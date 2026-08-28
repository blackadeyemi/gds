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
 * Everything is bounded twice: by a DATE WINDOW the reader chooses (30/60/90
 * days) and by a row cap as a backstop. Nine years of movements is not something
 * to read in a modal — the reports are for that.
 *
 * Each source dates differently, which is why the window is applied per method
 * rather than once: `date_of_entrance` is a real DATE, `created_at` a timestamp,
 * the sales dates are legacy `Y/m/d` varchars (which compare correctly as
 * strings), and `sales_loading_return` has only a unix `timestamp`.
 */
class FinishedGoodsStockMovements
{
    /**
     * Rows per section, once the window has been applied.
     *
     * Sized for reading, not for analysis: even at 30 days a busy product can
     * have several hundred loadings, and four sections of 200 is a wall. The
     * modal says when it has capped so the reports are the obvious next step.
     */
    public const LIMIT = 50;

    /** Windows offered in the modal, in days. */
    public const WINDOWS = [30, 60, 90];

    public const DEFAULT_WINDOW = 30;

    /** Legacy `Y/m/d` cut-off for a window. */
    protected static function since(int $days): string
    {
        return now()->subDays($days)->format('Y/m/d');
    }

    /** ISO cut-off, for the columns that are real dates. */
    protected static function sinceIso(int $days): string
    {
        return now()->subDays($days)->format('Y-m-d');
    }

    /* ---------------- Incoming ---------------- */

    /**
     * Goods arriving: warehouse receipts, manual corrections, and anything the
     * sales side gave back (customer returns, and loads unloaded again).
     */
    public static function incoming(int $warehouseId, int $productid, int $days = self::DEFAULT_WINDOW): array
    {
        return [
            'receipts' => self::receipts($warehouseId, $productid, $days),
            'adjustments' => self::adjustments($warehouseId, $productid, $days),
            'sales_returns' => self::salesReturns($productid, $days),
            'loading_returns' => self::loadingReturns($productid, $days),
        ];
    }

    /** Pallets received through a gate — the barcode, its bundles and the date. */
    public static function receipts(int $warehouseId, int $productid, int $days = self::DEFAULT_WINDOW): array
    {
        return DB::connection('bil')->table('finished_goods_warehouse_receipts as r')
            ->leftJoin('core.warehouse_gates as g', 'r.entrance_id', '=', 'g.id')
            ->where('r.warehouse_id', $warehouseId)
            ->where('r.productid', $productid)
            ->where('r.date_of_entrance', '>=', self::sinceIso($days))
            ->orderByDesc('r.date_of_entrance')->orderByDesc('r.id')
            ->limit(self::LIMIT)
            ->get(['r.barcode', 'r.bundles', 'r.date_of_entrance', 'r.username',
                'r.is_historic', 'g.name as gate'])
            ->all();
    }

