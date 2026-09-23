<?php

namespace Modules\Bpl\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which roll a scanned BPL barcode means.
 *
 * From 2026-09-09 a barcode names its own stream — softrolls print `S{machine}`
 * and hardrolls `M{machine}` (see RollBarcode). Before that both streams minted
 * `M` from independent counters and collided: 1,699 of 1,708 softroll barcodes
 * are byte-identical to a hardroll's. So `RollBarcode::stream()` alone cannot
 * route the rolls made before the cut-over — every one of them reads as a
 * hardroll.
 *
 * It does not have to. A movement screen only ever acts on rolls at a
 * particular point in the pipeline, and among those the collision is almost
 * always already resolved. Measured 2026-09-16:
 *
 *   - **On the floor** (Factory Exit): 215 hardrolls, 60 softrolls, and all 59
 *     of the M-barcode softrolls have a hardroll twin that left long ago.
 *     Contested: **zero**.
 *   - **Awaiting a store** (Store Entrance): 406 hardrolls, 113 softrolls.
 *     Contested: **zero**.
 *
 * Hence, for either predicate:
 *
 *   1. an `S` barcode is a softroll, full stop — it was minted after the
 *      cut-over and cannot collide;
 *   2. otherwise, take the rows for that barcode at that point in the pipeline.
 *      One candidate is the answer; two means asking the operator.
 *
 * **The two-candidate case is rare, not impossible**, and it matters more the
 * further down the pipeline you go: 1,637 barcodes exist in BOTH factory-exit
 * tables. Today's zeroes are the data, not a guarantee. Callers must handle two.
 *
 * This is a CLOSING problem: no new `M` softrolls are minted, so the backlog
 * only shrinks. `softrollBacklog()` counts what is left; when it reaches zero,
 * step 2 can go and `stream()` alone is enough.
 */
class RollResolver
{
    public const HARDROLL = 'hardroll';
    public const SOFTROLL = 'softroll';

    /**
     * Rolls matching this barcode that are still on the factory floor — what
     * BPL Factory Exit may book out.
     *
     * @return array<int, array{stream:string,id:int,barcode:string,rollnumber:string,productname:string,gradetype:string,weight:float,product_id:?int,grade_id:?int}>
     */
    public static function onFloor(string $barcode): array
    {
        return self::candidates($barcode, [self::class, 'hardrollOnFloor'], [self::class, 'softrollOnFloor']);
    }

    /**
     * Rolls matching this barcode that have LEFT the factory and are not yet in
     * a store — what BPL Warehouse Entry may receive.
     *
     * The legacy screen's rule, kept: a barcode has to be in the matching
     * factory-exit table first ("Barcode not found in factory exit"), and must
     * not already be in the matching store-entrance table.
     *
     * @return array<int, array>
     */
    public static function awaitingWarehouse(string $barcode): array
    {
        return self::candidates($barcode, [self::class, 'hardrollAwaitingWarehouse'], [self::class, 'softrollAwaitingWarehouse']);
    }

    /**
     * Rolls matching this barcode that are IN a store — what BPL Warehouse Exit may
     * release.
     *
     * In the matching store-entrance table and not in the matching store-exit
     * table. Each candidate carries the `location_id` of the store it is
     * actually in, because the releasing screen has to check that against the
     * store the operator picked: the legacy refused with "Barcode not found in
     * your store location", which does not say where it IS.
     *
     * Measured 2026-09-16: 2,629 hardrolls in store, 0 softrolls, contested
     * zero.
     *
     * @return array<int, array>
     */
    public static function inWarehouse(string $barcode): array
    {
        return self::candidates($barcode, [self::class, 'hardrollInWarehouse'], [self::class, 'softrollInWarehouse']);
    }

    /**
     * Why a barcode cannot be released from a store.
     *
     * @return array{reason:string,stream:?string,date:?string}
     *         reason: 'already_out' | 'not_received' | 'unknown'
     */
    public static function explainWarehouseExitMiss(string $barcode): array
    {
        $barcode = strtoupper(trim($barcode));

        foreach (self::STREAMS as $stream => [$production, $exitTable, $storeTable, $storeExitTable]) {
            $out = DB::connection('bpl')->table($storeExitTable)
                ->where('barcode', $barcode)->whereNull('deleted_at')->first(['date']);

            if ($out) {
                return ['reason' => 'already_out', 'stream' => $stream, 'date' => $out->date];
            }

            $known = DB::connection('bpl')->table($production)
                ->where('barcode', $barcode)->whereNull('deleted_at')->exists();

            if ($known) {
                return ['reason' => 'not_received', 'stream' => $stream, 'date' => null];
            }
        }

        return ['reason' => 'unknown', 'stream' => null, 'date' => null];
    }

