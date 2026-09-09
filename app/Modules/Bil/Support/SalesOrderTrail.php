<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;

/**
 * Everything that ever happened to ONE sales order, line by line.
 *
 * The five sales screens each answer "what happened today"; this answers "what
 * happened to this order", which is the question a customer asks on the phone
 * and which no screen could answer before — you had to open Orders, then
 * Loading, then Delivery, then Waybill, and hold the barcodes in your head.
 *
 * THE UNIT IS THE ORDER LINE, NOT THE PRODUCT. The same product is routinely
 * ordered twice on one order — once sold, once free of charge — and 81,778
 * order/product pairs in this data do exactly that. They are separate lines
 * with separate quantities and separate loadings, so they get separate pages,
 * each labelled with its type. Merging them by product would add two different
 * things together.
 *
 * The chain, and where each link is stored:
 *
 *   order      sales_order + sales_order_details      the promise
 *   loading    sales_loading.sod_id                   what went on a truck
 *   unload     sales_loading_return                   what came back off it
 *                                                     at the gate, before it left
 *   delivery   sales_loading.status + sales_delivery  the customer's confirmation
 *   waybill    sales_waybill                          what the haulage cost
 *   return     sales_return.sod_id                    what the customer sent back
 *
 * Two traps this class is written around, both documented at length on the
 * Sales reports:
 *
 *   - `loadnumber` restarts every day, so a delivery is found by (date, number)
 *     and never by the number alone.
 *   - 462 (date, loadnumber) pairs carry TWO delivery rows, from the
 *     double-confirmation the legacy screen had no guard against. The first is
 *     the real one; taking them both would show a load delivered twice.
 */
class SalesOrderTrail
{
    /** Order of the stages within one day, so a day reads as a narrative. */
    private const STAGE_RANK = [
        'order' => 0, 'loading' => 1, 'unload' => 2,
        'delivery' => 3, 'waybill' => 4, 'return' => 5,
    ];

    private static function db()
    {
        return DB::connection('bil');
    }

    /* ---------------- Finding the order ---------------- */

    /** The order and who it is for, or null. `orderid` is unique. */
    public static function find(string $orderid): ?object
    {
        $orderid = trim($orderid);

        if ($orderid === '') {
            return null;
        }

        $order = self::db()->table('sales_order as so')
            ->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid')
            ->where('so.orderid', $orderid)
            ->first(['so.id', 'so.orderid', 'so.dateoforder', 'so.warehousecode', 'so.username',
                'so.customerid', 'so.timestamp', 'c.customername', 'c.customercode',
                'c.customeraddress', 'c.customerstate']);

        if (! $order) {
            return null;
        }

        $order->warehouse = DB::connection('core')->table('warehouses')
            ->where('legacy_sales_code', $order->warehousecode)->value('name')
            ?: ($order->warehousecode ?: null);

        return $order;
    }

    /**
     * Orders matching what was typed, for the search box.
     *
     * Two ways in, because the two ways people arrive at this screen are "the
     * customer read me a number" and "the customer gave me their name". A
     * number is matched as a PREFIX so it uses the unique index on `orderid`;
     * anything else is treated as a customer and their recent orders listed.
     */
    public static function suggest(string $term, int $limit = 15): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $q = self::db()->table('sales_order as so')
            ->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid');

        if (ctype_digit($term)) {
            // Anchored, so the index does the work: 97,885 orders and no scan.
            $q->where('so.orderid', 'like', $term . '%');
        } else {
            $q->where('c.customername', 'like', '%' . $term . '%');
        }

