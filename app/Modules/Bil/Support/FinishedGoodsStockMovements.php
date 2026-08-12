<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;

/**
 * Everything that moved a product in and out of a warehouse, read live.
 *
 * WHY THIS IS NOT STORED
 * Each of these movements is already a row somewhere — a receipt, an
 * adjustment, a sales order, a loading, a delivery, a return. Copying them onto
 * the stock row as JSON would duplicate data that already has an owner, go
 * stale the moment the source changed, and break the one property worth having:
 * that stock can be PROVED from its movements. It would also be unsortable,
 * unfilterable and unbounded — a busy product has tens of thousands of rows.
 *
 * So the stock row stays a cached total, and this reads the movements on demand
 * when someone opens the detail modal. Same sources `FinishedGoodsStock::expected()`
 * reconciles against, so the modal and the total can never disagree.
 *
 * HOW THE SALES CHAIN LINKS TO A PRODUCT
 * Only `sales_order_details` knows the product; everything else points at it:
 *
 *     sales_order_details.id = sod_id
 *        ├─ sales_loading.sod_id           goods leaving the warehouse
 *        ├─ sales_loading_return.sod_id    unloaded back in
 *        └─ sales_return.sod_id            returned by the customer
 *
 *     sales_delivery  -> by loadnumber      -> sales_loading -> sod_id
 *     sales_waybill   -> by deliverynumber  -> sales_delivery -> loadnumber
 *
 * The last two are two joins from the product, which is why they are queried
 * through the load numbers rather than joined in one statement.
 *
 * Everything here is capped: a modal is for reading, not for exporting a
 * decade of history.
 */
class FinishedGoodsStockMovements
{
    /** Rows per section. A modal that returns 40k rows helps nobody. */
    public const LIMIT = 200;

    /* ---------------- Incoming ---------------- */

    /**
     * Goods arriving: warehouse receipts, manual corrections, and anything the
     * sales side gave back (customer returns, and loads unloaded again).
     */
    public static function incoming(int $warehouseId, int $productid): array
    {
        return [
            'receipts' => self::receipts($warehouseId, $productid),
            'adjustments' => self::adjustments($warehouseId, $productid),
            'sales_returns' => self::salesReturns($productid),
            'loading_returns' => self::loadingReturns($productid),
        ];
    }

    /** Pallets received through a gate — the barcode, its bundles and the date. */
    public static function receipts(int $warehouseId, int $productid): array
    {
        return DB::connection('core')->table('finished_goods_warehouse_receipts as r')
            ->leftJoin('warehouse_gates as g', 'r.entrance_id', '=', 'g.id')
            ->where('r.warehouse_id', $warehouseId)
            ->where('r.productid', $productid)
            ->orderByDesc('r.date_of_entrance')->orderByDesc('r.id')
            ->limit(self::LIMIT)
            ->get(['r.barcode', 'r.bundles', 'r.date_of_entrance', 'r.username',
                'r.is_historic', 'g.name as gate'])
            ->all();
    }

