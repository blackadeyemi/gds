<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;

/**
 * Finished-goods stock: bundles of a product held in a warehouse.
 *
 * Replaces the legacy pair — `storebundle` (one hard-coded `warehousecode` '01')
 * and `storebundle_floor` (three hard-coded floors, chosen by a PHP string
 * comparison against one gate name) — with `finished_goods_warehouse_stock`,
 * one row per warehouse per product.
 *
 * THE PROPERTY WORTH PROTECTING: stock is **derivable**. It is not a number
 * someone typed; it is the sum of three things that each leave a record:
 *
 *     bundles = SUM(receipts WHERE NOT is_historic)     goods received in gds
 *             + SUM(adjustments)                        manual corrections
 *             - SUM(loadings since the cut-over)        goods dispatched
 *
 * so `bil:reconcile-fg-stock` can always prove or repair it. The old totals
 * could not be — nothing recorded which floor a bundle had been counted onto,
 * so drift was permanent and undetectable.
 *
 * The aggregate is still maintained incrementally rather than computed on read,
 * because it is queried per product on screens that would otherwise scan the
 * receipts; the reconcile command is the safety net, not the primary path.
 *
 * **Imported history does not count.** The 1.17M receipts imported from the
 * legacy `store_entrance` carry `is_historic = true`: they make the reports
 * complete, but treating nine years of arrivals as stock on hand would be
 * nonsense. Stock therefore starts from the cut-over, which is what the manual
 * adjustments are for — set the opening balance once, then let movements run.
 *
 * **Goods leaving are read, not mirrored.** gds has no dispatch screen yet, so
 * outbound comes from the legacy `sales_loading` (joined to
 * `sales_order_details` for the product, since loading rows carry only a sales
 * order detail id). Deriving avoids a second copy that could drift from the
 * table it copies.
 *
 * ⚠️ **Loadings cannot be split per warehouse.** `sales_loading` identifies a
 * cage room, and every cage room maps to warehouse code '01' — the legacy model
 * had one finished-goods warehouse. With more than one FG warehouse configured,
 * loadings are attributed to the FIRST by sort order and the reconcile output
 * will look wrong for the others. Fix that by mapping cage rooms to warehouses
 * before running a second FG warehouse.
 *
 * gds no longer writes `storebundle` or `storebundle_floor`. The legacy app
 * still owns them, and the two diverge from the cut-over.
 */
class FinishedGoodsStock
{
    /**
     * Movements before this date belong to the legacy app and are excluded.
     *
     * Receipts are excluded by their `is_historic` flag; loadings have no such
     * flag, so they are excluded by date. Both must use the same boundary or
     * stock would be charged for dispatches of goods it never counted receiving.
     */
    public static function cutover(): string
    {
        return (string) config('warehouses.finished_goods_cutover', '2026-08-12');
    }