    /**
     * Why a barcode produced no candidate at FACTORY EXIT — so the screen can
     * say which of the two it is instead of a flat "not found".
     *
     * @return array{reason:string,stream:?string,date:?string} reason: 'exited' | 'unknown'
     */
    public static function explainMiss(string $barcode): array
    {
        $barcode = strtoupper(trim($barcode));

        foreach (self::STREAMS as $stream => [$production, $exitTable, $storeTable, $storeExitTable]) {
            $row = DB::connection('bpl')->table($production)
                ->where('barcode', $barcode)
                ->whereNull('deleted_at')
                ->first(['status']);

            if ($row && $row->status !== null) {
                $date = DB::connection('bpl')->table($exitTable)
                    ->where('barcode', $barcode)->whereNull('deleted_at')->value('date');

                return ['reason' => 'exited', 'stream' => $stream, 'date' => $date];
            }
        }

        return ['reason' => 'unknown', 'stream' => null, 'date' => null];
    }

    /**
     * Why a barcode cannot be received into a store — three different problems
     * where the legacy screen reported two.
     *
     * @return array{reason:string,stream:?string,date:?string}
     *         reason: 'in_store' | 'not_exited' | 'unknown'
     */
    public static function explainWarehouseEntryMiss(string $barcode): array
    {
        $barcode = strtoupper(trim($barcode));

        foreach (self::STREAMS as $stream => [$production, $exitTable, $storeTable, $storeExitTable]) {
            $inStore = DB::connection('bpl')->table($storeTable)
                ->where('barcode', $barcode)->whereNull('deleted_at')->first(['date']);

            if ($inStore) {
                return ['reason' => 'in_store', 'stream' => $stream, 'date' => $inStore->date];
            }

            $known = DB::connection('bpl')->table($production)
                ->where('barcode', $barcode)->whereNull('deleted_at')->exists();

            if ($known) {
                $left = DB::connection('bpl')->table($exitTable)
                    ->where('barcode', $barcode)->whereNull('deleted_at')->exists();

                if (! $left) {
                    return ['reason' => 'not_exited', 'stream' => $stream, 'date' => null];
                }
            }
        }

        return ['reason' => 'unknown', 'stream' => null, 'date' => null];
    }

    /**
     * Softrolls still on the floor that carry a pre-cut-over `M` barcode — the
     * population that makes step 2 of the rule necessary. When this is zero the
     * fallback can be deleted.
     */
    public static function softrollBacklog(): int
    {
        return DB::connection('bpl')->table('bpl_softroll_production')
            ->whereNull('status')
            ->whereNull('deleted_at')
            // The machine segment is the 4th of five; anything not starting S
            // was minted before the cut-over.
            ->whereRaw("SUBSTRING_INDEX(`barcode`, '-', -2) NOT LIKE 'S%'")
            ->count();
    }

    /* ---------------- Internals ---------------- */

    /** stream => [production, factory-exit, store-entrance, store-exit] */
    private const STREAMS = [
        self::HARDROLL => ['bpl_production', 'bpl_factoryexit', 'bpl_warehouse_entry', 'bpl_warehouse_exit'],
        self::SOFTROLL => ['bpl_softroll_production', 'bpl_softroll_factoryexit', 'bpl_softroll_warehouse_entry', 'bpl_softroll_warehouse_exit'],
    ];

    private const HARDROLL_COLUMNS = ['r.id', 'r.barcode', 'r.hardrollnumber', 'r.weight', 'r.product_id',
        'p.productname', 'p.gradetype'];

    private const SOFTROLL_COLUMNS = ['r.id', 'r.barcode', 'r.softrollnumber', 'r.weight', 'r.grade_id',
        'r.grammage', 'r.diameter', 'p.productname', 'g.type as gradetype'];

    /**
     * The shared shape of both predicates: an S barcode is asked of the softroll
     * table only; anything else could be either, so both are asked.
     */
    private static function candidates(string $barcode, callable $hard, callable $soft): array
    {
        $barcode = strtoupper(trim($barcode));

        if ($barcode === '') {
            return [];
        }

        $out = [];

        if (RollBarcode::stream($barcode) !== self::SOFTROLL) {
            if ($row = $hard($barcode)) {
                $out[] = $row;
            }
        }

        if ($row = $soft($barcode)) {
            $out[] = $row;
        }

        return $out;
    }

    private static function hardrollOnFloor(string $barcode): ?array
    {
        $row = self::hardrollQuery($barcode)->whereNull('r.status')->first(self::HARDROLL_COLUMNS);

        return $row ? self::hardrollRow($row) : null;
    }

    private static function softrollOnFloor(string $barcode): ?array
    {
        $row = self::softrollQuery($barcode)->whereNull('r.status')->first(self::SOFTROLL_COLUMNS);

        return $row ? self::softrollRow($row) : null;
    }