    /** Manual corrections, with who made them and why. */
    public static function adjustments(int $warehouseId, int $productid): array
    {
        return DB::connection('core')->table('finished_goods_stock_adjustments')
            ->where('warehouse_id', $warehouseId)
            ->where('productid', $productid)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['bundles', 'reason', 'username', 'created_at'])
            ->all();
    }

    /**
     * Customer returns — these come back into stock.
     *
     * `sod_id` is an int on both sides so that join is fine; only the order
     * header is looked up separately, for the collation reason above.
     */
    public static function salesReturns(int $productid): array
    {
        $rows = DB::connection('bil')->table('sales_return as sr')
            ->join('sales_order_details as d', 'sr.sod_id', '=', 'd.id')
            ->where('d.productid', $productid)
            ->orderByDesc('sr.id')
            ->limit(self::LIMIT)
            ->get(['sr.returnnumber', 'sr.quantityreturned', 'sr.quantityrejected',
                'sr.dateofreturn', 'sr.username', 'd.orderid']);

        $headers = self::orderHeaders($rows->pluck('orderid')->all());

        return $rows->map(function ($r) use ($headers) {
            $r->customerid = $headers[$r->orderid]->customerid ?? null;

            return $r;
        })->all();
    }

    /** Loads unloaded again before they left — also back into stock. */
    public static function loadingReturns(int $productid): array
    {
        return DB::connection('bil')->table('sales_loading_return as lr')
            ->join('sales_order_details as d', 'lr.sod_id', '=', 'd.id')
            ->where('d.productid', $productid)
            ->orderByDesc('lr.id')
            ->limit(self::LIMIT)
            ->get(['lr.barcode', 'lr.quantityunloaded', 'lr.username', 'lr.timestamp', 'd.orderid'])
            ->all();
    }

    /* ---------------- Outgoing ---------------- */

    /**
     * The sales chain for this product, stage by stage:
     * ordered → loaded (leaves the warehouse) → delivered → waybilled.
     */
    public static function outgoing(int $productid): array
    {
        $loadings = self::loadings($productid);
        $loadNumbers = array_values(array_unique(array_filter(array_column($loadings, 'loadnumber'))));

        $deliveries = self::deliveries($loadNumbers);
        $deliveryNumbers = array_values(array_unique(array_filter(array_column($deliveries, 'deliverynumber'))));

        return [
            'orders' => self::orders($productid),
            'loadings' => $loadings,
            'deliveries' => $deliveries,
            'waybills' => self::waybills($deliveryNumbers),
        ];
    }

    /**
     * What was ordered — the start of the chain, not yet a stock movement.
     *
     * The order header is fetched separately rather than joined. `sales_order_details.orderid`
     * is utf8mb3 and `sales_order.orderid` is latin1, and MySQL cannot use an
     * index across that collation mismatch: joining them hash-scans all 97k
     * orders for every lookup. Taking the newest details by their own primary
     * key and then reading only those order headers turns a 1.6s query into two
     * indexed ones. (The same trap is noted on the Warehouse Exit report.)
     */
    public static function orders(int $productid): array
    {
        $details = DB::connection('bil')->table('sales_order_details')
            ->where('productid', $productid)
            // id is chronological, so this is newest-first without touching the
            // order header — and it is the primary key.
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'orderid', 'quantityordered', 'foc']);

        $headers = self::orderHeaders($details->pluck('orderid')->all());

        return $details->map(function ($d) use ($headers) {
            $o = $headers[$d->orderid] ?? null;
            $d->dateoforder = $o->dateoforder ?? null;
            $d->customerid = $o->customerid ?? null;
            $d->username = $o->username ?? null;
            $d->warehousecode = $o->warehousecode ?? null;

            return $d;
        })->all();
    }

    /** Order headers by id. A constant list compares fine across collations. */
    protected static function orderHeaders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::connection('bil')->table('sales_order')
            ->whereIn('orderid', array_unique($orderIds))
            ->get(['orderid', 'dateoforder', 'customerid', 'username', 'warehousecode'])
            ->keyBy('orderid')
            ->all();
    }

    /** Goods actually leaving the warehouse — this is what reduces stock. */
    public static function loadings(int $productid): array
    {
        return DB::connection('bil')->table('sales_loading as l')
            ->join('sales_order_details as d', 'l.sod_id', '=', 'd.id')
            ->where('d.productid', $productid)
            ->orderByDesc('l.id')
            ->limit(self::LIMIT)
            ->get(['l.loadnumber', 'l.barcode', 'l.quantityloaded', 'l.cageroomcode',
                'l.dateofloading', 'l.username', 'l.status', 'd.orderid'])
            ->all();
    }

    /** Deliveries, reached through the load numbers this product went out on. */
    public static function deliveries(array $loadNumbers): array
    {
        if ($loadNumbers === []) {
            return [];
        }

        return DB::connection('bil')->table('sales_delivery')
            ->whereIn('loadnumber', $loadNumbers)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['deliverynumber', 'barcode', 'loadnumber', 'dateofdelivery',
                'username', 'deliverycustomerid'])
            ->all();
    }

    /** Waybills, reached through those deliveries — the end of the chain. */
    public static function waybills(array $deliveryNumbers): array
    {
        if ($deliveryNumbers === []) {
            return [];
        }

        return DB::connection('bil')->table('sales_waybill')
            ->whereIn('deliverynumber', $deliveryNumbers)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['barcode', 'deliverynumber', 'receiptnumber', 'dateofwaybill', 'username'])
            ->all();
    }
}
