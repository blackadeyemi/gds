<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Warehouse;
use Modules\Core\Models\WarehouseGate;

/**
 * Raw-material stock: units and weight of a product held in a warehouse.
 *
 * Replaces `rawmaterials_stock`, which was keyed by a location NAME ('Ogba')
 * rather than a warehouse, and which the code only ever looked up by product —
 * so a second store could never have had its own line.
 *
 * Like finished goods, the new table is **derivable**: an item is in stock
 * exactly while its `rawmaterials_warehouse_entry` row has `status IS NULL`, so
 * the totals are a straight rollup of the barcodes. That is what makes
 * `bil:reconcile-rm-stock` able to prove or repair them — the old aggregate had
 * drifted to roughly 8.5x reality precisely because nothing could check it.
 *
 * gds no longer writes `rawmaterials_stock`. The legacy app still owns it, and
 * the two diverge from the 2026-08-12 cut-over.
 *
 * The raw-materials movement tables were MyISAM until 2026_10_07_100000, so a
 * `DB::transaction()` around them committed statement by statement. They are
 * InnoDB now: each RM screen holds its `GET_LOCK` (which keeps two stations
 * from reading the same sequence or barcode state) AND a `bil` transaction
 * (which keeps a failed batch from leaving half its rows). Rows written before
 * the conversion can still be half a batch — `bil:reconcile-rm-stock` puts the
 * totals right from the barcodes either way.
 *
 * ⚠️ A test still cannot rely on rolling back: the feature tests run against
 * the live databases and the screens commit their own transaction. Clean up
 * explicitly, by id.
 */
class RawMaterialsStock
{
    /**
     * Move stock for one product in one warehouse.
     *
     * `$units` and `$weight` are signed — positive receives, negative issues.
     * Call inside the caller's transaction or lock, so the movement and the
     * total commit together.
     */
    public static function apply(int $warehouseId, int $productid, int $units, float $weight): void
    {
        if ($warehouseId <= 0 || $productid <= 0 || ($units === 0 && $weight == 0.0)) {
            return;
        }

        // Race-safe: the unique key on (warehouse_id, productid) turns a
        // concurrent double-insert into an increment, and `x + ?` is atomic.
        DB::connection('bil')->insert(
            'INSERT INTO `raw_materials_warehouse_stock`
                 (`warehouse_id`, `productid`, `quantity`, `weight`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 `quantity` = `quantity` + ?,
                 `weight` = `weight` + ?,
                 `updated_at` = ?',
            [$warehouseId, $productid, $units, $weight, now(), now(), $units, $weight, now()]
        );
    }

    /**
     * The warehouse a raw-materials gate belongs to, or null.
     *
     * Every RM movement is booked against a gate; the warehouse behind it is
     * what actually holds the stock.
     */
    public static function warehouseForGate(?int $gateId): ?Warehouse
    {
        if (! $gateId) {
            return null;
        }

        return WarehouseGate::with('warehouse')->find($gateId)?->warehouse;
    }

    /**
     * The legacy `rawmaterial_store_location` id for a warehouse.
     *
     * The RM movement tables still carry `location_id`, which the legacy app
     * reads, so gds keeps writing it alongside the new `gate_id`.
     */
    public static function legacyLocationId(?Warehouse $warehouse): ?int
    {
        return $warehouse?->legacy_location_id;
    }

    /* ---------------- Reconciliation ---------------- */

    /**
     * What the totals SHOULD be, from the in-store barcodes.
     *
     * An item is in store exactly while its warehouse-entry row has no status.
     * Returns ['{warehouse_id}:{productid}' => ['quantity','weight']].
     */
    public static function expected(?int $warehouseId = null): array
    {
        // location_id on the legacy rows is the STORE, so it maps to a
        // warehouse through `legacy_location_id`.
        $byLegacy = Warehouse::whereNotNull('legacy_location_id')
            ->pluck('id', 'legacy_location_id')->all();

        $rows = DB::connection('bil')->table('rawmaterials_warehouse_entry')
            ->whereNull('status')
            ->groupBy('location_id', 'productid')
            ->selectRaw('location_id, productid, COUNT(*) as quantity, SUM(weight) as weight')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $id = $byLegacy[$row->location_id] ?? null;
            if (! $id || ($warehouseId && $id !== $warehouseId)) {
                continue;
            }
            $out[$id . ':' . $row->productid] = [
                'quantity' => (int) $row->quantity,
                'weight' => round((float) $row->weight, 4),
            ];
        }

        return $out;
    }

    /** What the totals currently say, in the same shape as expected(). */
    public static function actual(?int $warehouseId = null): array
    {
        return DB::connection('bil')->table('raw_materials_warehouse_stock')
            ->when($warehouseId, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->get(['warehouse_id', 'productid', 'quantity', 'weight'])
            ->mapWithKeys(fn ($r) => [$r->warehouse_id . ':' . $r->productid => [
                'quantity' => (int) $r->quantity,
                'weight' => round((float) $r->weight, 4),
            ]])->all();
    }

    /**
     * Lines where the stored total disagrees with the barcodes.
     *
     * Weight is compared to 2dp: it is a float summed in a different order on
     * each side, so an exact comparison would report noise as drift.
     */
    public static function drift(?int $warehouseId = null): array
    {
        $expected = self::expected($warehouseId);
        $actual = self::actual($warehouseId);

        $out = [];
        foreach (array_keys($expected + $actual) as $key) {
            $want = $expected[$key] ?? ['quantity' => 0, 'weight' => 0.0];
            $have = $actual[$key] ?? ['quantity' => 0, 'weight' => 0.0];

            if ($want['quantity'] === $have['quantity']
                && round($want['weight'], 2) === round($have['weight'], 2)) {
                continue;
            }

            [$warehouse, $product] = array_map('intval', explode(':', $key));
            $out[] = [
                'warehouse_id' => $warehouse,
                'productid' => $product,
                'stored_quantity' => $have['quantity'],
                'expected_quantity' => $want['quantity'],
                'stored_weight' => $have['weight'],
                'expected_weight' => $want['weight'],
            ];
        }

        return $out;
    }
}