    private static function hardrollAwaitingWarehouse(string $barcode): ?array
    {
        $row = self::hardrollQuery($barcode)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('bpl_factoryexit as e')
                ->whereColumn('e.barcode', 'r.barcode')->whereNull('e.deleted_at'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_warehouse_entry as se')
                ->whereColumn('se.barcode', 'r.barcode')->whereNull('se.deleted_at'))
            ->first(self::HARDROLL_COLUMNS);

        return $row ? self::hardrollRow($row) : null;
    }

    private static function softrollAwaitingWarehouse(string $barcode): ?array
    {
        $row = self::softrollQuery($barcode)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('bpl_softroll_factoryexit as e')
                ->whereColumn('e.barcode', 'r.barcode')->whereNull('e.deleted_at'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_softroll_warehouse_entry as se')
                ->whereColumn('se.barcode', 'r.barcode')->whereNull('se.deleted_at'))
            ->first(self::SOFTROLL_COLUMNS);

        return $row ? self::softrollRow($row) : null;
    }

    private static function hardrollInWarehouse(string $barcode): ?array
    {
        $row = self::hardrollQuery($barcode)
            ->join('bpl_warehouse_entry as se', function ($j) {
                $j->on('se.barcode', '=', 'r.barcode')->whereNull('se.deleted_at');
            })
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_warehouse_exit as sx')
                ->whereColumn('sx.barcode', 'r.barcode')->whereNull('sx.deleted_at'))
            ->first(array_merge(self::HARDROLL_COLUMNS, ['se.location_id']));

        if (! $row) {
            return null;
        }

        // Assigned, not array-unioned: `+` keeps the LEFT operand's value for a
        // duplicate key, so the null default in hardrollRow() would win.
        $candidate = self::hardrollRow($row);
        $candidate['location_id'] = (int) $row->location_id;

        return $candidate;
    }

    private static function softrollInWarehouse(string $barcode): ?array
    {
        $row = self::softrollQuery($barcode)
            ->join('bpl_softroll_warehouse_entry as se', function ($j) {
                $j->on('se.barcode', '=', 'r.barcode')->whereNull('se.deleted_at');
            })
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_softroll_warehouse_exit as sx')
                ->whereColumn('sx.barcode', 'r.barcode')->whereNull('sx.deleted_at'))
            ->first(array_merge(self::SOFTROLL_COLUMNS, ['se.location_id']));

        if (! $row) {
            return null;
        }

        $candidate = self::softrollRow($row);
        $candidate['location_id'] = (int) $row->location_id;

        return $candidate;
    }

    private static function hardrollQuery(string $barcode)
    {
        return DB::connection('bpl')->table('bpl_production as r')
            ->leftJoin('bpl_products_hardroll as p', 'r.product_id', '=', 'p.id')
            ->where('r.barcode', $barcode)
            ->whereNull('r.deleted_at');
    }

    private static function softrollQuery(string $barcode)
    {
        return DB::connection('bpl')->table('bpl_softroll_production as r')
            ->leftJoin('bpl_products_softroll as p', 'r.product_id', '=', 'p.id')
            ->leftJoin('bpl_grades as g', 'r.grade_id', '=', 'g.id')
            ->where('r.barcode', $barcode)
            ->whereNull('r.deleted_at');
    }

    private static function hardrollRow($row): array
    {
        return [
            'stream' => self::HARDROLL,
            'id' => (int) $row->id,
            'barcode' => $row->barcode,
            'rollnumber' => (string) $row->hardrollnumber,
            'productname' => (string) ($row->productname ?? '—'),
            'gradetype' => (string) ($row->gradetype ?? ''),
            'weight' => (float) $row->weight,
            // bpl_stock is keyed by product; bpl_softroll_stock by grade.
            'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
            'grade_id' => null,
            // Only the in-store predicate knows this; the others leave it null.
            'location_id' => null,
        ];
    }

    private static function softrollRow($row): array
    {
        return [
            'stream' => self::SOFTROLL,
            'id' => (int) $row->id,
            'barcode' => $row->barcode,
            'rollnumber' => (string) $row->softrollnumber,
            // Rolls made before the product split have no product row; their
            // own grade and dimensions still describe them.
            'productname' => (string) ($row->productname ?: trim($row->gradetype . ' ' . $row->grammage . 'gsm ' . $row->diameter . 'd')),
            'gradetype' => (string) ($row->gradetype ?? ''),
            'weight' => (float) $row->weight,
            'product_id' => null,
            'grade_id' => $row->grade_id !== null ? (int) $row->grade_id : null,
            'location_id' => null,
        ];
    }
}