    /**
     * Move stock for one product in one warehouse.
     *
     * Call inside the caller's transaction — the movement and the total must
     * commit or roll back together.
     */
    public static function apply(int $warehouseId, int $productid, int $bundles): void
    {
        if ($warehouseId <= 0 || $productid <= 0 || $bundles === 0) {
            return;
        }

        // Race-safe: the unique key on (warehouse_id, productid) turns a
        // concurrent double-insert into an increment, and `bundles + ?` is
        // atomic, so no read-modify-write and no lock is needed.
        //
        // The product's name and code are NOT stored here. They were, back when
        // stock was on `core` and the master on `bil`; both are on `bil` now, so
        // the pages that sort and search by product join `products` instead of
        // keeping a copy that has to be refreshed on every movement.
        DB::connection('bil')->insert(
            'INSERT INTO `finished_goods_warehouse_stock`
                 (`warehouse_id`, `productid`, `bundles`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 `bundles` = `bundles` + ?,
                 `updated_at` = ?',
            [$warehouseId, $productid, $bundles, now(), now(), $bundles, now()]
        );
    }

    /**
     * Record a manual correction and move the total by it.
     *
     * `$bundles` is a signed delta rather than a target, so two people
     * correcting the same product at once add up instead of overwriting each
     * other. Use `setTo()` when the operator is typing an absolute figure.
     */
    public static function adjust(int $warehouseId, int $productid, int $bundles, ?string $reason = null): void
    {
        // Guard the SAME cases apply() refuses. Writing a ledger row that
        // apply() then silently skips makes the two disagree forever — which
        // is exactly what happened seeding the 44 legacy receipts that carry
        // productid 0.
        if ($bundles === 0 || $warehouseId <= 0 || $productid <= 0) {
            return;
        }

        $user = auth()->user();

        DB::connection('bil')->transaction(function () use ($warehouseId, $productid, $bundles, $reason, $user) {
            DB::connection('bil')->table('finished_goods_stock_adjustments')->insert([
                'warehouse_id' => $warehouseId,
                'productid' => $productid,
                'bundles' => $bundles,
                'reason' => $reason,
                'user_id' => $user?->userid,
                'username' => (string) ($user?->username ?? ''),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            self::apply($warehouseId, $productid, $bundles);
        });
    }

    /** Set a product's stock to an absolute figure, recorded as the delta. */
    public static function setTo(int $warehouseId, int $productid, int $target, ?string $reason = null): void
    {
        $current = (int) DB::connection('bil')->table('finished_goods_warehouse_stock')
            ->where('warehouse_id', $warehouseId)->where('productid', $productid)
            ->value('bundles');

        self::adjust($warehouseId, $productid, $target - $current, $reason);
    }

    /* ---------------- Reconciliation ---------------- */

    /**
     * What the totals SHOULD be, from the three things that move stock.
     *
     * Returns ['{warehouse_id}:{productid}' => bundles].
     */
    public static function expected(?int $warehouseId = null): array
    {
        $bil = DB::connection('bil');
        $out = [];

        $add = function (string $key, int $bundles) use (&$out) {
            $out[$key] = ($out[$key] ?? 0) + $bundles;
        };

        // 1. Live receipts.
        $bil->table('finished_goods_warehouse_receipts')
            ->where('is_historic', false)
            ->when($warehouseId, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->groupBy('warehouse_id', 'productid')
            ->selectRaw('warehouse_id, productid, SUM(bundles) as bundles')
            ->get()
            ->each(fn ($r) => $add($r->warehouse_id . ':' . $r->productid, (int) $r->bundles));

        // 2. Manual corrections.
        $bil->table('finished_goods_stock_adjustments')
            ->when($warehouseId, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->groupBy('warehouse_id', 'productid')
            ->selectRaw('warehouse_id, productid, SUM(bundles) as bundles')
            ->get()
            ->each(fn ($r) => $add($r->warehouse_id . ':' . $r->productid, (int) $r->bundles));

        // 3. Goods dispatched, read from the legacy loading screen.
        $loadWarehouse = self::loadingWarehouseId();
        if ($loadWarehouse && (! $warehouseId || $warehouseId === $loadWarehouse)) {
            foreach (self::loadedSinceCutover() as $productid => $bundles) {
                $add($loadWarehouse . ':' . $productid, -$bundles);
            }
        }

        return $out;
    }

    /**
     * Bundles dispatched per product since the cut-over.
     *
     * `sales_loading` carries a sales-order-detail id rather than a product, so
     * the product comes from `sales_order_details`. `dateofloading` is a legacy
     * `Y/m/d` varchar, which compares correctly as a string.
     *
     * NB: `sales_loading.status` is NOT a state flag — it holds a DATE (582,792
     * of 583,096 rows have one). Filtering on NULL here, as an earlier cut did,
     * excluded every completed load and meant stock never went down. Every
     * loading row took goods out.
     *
     * ⚠️ RETURNS ARE ALREADY OFF THIS FIGURE. `quantityloaded` is stored NET:
     * the legacy return script writes `SET quantityloaded = <gross> - <return>`
     * and gds's recordReturn() does the same, so a return lowers the loading row
     * itself. Subtracting `sales_loading_return` again — as this method did
     * until 2026-09-02 — took the same bundles off twice and overstated stock.
     *
     * The data settles it beyond the code: 13,022 returned lines have
     * `quantityloaded = 0` (impossible if it were gross — nothing was loaded to
     * return), and on 16,946 the return EXCEEDS `quantityloaded`.
     */
    public static function loadedSinceCutover(): array
    {
        return DB::connection('bil')->table('sales_loading as l')
            ->join('sales_order_details as d', 'l.sod_id', '=', 'd.id')
            ->where('l.dateofloading', '>=', str_replace('-', '/', self::cutover()))
            ->groupBy('d.productid')
            ->selectRaw('d.productid, SUM(l.quantityloaded) as bundles')
            ->pluck('bundles', 'productid')
            ->map(fn ($b) => (int) $b)
            ->reject(fn ($b) => $b === 0)
            ->all();
    }

    /** The FG warehouse loadings are attributed to — see the class note. */
    public static function loadingWarehouseId(): ?int
    {
        return DB::connection('core')->table('warehouses')
            ->where('module', 'finished-goods')->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('id')
            ->value('id');
    }

    /** What the totals currently say, in the same shape as expected(). */
    public static function actual(?int $warehouseId = null): array
    {
        return DB::connection('bil')->table('finished_goods_warehouse_stock')
            ->when($warehouseId, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->get(['warehouse_id', 'productid', 'bundles'])
            ->mapWithKeys(fn ($r) => [$r->warehouse_id . ':' . $r->productid => (int) $r->bundles])
            ->all();
    }

    /**
     * Rows where the stored total disagrees with what the movements say.
     *
     * Returns [['warehouse_id','productid','stored','expected','difference'], …].
     */
    public static function drift(?int $warehouseId = null): array
    {
        $expected = self::expected($warehouseId);
        $actual = self::actual($warehouseId);

        $out = [];
        foreach (array_keys($expected + $actual) as $key) {
            $want = $expected[$key] ?? 0;
            $have = $actual[$key] ?? 0;
            if ($want === $have) {
                continue;
            }
            [$warehouse, $product] = array_map('intval', explode(':', $key));
            $out[] = [
                'warehouse_id' => $warehouse,
                'productid' => $product,
                'stored' => $have,
                'expected' => $want,
                'difference' => $want - $have,
            ];
        }

        return $out;
    }
}