        return $q->orderByDesc('so.dateoforder')->orderByDesc('so.id')->limit($limit)
            ->get(['so.orderid', 'so.dateoforder', 'c.customername'])->all();
    }

    /* ---------------- The lines, one per page ---------------- */

    /**
     * The order's lines, each with what has happened to it in total.
     *
     * `loaded` is summed off `sales_loading` rather than joined, so a line
     * loaded on three trucks is not multiplied by three — the same correlated
     * subquery the Orders report uses, and for the same reason.
     *
     * ⚠️ `quantityloaded` is stored NET of anything taken back off the truck at
     * the gate, so the unloads are shown as events but never subtracted again.
     */
    public static function lines(string $orderid, ?int $productid = null): array
    {
        $rows = self::db()->table('sales_order_details as sod')
            ->leftJoin('products as p', 'p.productid', '=', 'sod.productid')
            ->where('sod.orderid', $orderid)
            ->when($productid, fn ($q) => $q->where('sod.productid', $productid))
            ->orderBy('sod.id')
            ->selectRaw('sod.id, sod.productid, sod.quantityordered, sod.foc,
                         p.productname, p.productcode,
                         COALESCE((SELECT SUM(quantityloaded) FROM sales_loading
                                   WHERE sales_loading.sod_id = sod.id), 0) as loaded,
                         COALESCE((SELECT SUM(quantityloaded) FROM sales_loading
                                   WHERE sales_loading.sod_id = sod.id
                                     AND sales_loading.status IS NOT NULL), 0) as delivered,
                         COALESCE((SELECT SUM(quantityreturned) FROM sales_return
                                   WHERE sales_return.sod_id = sod.id), 0) as returned,
                         COALESCE((SELECT SUM(quantityrejected) FROM sales_return
                                   WHERE sales_return.sod_id = sod.id), 0) as rejected')
            ->get();

        foreach ($rows as $row) {
            $row->productname = $row->productname ?: '— product deleted —';
            $row->balance = (int) $row->quantityordered - (int) $row->loaded;
        }

        return $rows->all();
    }

    /** The distinct products on an order, for the optional product filter. */
    public static function products(string $orderid): array
    {
        return self::db()->table('sales_order_details as sod')
            ->leftJoin('products as p', 'p.productid', '=', 'sod.productid')
            ->where('sod.orderid', $orderid)
            ->orderBy('p.productname')
            ->pluck('p.productname', 'sod.productid')
            ->map(fn ($name) => $name ?: '— product deleted —')
            ->all();
    }

    /* ---------------- The trail for one line ---------------- */

    /**
     * Every transaction on one order line, in the order it happened.
     *
     * Sorted by date, then by where the stage sits in the chain, so a day reads
     * loading -> unload -> delivery -> waybill rather than in id order. A
     * delivery is confirmed on its loading's own date (see
     * SalesDeliveries::confirm), so in practice the whole of a truck's story
     * lands on one day and stays together.
     */
    public static function trail(object $order, object $line): array
    {
        $events = [self::event('order', (string) $order->dateoforder, 'Ordered',
            (string) $order->orderid, (int) $line->quantityordered,
            trim(($order->warehouse ? $order->warehouse . ' · ' : '')
                . 'entered by ' . ($order->username ?: 'unknown')),
            (int) ($order->timestamp ?: 0))];

        $loadings = self::db()->table('sales_loading as l')
            ->leftJoin('sales_transporters as t', 't.id', '=', 'l.transporterid')
            ->where('l.sod_id', $line->id)
            ->orderBy('l.dateofloading')->orderBy('l.id')
            ->get(['l.id', 'l.barcode', 'l.dateofloading', 'l.quantityloaded', 'l.status',
                'l.loadnumber', 'l.trucknumber', 'l.truckdriver', 'l.loader',
                'l.cageroomcode', 'l.timestamp', 't.transportername']);

        $gates = self::gateNames($loadings->pluck('cageroomcode')->filter()->unique()->all());

        foreach ($loadings as $load) {
            $events[] = self::event('loading', (string) $load->dateofloading, 'Loaded',
                (string) $load->barcode, (int) $load->quantityloaded,
                self::loadDetail($load, $gates), (int) ($load->timestamp ?: 0));

            foreach (self::unloads($line->id, (string) $load->barcode) as $u) {
                $events[] = self::event('unload',
                    $u->timestamp ? date('Y/m/d', (int) $u->timestamp) : (string) $load->dateofloading,
                    'Taken back at the gate', (string) $load->barcode,
                    (int) $u->quantityunloaded,
                    'Off the truck before it left — already deducted from the loaded figure',
                    (int) ($u->timestamp ?: 0));
            }

            if (! $load->status) {
                continue;
            }

            $delivery = self::deliveryFor((int) $load->loadnumber, (string) $load->status);

            $events[] = self::event('delivery', (string) $load->status, 'Delivered',
                $delivery->barcode ?? '—', (int) $load->quantityloaded,
                $delivery
                    ? 'Delivery #' . $delivery->deliverynumber . ' against load ' . $load->barcode
                    : 'Confirmed on the load, with no delivery note raised',
                (int) ($delivery->timestamp ?? $load->timestamp ?? 0));

            if ($delivery) {
                $waybill = self::waybillFor((int) $delivery->deliverynumber, (string) $delivery->dateofdelivery);

                if ($waybill) {
                    $events[] = self::event('waybill', (string) $waybill->dateofwaybill, 'Waybill raised',
                        (string) $waybill->barcode, null,
                        self::waybillDetail($waybill), (int) ($waybill->timestamp ?: 0),
                        (float) $waybill->transportcost);
                }
            }
        }

        foreach (self::db()->table('sales_return')->where('sod_id', $line->id)
            ->orderBy('dateofreturn')->orderBy('id')
            ->get(['returnnumber', 'dateofreturn', 'quantityreturned', 'quantityrejected',
                'username', 'timestamp']) as $r) {
            $events[] = self::event('return', (string) $r->dateofreturn, 'Returned by customer',
                '#' . $r->returnnumber, (int) $r->quantityreturned,
                ((int) $r->quantityrejected > 0
                    ? number_format((int) $r->quantityrejected) . ' of them rejected as damaged'
                    : 'All of it back to sellable stock')
                . ' · booked by ' . ($r->username ?: 'unknown'),
                (int) strtotime((string) $r->timestamp));
        }

        usort($events, function ($a, $b) {
            return [$a['date'], self::STAGE_RANK[$a['stage']], $a['at']]
               <=> [$b['date'], self::STAGE_RANK[$b['stage']], $b['at']];
        });

        return $events;
    }

    private static function event(string $stage, string $date, string $label, string $reference,
        ?int $quantity, string $detail, int $at = 0, ?float $money = null): array
    {
        return compact('stage', 'date', 'label', 'reference', 'quantity', 'detail', 'at', 'money');
    }

    private static function loadDetail(object $load, array $gates): string
    {
        return implode(' · ', array_filter([
            $load->trucknumber ? 'Truck ' . $load->trucknumber : null,
            $load->truckdriver ?: null,
            $load->transportername ?: null,
            $gates[$load->cageroomcode] ?? ($load->cageroomcode ?: null),
            $load->loader ? 'loaded by ' . $load->loader : null,
        ]));
    }

    private static function waybillDetail(object $waybill): string
    {
        return implode(' · ', array_filter([
            '₦' . number_format((float) $waybill->transportcost, 2),
            $waybill->receiptnumber !== null && $waybill->receiptnumber !== ''
                ? 'receipt ' . $waybill->receiptnumber : 'no receipt number',
        ]));
    }

    /** What came back off the truck, matched the way the reports match it. */
    private static function unloads(int $sodId, string $barcode)
    {
        return self::db()->table('sales_loading_return')
            ->where('sod_id', $sodId)->where('barcode', $barcode)
            ->where('quantityunloaded', '>', 0)
            ->orderBy('id')->get(['quantityunloaded', 'timestamp']);
    }

    /**
     * The delivery that closed a load.
     *
     * By (date, load number) — the number alone restarts daily — and the FIRST
     * of them, because 462 pairs carry a duplicate from the double-confirm bug.
     */
    private static function deliveryFor(int $loadnumber, string $date): ?object
    {
        return self::db()->table('sales_delivery')
            ->where('loadnumber', $loadnumber)->where('dateofdelivery', $date)
            ->orderBy('id')
            ->first(['id', 'deliverynumber', 'barcode', 'dateofdelivery', 'timestamp']);
    }

    /**
     * The waybill on a delivery.
     *
     * `sales_waybill.deliverynumber` is a VARCHAR against an INT on
     * `sales_delivery`, so the comparison is cast on the waybill side, leaving
     * `sw_date_idx` usable for the date.
     */
    private static function waybillFor(int $deliverynumber, string $date): ?object
    {
        return self::db()->table('sales_waybill')
            ->where('dateofwaybill', $date)
            ->whereRaw('CAST(deliverynumber AS UNSIGNED) = ?', [$deliverynumber])
            ->orderBy('id')
            ->first(['barcode', 'dateofwaybill', 'receiptnumber', 'transportcost', 'timestamp']);
    }

    /** Cageroom codes are legacy names on the core gate table. */
    private static function gateNames(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return DB::connection('core')->table('warehouse_gates')
            ->whereIn('legacy_name', $codes)->pluck('name', 'legacy_name')->all();
    }
}
