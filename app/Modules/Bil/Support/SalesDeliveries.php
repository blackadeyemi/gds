<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;
use Modules\Bil\Models\SalesDelivery;
use Modules\Bil\Models\SalesLoading;

/**
 * The rules behind delivery — confirming that a load actually left, and undoing
 * that confirmation.
 *
 * Delivery CREATES ALMOST NOTHING. It writes one `sales_delivery` row per load
 * and stamps `sales_loading.status` with the date. Nothing about what is on the
 * truck is re-entered, because it was already entered at loading; the screen's
 * whole job is to say "this one went". That is why there is no quantity here.
 *
 * IT DOES NOT MOVE STOCK. FinishedGoodsStock::loadedSinceCutover() takes
 * bundles off the warehouse when they are LOADED and deliberately ignores
 * `status`, so a delivery has no stock effect at all. Adding one would take the
 * same bundles off twice.
 *
 * THE DATE IS THE LOAD'S, NOT TODAY'S. `dateofdelivery` is set to the load's
 * `dateofloading` — all 129,583 legacy rows agree, and it is load-bearing: a
 * delivery points at its load only through (`loadnumber`, `dateofdelivery`), so
 * dating a delivery "today" for a load raised yesterday would orphan it from
 * the load, the printout and the waybill at once.
 *
 * ONE BUG IS NOT REPRODUCED. The legacy request script checked only that the
 * load number existed — never that it was still open — so a stale page could
 * confirm the same load twice. 462 load numbers in the history carry two
 * deliveries because of it. confirm() refuses a load with no open lines, which
 * is the whole of that fix.
 */
class SalesDeliveries
{
    /** Warehouse code -> the letter its barcodes start with (legacy format). */
    private const WAREHOUSE_LETTER = ['1' => 'L', '2' => 'K', '3' => 'A'];

    /** The legacy barcode's fixed "delivery" character. */
    private const DELIVERY_CHAR = 'D';

    /* ---------------- The queue ---------------- */

    /**
     * Loads still waiting to be delivered — the legacy's "BARCODES NOT
     * DELIVERED".
     *
     * Identical to the loading queue, because they are the same set: a load is
     * open exactly until it is delivered, and delivery is what closes it. It is
     * fetched through SalesLoadings so the two screens can never disagree about
     * what "open" means.
     */
    public static function pendingLoads(?string $search = null, ?array $cageroomCodes = null, int $limit = 200): array
    {
        return SalesLoadings::openLoads($search, $cageroomCodes, $limit);
    }

    /* ---------------- Numbering and barcodes ---------------- */

    /** Delivery numbers restart every day, independently of load numbers. */
    public static function nextDeliveryNumber(string $dateSlash): int
    {
        return (int) DB::connection('bil')->table('sales_delivery')
            ->where('dateofdelivery', $dateSlash)->max('deliverynumber') + 1;
    }

    /** `{yy}-{mm}-{dd}-{letter}{code}D-{nnn}` — the legacy format, exactly. */
    public static function barcodeFor(string $dateSlash, string $warehouseCode, int $deliveryNumber): string
    {
        [$y, $m, $d] = array_pad(explode('/', $dateSlash), 3, '00');
        $int = (string) (int) $warehouseCode;
        $letter = self::WAREHOUSE_LETTER[$int] ?? 'L';

        return sprintf('%s-%s-%s-%s%s%s-%03d',
            substr($y, -2), $m, $d, $letter, $int, self::DELIVERY_CHAR, $deliveryNumber);
    }

    /**
     * The barcode of the load a delivery belongs to.
     *
     * The row does not carry it, but both barcodes are built from the same
     * date and warehouse and differ in one character, so the delivery's own
     * barcode supplies the warehouse the load number is missing:
     *
     *     26-06-30-L1D-379  ->  26-06-30-L1L-381
     */
    public static function loadBarcodeFor(object $delivery): string
    {
        $warehouse = '1';

        // …-L1D-379: the digit before the delivery character is the warehouse.
        if (preg_match('/-([A-Z])(\d)' . self::DELIVERY_CHAR . '-/', (string) $delivery->barcode, $m)) {
            $warehouse = $m[2];
        }

        return SalesLoadings::barcodeFor(
            (string) $delivery->dateofdelivery, $warehouse, (int) $delivery->loadnumber
        );
    }

