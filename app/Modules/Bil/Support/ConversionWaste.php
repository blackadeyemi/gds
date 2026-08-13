<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Bil\Models\ConversionWasteRun;
use Modules\Core\Support\Settings;

/**
 * The rules behind conversion waste: what a run is, which runs still owe waste,
 * and which one is blocking the line.
 *
 * RUNS ARE DERIVED. A run exists because pallets were booked against a line for
 * a product in a shift — `factory_conversion` is the record of that, and this
 * class reads it. A row in `conversion_waste_runs` is created only when someone
 * enters waste or confirms, so "no row" means "nobody has looked at this yet",
 * which is precisely the state that should stop the next run starting.
 *
 * Deriving rather than declaring matters for the same reason it did for stock:
 * a run cannot be silently missed. If a pallet was made, the run is on the list,
 * whether or not anything else went right.
 *
 * ORDERING. Runs are ordered by the last pallet booked against them
 * (MAX(factory_conversion.id)), not by date and shift alone. That is what makes
 * a mid-shift product change work: two runs can share a line, date and shift and
 * still have an unambiguous order, because one line's pallets were booked before
 * the other's. No changeover log is consulted — production itself says which
 * came first.
 */
class ConversionWaste
{
    /**
     * Production before this date is history and never blocks. See config/waste.php
     * — without it, 1.2M unconfirmed historic runs would block every line forever.
     *
     * Editable in Settings → Waste; the stored override wins, and with none set
     * the .env value stands (Core\Support\Settings).
     */
    public static function cutover(): string
    {
        return (string) Settings::get('waste.confirmation_start', '2026-08-13');
    }

    /** The cut-over in the legacy `Y/m/d` form `factory_conversion` stores. */
    protected static function cutoverSlash(?string $since = null): string
    {
        return str_replace('-', '/', $since ?: self::cutover());
    }

    /* ---------------- Current production date + shift ---------------- */

    /**
     * The production date and shift right now.
     *
     * A production day runs 07:00 → 06:59, so between midnight and 07:00 the
     * work belongs to YESTERDAY's night shift. This mirrors the legacy
     * functions/production_date.php exactly, because Conversion Output still
     * stamps `dateofproduction` that way and the two must agree about which run
     * a pallet belongs to.
     */
    public static function currentRun(): array
    {
        $now = now();
        $hour = (int) $now->format('G');

        if ($hour < 7) {
            return ['date' => $now->copy()->subDay()->format('Y-m-d'), 'shift' => 'night'];
        }

        return [
            'date' => $now->format('Y-m-d'),
            'shift' => $hour < 19 ? 'day' : 'night',
        ];
    }

    /* ---------------- Runs, from production ---------------- */

    /**
     * Every run on a line since the cut-over, newest first.
     *
     * Returns plain arrays keyed by the run's identity, each carrying the
     * position (`last_id`) used to order them and the production totals that
     * make the run recognisable on screen.
     */
    public static function runsForLine(int $lineId, int $limit = 200): array
    {
        return self::runQuery()->where('c.line_id', $lineId)
            ->orderByDesc('last_id')->limit($limit)->get()
            ->map(fn ($r) => self::hydrate($r))->all();
    }

    /**
     * Every run since the cut-over across all lines, newest first.
     * `$lineIds` limits it to a set of lines (the run picker's filter).
     */
    public static function recentRuns(?array $lineIds = null, int $limit = 500, ?string $since = null): array
    {
        $q = self::runQuery($since);

        if ($lineIds !== null) {
            if ($lineIds === []) {
                return [];
            }
            $q->whereIn('c.line_id', $lineIds);
        }

        return $q->orderByDesc('last_id')->limit($limit)->get()
            ->map(fn ($r) => self::hydrate($r))->all();
    }

