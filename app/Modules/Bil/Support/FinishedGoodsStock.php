<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;

/**
 * Finished-goods stock: bundles of a product held in a warehouse.
 *
 * Replaces the legacy pair — `storebundle` (one hard-coded `warehousecode`
 * '01') and `storebundle_floor` (three hard-coded floors, chosen by a PHP string
 * comparison against one gate name) — with `finished_goods_warehouse_stock`,
 * one row per warehouse per product.
 *
 * The important structural gain: **stock is now derivable.** Every movement has
 * a receipt behind it, so the totals are exactly
 *
 *     SUM(bundles) of finished_goods_warehouse_receipts, per warehouse, product
 *
 * The old totals were not — nothing recorded which floor a bundle had been
 * counted onto, so drift was permanent and undetectable. Now `bil:reconcile-fg-stock`
 * can prove or repair them at any time.
 *
 * The aggregate is still maintained incrementally rather than computed on read,
 * because it is queried per product on screens that would otherwise scan the
 * receipts table; the reconcile command is the safety net, not the primary path.
 *
 * Bundles are signed — positive receives, negative reverses. Totals are NOT
 * clamped at zero: a genuine correction can take a product negative, and hiding
 * that would mask a real problem rather than fix it.
 *
 * gds no longer writes `storebundle` or `storebundle_floor` at all. The legacy
 * app still owns them, and from the 2026-08-12 cut-over the two diverge for
 * anything received through gds — see docs/DEPLOYMENT.md.
 */
class FinishedGoodsStock
{
    /**
     * Move stock for one product in one warehouse.
     *
     * Call inside the caller's transaction — the receipt and the total must
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
        DB::connection('core')->insert(
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
     * What the totals SHOULD be, straight from the receipts.
     *
     * Returns ['{warehouse_id}:{productid}' => bundles]. Used by the reconcile
     * command and by the tests that prove receiving and un-receiving balance.
     */
    public static function expected(?int $warehouseId = null): array
    {
        return DB::connection('core')->table('finished_goods_warehouse_receipts')
            ->when($warehouseId, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->groupBy('warehouse_id', 'productid')
            ->selectRaw('warehouse_id, productid, SUM(bundles) as bundles')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->warehouse_id . ':' . $r->productid => (int) $r->bundles])
            ->all();
    }

    /** What the totals currently say, in the same shape as expected(). */
    public static function actual(?int $warehouseId = null): array
    {
        return DB::connection('core')->table('finished_goods_warehouse_stock')
            ->when($warehouseId, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->get(['warehouse_id', 'productid', 'bundles'])
            ->mapWithKeys(fn ($r) => [$r->warehouse_id . ':' . $r->productid => (int) $r->bundles])
            ->all();
    }

    /**
     * Rows where the stored total disagrees with the receipts.
     *
     * Returns [['warehouse_id','productid','stored','expected','difference'], …].
     */
    public static function drift(?int $warehouseId = null): array
    {
        $expected = self::expected($warehouseId);
        $actual = self::actual($warehouseId);

        $out = [];
        foreach ($expected + $actual as $key => $_) {
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