    /* ---------------- Reading deliveries ---------------- */

    /**
     * Every delivery on a date, newest number first — the legacy print-out list
     * and the list a delivery is reached through to undo it.
     *
     * The customer comes off `deliverycustomerid`, denormalised onto the
     * delivery row, rather than through the order chain: `sales_order.orderid`
     * is latin1 where `sales_order_details.orderid` is utf8mb3, and joining
     * across that mismatch cannot use an index. The truck and crew come from
     * the loading rows for the same date, matched on load number in PHP.
     */
    public static function deliveriesOn(string $dateSlash): array
    {
        $deliveries = DB::connection('bil')->table('sales_delivery')
            ->where('dateofdelivery', $dateSlash)
            ->orderByDesc('deliverynumber')
            ->get()->all();

        return $deliveries === [] ? [] : self::decorate($deliveries, $dateSlash);
    }

    /** One delivery, by id — how the screen holds on to a particular one. */
    public static function find(int $id): ?object
    {
        $row = DB::connection('bil')->table('sales_delivery')->where('id', $id)->first();

        return $row ? (self::decorate([$row], (string) $row->dateofdelivery)[0] ?? null) : null;
    }

    /** One delivery, by its own barcode. */
    public static function delivery(string $deliveryBarcode): ?object
    {
        $row = DB::connection('bil')->table('sales_delivery')
            ->where('barcode', $deliveryBarcode)->orderByDesc('id')->first();

        return $row ? (self::decorate([$row], (string) $row->dateofdelivery)[0] ?? null) : null;
    }

    /**
     * The deliveries of one load, oldest first.
     *
     * More than one is possible and legitimate: a truck that goes out, comes
     * back and is loaded again under the same load number — the case the
     * loading screen's "new load number" box exists to distinguish — produces a
     * second delivery against the same load number and date.
     */
    public static function deliveriesForLoad(int $loadNumber, string $dateSlash): array
    {
        return DB::connection('bil')->table('sales_delivery')
            ->where('loadnumber', $loadNumber)
            ->where('dateofdelivery', $dateSlash)
            ->orderBy('timestamp')->orderBy('id')
            ->get()->all();
    }

    /** The delivery that closed a load, or null while it is still open. */
    public static function latestForLoad(int $loadNumber, string $dateSlash): ?object
    {
        $all = self::deliveriesForLoad($loadNumber, $dateSlash);

        return $all === [] ? null : (self::decorate([end($all)], $dateSlash)[0] ?? null);
    }