    /** Manual corrections, with who made them and why. */
    public static function adjustments(int $warehouseId, int $productid, int $days = self::DEFAULT_WINDOW): array
    {
        return DB::connection('bil')->table('finished_goods_stock_adjustments')
            ->where('warehouse_id', $warehouseId)
            ->where('productid', $productid)
            ->where('created_at', '>=', now()->subDays($days))
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
    public static function salesReturns(int $productid, int $days = self::DEFAULT_WINDOW): array
    {
        $rows = DB::connection('bil')->table('sales_return as sr')
            ->join('sales_order_details as d', 'sr.sod_id', '=', 'd.id')
            ->where('d.productid', $productid)
            ->where('sr.dateofreturn', '>=', self::since($days))
            ->orderByDesc('sr.id')
            ->limit(self::LIMIT)
            ->get(['sr.returnnumber', 'sr.quantityreturned', 'sr.quantityrejected',
                'sr.dateofreturn', 'sr.username', 'd.orderid']);

        $headers = self::orderHeaders($rows->pluck('orderid')->all());

        return $rows->map(function ($r) use ($headers) {
            $r->customerid = $headers[$r->orderid]->customerid ?? null;
            $r->customername = self::customerName($r->customerid);

            return $r;
        })->all();
    }

    /**
     * Loads unloaded again before they left — also back into stock.
     *
     * This table has no date column, only a unix `timestamp`, so the window is
     * applied against that.
     */
    public static function loadingReturns(int $productid, int $days = self::DEFAULT_WINDOW): array
    {
        return DB::connection('bil')->table('sales_loading_return as lr')
            ->join('sales_order_details as d', 'lr.sod_id', '=', 'd.id')
            ->where('d.productid', $productid)
            ->where('lr.timestamp', '>=', now()->subDays($days)->getTimestamp())
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
    public static function outgoing(int $productid, int $days = self::DEFAULT_WINDOW): array
    {
        $loadings = self::loadings($productid, $days);
        $loadNumbers = array_values(array_unique(array_filter(array_column($loadings, 'loadnumber'))));

        $deliveries = self::deliveries($loadNumbers, $days);
        $deliveryNumbers = array_values(array_unique(array_filter(array_column($deliveries, 'deliverynumber'))));

        return [
            'orders' => self::orders($productid, $days),
            'loadings' => $loadings,
            'deliveries' => $deliveries,
            'waybills' => self::waybills($deliveryNumbers, $days),
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
    public static function orders(int $productid, int $days = self::DEFAULT_WINDOW): array
    {
        // The date lives on the header, so the window is applied there first and
        // the details are then restricted to those orders — which also keeps
        // the collation mismatch out of the query.
        $orderIds = DB::connection('bil')->table('sales_order')
            ->where('dateoforder', '>=', self::since($days))
            ->pluck('orderid');

        if ($orderIds->isEmpty()) {
            return [];
        }

        $details = DB::connection('bil')->table('sales_order_details')
            ->where('productid', $productid)
            ->whereIn('orderid', $orderIds)
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
            $d->customername = self::customerName($o->customerid ?? null);
            $d->username = $o->username ?? null;
            $d->warehousecode = $o->warehousecode ?? null;

            return $d;
        })->all();
    }

    /** id => customer name, loaded once per request. */
    protected static ?array $customers = null;

    /**
     * Customer name for an id.
     *
     * `sales_order.customerid` and `sales_delivery.deliverycustomerid` both hold
     * `sales_customers.id`. There are only a few thousand customers, so the
     * whole list is cached rather than joined into six separate queries —
     * `deliverycustomerid` is a varchar against an int key, which is the kind
     * of mismatch that costs an index (see the orderid note above).
     */
    public static function customerName($id): string
    {
        if ($id === null || $id === '') {
            return '—';
        }

        self::$customers ??= DB::connection('bil')->table('sales_customers')
            ->pluck('customername', 'id')->all();

        return self::$customers[(int) $id] ?? ('#' . $id);
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
    public static function loadings(int $productid, int $days = self::DEFAULT_WINDOW): array
    {
        return DB::connection('bil')->table('sales_loading as l')
            ->join('sales_order_details as d', 'l.sod_id', '=', 'd.id')
            ->where('d.productid', $productid)
            ->where('l.dateofloading', '>=', self::since($days))
            ->orderByDesc('l.id')
            ->limit(self::LIMIT)
            ->get(['l.loadnumber', 'l.barcode', 'l.quantityloaded', 'l.cageroomcode',
                'l.dateofloading', 'l.username', 'l.status', 'd.orderid'])
            ->all();
    }

    /** Deliveries, reached through the load numbers this product went out on. */
    public static function deliveries(array $loadNumbers, int $days = self::DEFAULT_WINDOW): array
    {
        if ($loadNumbers === []) {
            return [];
        }

        return DB::connection('bil')->table('sales_delivery')
            ->whereIn('loadnumber', $loadNumbers)
            ->where('dateofdelivery', '>=', self::since($days))
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['deliverynumber', 'barcode', 'loadnumber', 'dateofdelivery',
                'username', 'deliverycustomerid'])
            ->map(function ($r) {
                $r->customername = self::customerName($r->deliverycustomerid);

                return $r;
            })
            ->all();
    }

    /** Waybills, reached through those deliveries — the end of the chain. */
    public static function waybills(array $deliveryNumbers, int $days = self::DEFAULT_WINDOW): array
    {
        if ($deliveryNumbers === []) {
            return [];
        }

        return DB::connection('bil')->table('sales_waybill')
            ->whereIn('deliverynumber', $deliveryNumbers)
            ->where('dateofwaybill', '>=', self::since($days))
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['barcode', 'deliverynumber', 'receiptnumber', 'dateofwaybill', 'username'])
            ->all();
    }
}