    /**
     * The shared shape of a run, straight off production.
     *
     * Grouped on the four columns that identify a run. `line_id` is indexed and
     * the cut-over keeps the date range short, so this stays cheap however long
     * factory_conversion grows.
     */
    protected static function runQuery(?string $since = null)
    {
        return DB::connection('bil')->table('factory_conversion as c')
            ->where('c.dateofproduction', '>=', self::cutoverSlash($since))
            ->whereNotNull('c.line_id')
            ->whereNotNull('c.productid')
            ->where('c.productid', '>', 0)
            ->groupBy('c.line_id', 'c.dateofproduction', 'c.shift', 'c.productid')
            ->selectRaw('c.line_id, c.dateofproduction, c.shift, c.productid,
                         MAX(c.id) as last_id, MIN(c.id) as first_id,
                         COUNT(*) as pallets, SUM(c.bundles) as bundles,
                         MAX(c.factory_id) as factory_id,
                         MAX(c.linename) as linename, MAX(c.sublinename) as sublinename');
    }

    /** One grouped production row as a run array. */
    protected static function hydrate($r): array
    {
        return [
            'line_id' => (int) $r->line_id,
            'factory_id' => $r->factory_id ? (int) $r->factory_id : null,
            'productid' => (int) $r->productid,
            // factory_conversion stores Y/m/d; everything gds-side is ISO.
            'date' => str_replace('/', '-', (string) $r->dateofproduction),
            'shift' => strtolower(trim((string) $r->shift)) ?: 'day',
            'last_id' => (int) $r->last_id,
            'first_id' => (int) $r->first_id,
            'pallets' => (int) $r->pallets,
            'bundles' => (int) $r->bundles,
            'line_name' => $r->sublinename ?: $r->linename,
        ];
    }

    /** Stable identity of a run, for keying arrays and matching rows. */
    public static function key(int $lineId, string $date, string $shift, int $productid): string
    {
        return $lineId . '|' . str_replace('/', '-', $date) . '|' . strtolower($shift) . '|' . $productid;
    }

    public static function keyOf(array $run): string
    {
        return self::key($run['line_id'], $run['date'], $run['shift'], $run['productid']);
    }

    /* ---------------- Confirmation state ---------------- */

    /**
     * The stored rows for a set of runs, keyed by run key.
     *
     * One query for the whole page rather than one per run — the two tables are
     * on different connections, so this cannot be a join.
     */
    public static function storedFor(array $runs): array
    {
        if ($runs === []) {
            return [];
        }

        $q = ConversionWasteRun::query()->where(function ($w) use ($runs) {
            foreach ($runs as $r) {
                $w->orWhere(fn ($x) => $x
                    ->where('line_id', $r['line_id'])
                    ->where('production_date', $r['date'])
                    ->where('shift', $r['shift'])
                    ->where('productid', $r['productid']));
            }
        });

        return $q->get()->keyBy(fn ($m) => self::key(
            $m->line_id, $m->production_date->format('Y-m-d'), $m->shift, $m->productid
        ))->all();
    }

    /**
     * Runs that still owe waste, oldest first.
     *
     * The order is the point: the oldest open run is the one holding the line
     * up, so it belongs at the top of the entry screen and in the block message.
     */
    public static function openRuns(?array $lineIds = null, ?int $limit = null, ?string $since = null): array
    {
        $limit ??= (int) config('waste.open_run_limit', 100);

        $runs = self::recentRuns($lineIds, 500, $since);
        $stored = self::storedFor($runs);

        $open = array_values(array_filter(
            $runs,
            fn ($r) => ! isset($stored[self::keyOf($r)]) || ! $stored[self::keyOf($r)]->isConfirmed()
        ));

        // recentRuns() came back newest-first; the backlog reads oldest-first.
        usort($open, fn ($a, $b) => $a['last_id'] <=> $b['last_id']);

        return array_slice($open, 0, $limit);
    }

    /* ---------------- The block ---------------- */

    /**
     * The run standing in the way of working on `$target` on this line, or null.
     *
     * "In the way" means: an earlier run on the SAME LINE, since the cut-over,
     * whose waste has not been confirmed. Earlier is decided by `last_id` — the
     * last pallet booked — so a product changeover inside one shift orders
     * correctly without consulting a changeover log.
     *
     * The target run itself never blocks: continuing to book the run you are
     * already on is not starting a new one.
     */
    public static function blockingRun(int $lineId, string $date, string $shift, int $productid): ?array
    {
        $date = str_replace('/', '-', $date);
        $shift = strtolower($shift);

        $runs = self::runsForLine($lineId);
        if ($runs === []) {
            return null;
        }

        $targetKey = self::key($lineId, $date, $shift, $productid);

        // Where the target sits in the line's history. A run with no pallets yet
        // — the case when Conversion Output is about to book its first — is
        // newer than everything already recorded.
        $targetLastId = PHP_INT_MAX;
        foreach ($runs as $r) {
            if (self::keyOf($r) === $targetKey) {
                $targetLastId = $r['last_id'];
                break;
            }
        }

        $earlier = array_values(array_filter(
            $runs,
            fn ($r) => $r['last_id'] < $targetLastId && self::keyOf($r) !== $targetKey
        ));

        if ($earlier === []) {
            return null;
        }

        $stored = self::storedFor($earlier);

        // Oldest first: report the run at the front of the queue, not the one
        // that happens to be nearest.
        usort($earlier, fn ($a, $b) => $a['last_id'] <=> $b['last_id']);

        foreach ($earlier as $r) {
            $row = $stored[self::keyOf($r)] ?? null;
            if (! $row || ! $row->isConfirmed()) {
                return $r;
            }
        }

        return null;
    }

    /* ---------------- Persistence ---------------- */

    /**
     * The stored row for a run, created if this is the first time anyone has
     * touched it. Product and line names are copied in because they live on the
     * other connection and a report cannot join back to them.
     */
    public static function findOrCreateRun(array $run): ConversionWasteRun
    {
        return ConversionWasteRun::firstOrCreate(
            [
                'line_id' => $run['line_id'],
                'production_date' => $run['date'],
                'shift' => $run['shift'],
                'productid' => $run['productid'],
            ],
            [
                'factory_id' => $run['factory_id'] ?? null,
                'line_name' => $run['line_name'] ?? null,
                'product_name' => self::productName($run['productid']),
                'opened_by' => auth()->id(),
            ]
        );
    }

    /** bil.products is on the other connection, so names are resolved here. */
    public static function productName(int $productid): ?string
    {
        static $cache = [];

        return $cache[$productid] ??= DB::connection('bil')->table('products')
            ->where('productid', $productid)->value('productname');
    }

    /**
     * Close a run.
     *
     * `is_nil` records that a run was closed with nothing to report, which is a
     * different fact from a run nobody has opened — both have no entries, only
     * one of them has been accounted for.
     */
    public static function confirm(ConversionWasteRun $run, bool $isNil = false, ?string $note = null): void
    {
        $run->forceFill([
            'confirmed_at' => now(),
            'confirmed_by' => auth()->id(),
            // `username` is the User model's display field — `name` is null.
            'confirmed_by_name' => auth()->user()?->username ?? auth()->user()?->name,
            'is_nil' => $isNil,
            'note' => $note ?: $run->note,
        ])->save();
    }

    /** Re-open a confirmed run so its waste can be corrected. */
    public static function reopen(ConversionWasteRun $run): void
    {
        $run->forceFill([
            'confirmed_at' => null,
            'confirmed_by' => null,
            'confirmed_by_name' => null,
            'is_nil' => false,
        ])->save();
    }
}