    /**
     * Fill in customer, load barcode, truck, crew and waybill state.
     *
     * Four indexed queries for a whole day rather than a chain of joins per
     * row — the same shape SalesLoadings::decorate() uses, and for the same
     * collation reason.
     */
    private static function decorate(array $deliveries, string $dateSlash): array
    {
        $bil = DB::connection('bil');

        $customerIds = array_values(array_unique(array_filter(
            array_map(fn ($d) => $d->deliverycustomerid, $deliveries)
        )));

        $customers = $customerIds === [] ? collect() : $bil->table('sales_customers')
            ->whereIn('id', $customerIds)
            ->get(['id', 'customername', 'customeraddress'])->keyBy('id');

        $loadNumbers = array_values(array_unique(array_map(fn ($d) => (int) $d->loadnumber, $deliveries)));

        // The load's header and totals, one grouped read for the whole date.
        $loads = $bil->table('sales_loading as l')
            ->leftJoin('sales_transporters as t', 'l.transporterid', '=', 't.id')
            ->where('l.dateofloading', $dateSlash)
            ->whereIn('l.loadnumber', $loadNumbers)
            ->groupBy('l.loadnumber')
            ->selectRaw('l.loadnumber, MAX(l.barcode) as loadbarcode, MAX(l.trucknumber) as trucknumber,
                         MAX(l.truckdriver) as truckdriver, MAX(l.loader) as loader,
                         MAX(l.cageroomcode) as cageroomcode, MAX(t.transportername) as transportername,
                         MAX(l.sales_loading_customerid) as customerid,
                         COUNT(*) as line_count, SUM(l.quantityloaded) as loaded')
            ->get()->keyBy('loadnumber');

        // A delivery cannot be undone once its waybill is raised — the legacy
        // modification screen refused it too, and for the same reason: the
        // waybill is the paperwork the money follows.
        $waybills = $bil->table('sales_waybill')
            ->where('dateofwaybill', $dateSlash)
            ->whereIn('deliverynumber', array_map(fn ($d) => (string) $d->deliverynumber, $deliveries) ?: ['~none~'])
            ->pluck('barcode', 'deliverynumber');

        foreach ($deliveries as $d) {
            $load = $loads[(int) $d->loadnumber] ?? null;
            $customerId = $d->deliverycustomerid ?: ($load->customerid ?? null);
            $customer = $customerId ? ($customers[$customerId] ?? null) : null;

            $d->customerid = $customerId ?: null;
            $d->customername = $customer->customername ?? null;
            $d->customeraddress = $customer->customeraddress ?? null;
            $d->loadbarcode = $load->loadbarcode ?? self::loadBarcodeFor($d);
            $d->trucknumber = $load->trucknumber ?? null;
            $d->truckdriver = $load->truckdriver ?? null;
            $d->loader = $load->loader ?? null;
            $d->cageroomcode = $load->cageroomcode ?? null;
            $d->transportername = $load->transportername ?? null;
            $d->line_count = (int) ($load->line_count ?? 0);
            $d->loaded = (int) ($load->loaded ?? 0);
            $d->waybill = $waybills[(string) $d->deliverynumber] ?? null;
        }

        return $deliveries;
    }

    /* ---------------- Confirming ---------------- */

    /**
     * Confirm that a load left. Returns [ok, message, barcode].
     *
     * One transaction: a delivery row without the closed load would leave the
     * truck on the floor with its number already spent, and a closed load
     * without the row would be undeliverable and unprintable.
     */
    public static function confirm(string $loadBarcode): array
    {
        $load = SalesLoadings::load($loadBarcode);

        if (! $load) {
            return ['ok' => false, 'message' => 'That load could not be found.'];
        }

        $openLines = SalesLoading::forLoad($loadBarcode)->whereNull('status')->count();

        // The legacy's missing check. Without it a stale page confirmed the
        // same load a second time, which is where 462 duplicate deliveries in
        // the history came from.
        if ($openLines === 0) {
            return ['ok' => false, 'message' => 'This load has already been delivered — there is nothing open on it.'];
        }

        $dateSlash = (string) $load->dateofloading;
        $user = auth()->user();
        $username = (string) ($user?->username ?? $user?->name ?? 'gds');

        $barcode = DB::connection('bil')->transaction(function () use ($load, $loadBarcode, $dateSlash, $username) {
            $number = self::nextDeliveryNumber($dateSlash);
            $barcode = self::barcodeFor($dateSlash, (string) ($load->warehousecode ?: '1'), $number);

            SalesDelivery::create([
                'deliverynumber' => $number,
                'barcode' => $barcode,
                'username' => $username,
                'loadnumber' => (int) $load->loadnumber,
                'dateofdelivery' => $dateSlash,
                'timestamp' => time(),
                'deliverycustomerid' => (string) ($load->customerid ?? ''),
            ]);

            // Closed BY BARCODE and only where still open. The legacy closed by
            // (load number, date), which is the same set in every case since
            // 2018 but would also re-stamp lines an earlier delivery had
            // already closed.
            SalesLoading::forLoad($loadBarcode)->whereNull('status')
                ->update(['status' => $dateSlash]);

            return $barcode;
        });

        return ['ok' => true, 'barcode' => $barcode, 'message' => 'Delivery ' . $barcode . ' confirmed.'];
    }

    /* ---------------- Undoing ---------------- */

    /**
     * Delete a delivery and put its load back on the floor.
     *
     * Refused once a waybill exists, as the legacy screen was, and refused for
     * anything but the LAST delivery of a load number: reopening an earlier one
     * while a later one still stands would leave the later delivery pointing at
     * lines that are open again.
     */
    public static function undo(int $deliveryId): array
    {
        $delivery = SalesDelivery::find($deliveryId);

        if (! $delivery) {
            return ['ok' => false, 'message' => 'That delivery could not be found.'];
        }

        $dateSlash = (string) $delivery->dateofdelivery;
        $siblings = self::deliveriesForLoad((int) $delivery->loadnumber, $dateSlash);
        $last = end($siblings);

        if ($last && (int) $last->id !== (int) $delivery->id) {
            return ['ok' => false, 'message' =>
                'Load ' . $delivery->loadnumber . ' was delivered again after this one. Undo delivery '
                . $last->deliverynumber . ' first.'];
        }

        if (self::hasWaybill((int) $delivery->deliverynumber, $dateSlash)) {
            return ['ok' => false, 'message' =>
                'A waybill has been raised for this delivery — it can no longer be undone.'];
        }

        $loadBarcode = self::loadBarcodeFor($delivery);

        // Which lines this delivery closed. With one delivery on the load — the
        // normal case — it is all of them, and the timestamp bound does
        // nothing. With two, it separates the second trip's lines from the
        // first's, which nothing on the delivery row records.
        $previous = count($siblings) > 1 ? $siblings[count($siblings) - 2] : null;

        DB::connection('bil')->transaction(function () use ($delivery, $loadBarcode, $dateSlash, $previous) {
            SalesLoading::forLoad($loadBarcode)
                ->where('status', $dateSlash)
                ->when($previous, fn ($q) => $q->where('timestamp', '>', (int) $previous->timestamp))
                ->update(['status' => null]);

            $delivery->delete();
        });

        return ['ok' => true, 'message' =>
            'Delivery ' . $delivery->barcode . ' removed — load ' . $loadBarcode . ' is open again.'];
    }

    public static function hasWaybill(int $deliveryNumber, string $dateSlash): bool
    {
        return DB::connection('bil')->table('sales_waybill')
            ->where('deliverynumber', (string) $deliveryNumber)
            ->where('dateofwaybill', $dateSlash)
            ->exists();
    }

    /* ---------------- Lines ---------------- */

    /**
     * What a delivery carried, one row per product with quantities summed.
     *
     * Reproduces the legacy printout's query — SUM(quantityloaded) GROUP BY
     * productid ORDER BY productname — with one correction. Where a load number
     * has more than one delivery, each line is attributed to the FIRST delivery
     * raised at or after the line was written, so the second trip's sheet lists
     * the second trip's goods. The legacy summed the load number and printed
     * the morning's goods on the evening's sheet.
     */
    public static function lines(object $delivery): array
    {
        $rows = self::rawLines((int) $delivery->loadnumber, (string) $delivery->dateofdelivery);

        if ($rows === []) {
            return [];
        }

        $mine = self::linesOfDelivery($rows, $delivery);

        return self::summarise($mine);
    }

    /** Every loading row of a load number on a date, with its product. */
    private static function rawLines(int $loadNumber, string $dateSlash): array
    {
        return DB::connection('bil')->table('sales_loading as l')
            ->leftJoin('sales_order_details as d', 'l.sod_id', '=', 'd.id')
            ->leftJoin('products as p', 'd.productid', '=', 'p.productid')
            ->where('l.dateofloading', $dateSlash)
            ->where('l.loadnumber', $loadNumber)
            ->get([
                'l.id', 'l.barcode', 'l.quantityloaded', 'l.timestamp', 'l.status',
                'l.cageroomcode', 'd.orderid', 'd.foc', 'd.productid',
                'p.productname', 'p.productcode',
            ])->all();
    }

    /**
     * Split a load number's lines between its deliveries.
     *
     * A line belongs to the first delivery raised at or after the line was
     * written, and to the last delivery if it was written after all of them —
     * so every line lands on exactly one sheet and none is dropped.
     *
     * WITH ONE EXCEPTION, for the legacy's duplicates. Most of the 462 load
     * numbers carrying two deliveries are not second trips at all but the same
     * load confirmed twice from a stale page, and every line predates both
     * confirmations. The partition would hand the second one nothing, and a
     * blank delivery note helps no one — so a delivery that comes out empty
     * falls back to the whole load, which is what the legacy sheet printed.
     */
    private static function linesOfDelivery(array $rows, object $delivery): array
    {
        $siblings = self::deliveriesForLoad((int) $delivery->loadnumber, (string) $delivery->dateofdelivery);

        if (count($siblings) < 2) {
            return $rows;
        }

        $lastId = (int) end($siblings)->id;

        $mine = array_values(array_filter($rows, function ($row) use ($siblings, $delivery, $lastId) {
            foreach ($siblings as $d) {
                if ((int) $row->timestamp <= (int) $d->timestamp) {
                    return (int) $d->id === (int) $delivery->id;
                }
            }

            return $lastId === (int) $delivery->id;
        }));

        return $mine === [] ? $rows : $mine;
    }

    /** One row per product, summed, ordered as MySQL ordered the legacy sheet. */
    private static function summarise(array $rows): array
    {
        return collect($rows)->groupBy('productid')
            ->map(fn ($group) => (object) [
                'productid' => $group->first()->productid,
                'productcode' => $group->first()->productcode,
                'productname' => $group->first()->productname,
                'quantityloaded' => (int) $group->sum('quantityloaded'),
                // FOC only when the whole quantity is free of charge — a merged
                // row that is mostly sold must not be marked as given away.
                'foc' => $group->every(fn ($l) => (int) $l->foc === 1),
            ])
            ->sortBy(fn ($l) => mb_strtolower((string) $l->productname))
            ->values()->all();
    }

    /* ---------------- Print-outs ---------------- */

    /**
     * Everything needed to print one or more delivery notes.
     *
     * Built for a SET of barcodes so a day's deliveries print in one run, which
     * is how the office works — the legacy screen made that a browser tab each.
     */
    public static function printouts(array $deliveryBarcodes): array
    {
        $deliveryBarcodes = array_values(array_filter(array_unique($deliveryBarcodes)));

        if ($deliveryBarcodes === []) {
            return [];
        }

        $rows = DB::connection('bil')->table('sales_delivery')
            ->whereIn('barcode', $deliveryBarcodes)->get()->all();

        if ($rows === []) {
            return [];
        }

        // decorate() is date-scoped, so a selection spanning days is decorated
        // a day at a time. Print runs are normally one date; this only keeps a
        // mixed selection honest.
        $out = [];

        foreach (collect($rows)->groupBy('dateofdelivery') as $dateSlash => $group) {
            foreach (self::decorate($group->values()->all(), (string) $dateSlash) as $d) {
                $out[] = $d;
            }
        }

        $warehouses = DB::connection('bil')->table('sales_warehouse')
            ->pluck('warehouselocation', 'warehousecode');

        $cagerooms = DB::connection('core')->table('warehouse_gates')
            ->whereIn('legacy_name', array_filter(array_map(fn ($d) => $d->cageroomcode, $out)) ?: ['~none~'])
            ->pluck('name', 'legacy_name');

        foreach ($out as $d) {
            $raw = self::rawLines((int) $d->loadnumber, (string) $d->dateofdelivery);
            $mine = self::linesOfDelivery($raw, $d);

            $orderIds = collect($mine)->pluck('orderid')->filter()->unique()->values()->all();

            $orderWarehouses = $orderIds === [] ? collect() : DB::connection('bil')->table('sales_order')
                ->whereIn('orderid', $orderIds)->pluck('warehousecode', 'orderid');

            $d->orders = $orderIds;
            $d->warehouse = collect($orderIds)
                ->map(fn ($o) => $warehouses[$orderWarehouses[$o] ?? ''] ?? null)
                ->filter()->unique()->implode(', ') ?: null;
            $d->cageroom = $d->cageroomcode ? ($cagerooms[$d->cageroomcode] ?? $d->cageroomcode) : null;
            $d->lines = self::summarise($mine);
            $d->total = (int) collect($mine)->sum('quantityloaded');
        }

        // Print in the order the operator picked them off the list.
        usort($out, fn ($a, $b) => array_search($a->barcode, $deliveryBarcodes, true)
            <=> array_search($b->barcode, $deliveryBarcodes, true));

        return $out;
    }
}
