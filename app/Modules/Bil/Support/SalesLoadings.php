<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;
use Modules\Bil\Models\SalesLoading;
use Modules\Bil\Models\SalesLoadingReturn;

/**
 * The rules behind cageroom loading — creating a load, correcting it, and
 * taking goods back off it.
 *
 * A LOAD is a barcode shared by every line on one truck. The three legacy
 * screens (sales_loading, _modification, _return) are three things you do to
 * one, and two of them only work while it is OPEN (`status IS NULL`), which is
 * why they belong on one page rather than three.
 *
 * QUANTITIES. `sales_loading.quantityloaded` is stored NET of returns and
 * `sales_loading_return` records what came back off, so what actually went onto
 * the truck is loaded + returned. Every figure here says which of the two it
 * means rather than leaving the caller to guess.
 *
 * STOCK LOOKS AFTER ITSELF. FinishedGoodsStock::loadedSinceCutover() reads
 * `sales_loading` directly and nets returns against it, so a load written here
 * takes bundles off warehouse stock with no further action. Adding an
 * adjustment would double-count it.
 */
class SalesLoadings
{
    /** Warehouse code -> the letter its barcodes start with (legacy format). */
    private const WAREHOUSE_LETTER = ['1' => 'L', '2' => 'K', '3' => 'A'];

    /** The legacy barcode's fixed "loading" character. */
    private const LOADING_CHAR = 'L';

    /* ---------------- The open queue ---------------- */

    /**
     * Loads still open, newest first, as one row per LOAD (not per line).
     *
     * `status IS NULL` is the whole definition of open, and it is the only state
     * in which a load can be corrected or returned against.
     */
    public static function openLoads(?string $search = null, ?array $cageroomCodes = null, int $limit = 200): array
    {
        $loads = self::loadQuery()
            ->whereNull('l.status')
            ->when($cageroomCodes !== null, fn ($q) => $q->whereIn('l.cageroomcode', $cageroomCodes ?: ['~none~']))
            ->when($search, fn ($q, $s) => $q->where(function ($w) use ($s) {
                $term = '%' . $s . '%';
                $w->where('l.barcode', 'like', $term)
                  ->orWhere('l.trucknumber', 'like', $term)
                  ->orWhere('l.truckdriver', 'like', $term)
                  ->orWhere('l.loader', 'like', $term);
            }))
            ->orderByDesc(DB::raw('MAX(l.id)'))
            ->limit($limit)->get()->all();

        $loads = self::decorate($loads);

        // Customer lives on the order, which is resolved after the query rather
        // than joined, so a search on it is applied here.
        if ($search) {
            $needle = mb_strtolower($search);
            $loads = array_values(array_filter($loads, fn ($l) => str_contains(mb_strtolower(
                $l->barcode . ' ' . $l->trucknumber . ' ' . $l->truckdriver . ' '
                . $l->loader . ' ' . ($l->customername ?? '')
            ), $needle)));
        }

        return $loads;
    }

    /** Loads on a date — how a closed one is reached, read-only, to reprint. */
    public static function loadsOn(string $dateSlash, int $limit = 200): array
    {
        return self::decorate(self::loadQuery()
            ->where('l.dateofloading', $dateSlash)
            ->orderByDesc(DB::raw('MAX(l.id)'))
            ->limit($limit)->get()->all());
    }

    /**
     * One row per load.
     *
     * `sales_order` is DELIBERATELY NOT JOINED here. Its `orderid` is latin1
     * while `sales_order_details.orderid` is utf8mb3, and MySQL cannot use an
     * index across that mismatch — the join degraded to a full scan of 97,106
     * orders behind a hash join, which was most of a 315ms queue. The order id
     * comes off `sales_order_details` (a primary-key lookup) and the order and
     * its customer are resolved afterwards in decorate(), by an indexed
     * whereIn. The same trap cost the stock movement modal 1.6s.
     */
    private static function loadQuery()
    {
        return DB::connection('bil')->table('sales_loading as l')
            ->leftJoin('sales_order_details as d', 'l.sod_id', '=', 'd.id')
            ->leftJoin('sales_transporters as t', 'l.transporterid', '=', 't.id')
            ->groupBy('l.barcode')
            ->selectRaw('l.barcode, MAX(l.loadnumber) as loadnumber, MAX(l.dateofloading) as dateofloading,
                         MAX(l.trucknumber) as trucknumber, MAX(l.truckdriver) as truckdriver,
                         MAX(l.loader) as loader, MAX(l.cageroomcode) as cageroomcode,
                         MAX(l.transporterid) as transporterid, MAX(t.transportername) as transportername,
                         MAX(d.orderid) as orderid,
                         COUNT(*) as line_count, SUM(l.quantityloaded) as loaded,
                         MAX(l.status) as status, MAX(l.id) as last_id');
    }

    /**
     * Fill in each load's order and customer.
     *
     * Two indexed lookups for the whole page instead of a scan per query — see
     * the collation note on loadQuery().
     */
    private static function decorate(array $loads): array
    {
        if ($loads === []) {
            return [];
        }

        $orderIds = array_values(array_unique(array_filter(array_map(fn ($l) => $l->orderid, $loads))));

        $orders = $orderIds === [] ? collect() : DB::connection('bil')->table('sales_order')
            ->whereIn('orderid', $orderIds)->get(['orderid', 'warehousecode', 'customerid'])
            ->keyBy('orderid');

        $customers = $orders->isEmpty() ? collect() : DB::connection('bil')->table('sales_customers')
            ->whereIn('id', $orders->pluck('customerid')->filter()->unique()->all())
            ->pluck('customername', 'id');

        foreach ($loads as $l) {
            $order = $l->orderid ? ($orders[$l->orderid] ?? null) : null;
            $l->warehousecode = $order->warehousecode ?? null;
            $l->customerid = $order->customerid ?? null;
            $l->customername = $order && $order->customerid ? ($customers[$order->customerid] ?? null) : null;
        }

        return $loads;
    }

    /* ---------------- One load ---------------- */

    public static function load(string $barcode): ?object
    {
        $row = self::loadQuery()->where('l.barcode', $barcode)->first();

        return $row ? (self::decorate([$row])[0] ?? null) : null;
    }

    /**
     * Every line of a load, with what went on and what came back.
     *
     * `loaded_net` is the stored figure, `returned` the sum off the return
     * table, `loaded_gross` what was originally put on the truck. Showing all
     * three is what makes a correction comprehensible — the legacy screen
     * printed "LOADED / NOT LOADED / NEW VALUE" for the same reason.
     */
    public static function lines(string $barcode): array
    {
        $lines = DB::connection('bil')->table('sales_loading as l')
            ->leftJoin('sales_order_details as d', 'l.sod_id', '=', 'd.id')
            ->leftJoin('sales_order as o', 'd.orderid', '=', 'o.orderid')
            ->leftJoin('products as p', 'd.productid', '=', 'p.productid')
            ->where('l.barcode', $barcode)
            ->orderBy('p.productname')
            ->get([
                'l.id', 'l.sod_id', 'l.quantityloaded', 'l.status',
                'o.orderid', 'd.productid', 'd.quantityordered', 'd.foc',
                'p.productname', 'p.productcode',
            ]);

        if ($lines->isEmpty()) {
            return [];
        }

        $returns = DB::connection('bil')->table('sales_loading_return')
            ->where('barcode', $barcode)
            ->groupBy('sod_id')
            ->selectRaw('sod_id, SUM(quantityunloaded) as qty')
            ->pluck('qty', 'sod_id');

        return $lines->map(function ($l) use ($returns) {
            $returned = (int) ($returns[$l->sod_id] ?? 0);
            $l->returned = $returned;
            $l->loaded_net = (int) $l->quantityloaded;
            $l->loaded_gross = (int) $l->quantityloaded + $returned;

            return $l;
        })->all();
    }

    /* ---------------- Creating a load ---------------- */

    /**
     * Order lines that can still be loaded, with how much is outstanding.
     *
     * Outstanding = ordered minus everything already loaded (net of returns)
     * across every load of that order line, so re-opening a partly loaded order
     * offers only the remainder.
     */
    public static function orderLines(string $orderid): array
    {
        $details = DB::connection('bil')->table('sales_order_details as d')
            ->leftJoin('products as p', 'd.productid', '=', 'p.productid')
            ->where('d.orderid', $orderid)
            ->orderBy('p.productname')
            ->get(['d.id as sod_id', 'd.productid', 'd.quantityordered', 'd.foc',
                'p.productname', 'p.productcode']);

        if ($details->isEmpty()) {
            return [];
        }

        $loaded = DB::connection('bil')->table('sales_loading')
            ->whereIn('sod_id', $details->pluck('sod_id')->all())
            ->groupBy('sod_id')
            ->selectRaw('sod_id, SUM(quantityloaded) as qty')->pluck('qty', 'sod_id');

        return $details->map(function ($d) use ($loaded) {
            $d->already = (int) ($loaded[$d->sod_id] ?? 0);
            $d->outstanding = max(0, (int) $d->quantityordered - $d->already);

            return $d;
        })->all();
    }

    /** Recent orders, for the New Loading picker. */
    public static function loadableOrders(int $days = 250, int $limit = 300): array
    {
        $since = now()->subDays($days)->format('Y/m/d');

        return DB::connection('bil')->table('sales_order as o')
            ->leftJoin('sales_customers as c', 'o.customerid', '=', 'c.id')
            ->where('o.dateoforder', '>=', $since)
            ->orderByDesc('o.id')->limit($limit)
            ->get(['o.orderid', 'o.warehousecode', 'o.customerid', 'c.customername'])->all();
    }

    public static function order(string $orderid): ?object
    {
        return DB::connection('bil')->table('sales_order as o')
            ->leftJoin('sales_customers as c', 'o.customerid', '=', 'c.id')
            ->where('o.orderid', $orderid)
            ->first(['o.orderid', 'o.warehousecode', 'o.customerid', 'c.customername']);
    }

    /**
     * The load number for a truck on a day.
     *
     * Reuses the existing number when the SAME truck, loader, driver and
     * customer are already loading today — that is how one truck taking several
     * order lines stays one load — and otherwise takes the next number for the
     * date. Reproduces sales_loading_request.php, because the legacy app and its
     * reports still read what this writes.
     */
    public static function loadNumberFor(string $dateSlash, array $header, ?int $customerId): int
    {
        $existing = DB::connection('bil')->table('sales_loading as l')
            ->leftJoin('sales_order_details as d', 'l.sod_id', '=', 'd.id')
            ->leftJoin('sales_order as o', 'd.orderid', '=', 'o.orderid')
            ->where('l.dateofloading', $dateSlash)
            ->where('l.trucknumber', $header['trucknumber'] ?? '')
            ->where('l.loader', $header['loader'] ?? '')
            ->where('l.truckdriver', $header['truckdriver'] ?? '')
            ->when($customerId, fn ($q) => $q->where('o.customerid', $customerId))
            ->orderByDesc('l.id')
            ->value('l.loadnumber');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::connection('bil')->table('sales_loading')
            ->where('dateofloading', $dateSlash)->max('loadnumber') + 1;
    }

    /** `{yy}-{mm}-{dd}-{letter}{code}L-{nnn}` — the legacy format, exactly. */
    public static function barcodeFor(string $dateSlash, string $warehouseCode, int $loadNumber): string
    {
        [$y, $m, $d] = array_pad(explode('/', $dateSlash), 3, '00');
        $int = (string) (int) $warehouseCode;
        $letter = self::WAREHOUSE_LETTER[$int] ?? 'L';

        return sprintf('%s-%s-%s-%s%s%s-%03d',
            substr($y, -2), $m, $d, $letter, $int, self::LOADING_CHAR, $loadNumber);
    }

    /**
     * Put lines on a truck. Returns the load's barcode.
     *
     * One transaction: a load whose header saved but whose lines did not would
     * be a barcode with nothing on it, and its load number already spent.
     */
    public static function create(array $header, array $lines, string $dateSlash, ?int $customerId): string
    {
        $user = auth()->user();
        $username = (string) ($user?->username ?? $user?->name ?? 'gds');

        return DB::connection('bil')->transaction(function () use ($header, $lines, $dateSlash, $customerId, $username) {
            $loadNumber = self::loadNumberFor($dateSlash, $header, $customerId);
            $barcode = self::barcodeFor($dateSlash, (string) ($header['warehousecode'] ?? '1'), $loadNumber);
            $now = time();

            foreach ($lines as $line) {
                $qty = (int) ($line['quantity'] ?? 0);
                $sodId = (int) ($line['sod_id'] ?? 0);

                if ($qty <= 0 || $sodId <= 0) {
                    continue;
                }

                SalesLoading::create([
                    'loadnumber' => $loadNumber,
                    'loader' => $header['loader'],
                    'barcode' => $barcode,
                    'username' => $username,
                    'sod_id' => $sodId,
                    'cageroomcode' => $header['cageroomcode'],
                    'transporterid' => $header['transporterid'],
                    'trucknumber' => $header['trucknumber'],
                    'truckdriver' => $header['truckdriver'],
                    'quantityloaded' => $qty,
                    'dateofloading' => $dateSlash,
                    'timestamp' => $now,
                    'sales_loading_customerid' => (string) ($customerId ?? ''),
                ]);
            }

            return $barcode;
        });
    }

    /* ---------------- Modification ---------------- */

    /**
     * Change the load's header. Applies to EVERY line of the barcode, because
     * the truck and its crew belong to the load, not to one product.
     */
    public static function updateHeader(string $barcode, array $header): bool
    {
        if (! self::isOpen($barcode)) {
            return false;
        }

        SalesLoading::forLoad($barcode)->update([
            'transporterid' => $header['transporterid'],
            'cageroomcode' => $header['cageroomcode'],
            'loader' => $header['loader'],
            'trucknumber' => $header['trucknumber'],
            'truckdriver' => $header['truckdriver'],
        ]);

        return true;
    }

    /**
     * Correct what a line actually carries.
     *
     * Setting a new quantity CLEARS that line's returns, exactly as the legacy
     * screen did: the corrected figure is the whole truth about the line, so an
     * old return left behind would be counted against stock twice.
     */
    public static function correctLine(int $lineId, int $quantity): bool
    {
        $line = SalesLoading::find($lineId);

        if (! $line || ! $line->isOpen() || $quantity < 0) {
            return false;
        }

        $user = auth()->user();

        DB::connection('bil')->transaction(function () use ($line, $quantity, $user) {
            SalesLoadingReturn::where('barcode', $line->barcode)
                ->where('sod_id', $line->sod_id)->delete();

            $line->update([
                'quantityloaded' => $quantity,
                'username' => (string) ($user?->username ?? $user?->name ?? 'gds'),
                'timestamp' => time(),
            ]);
        });

        return true;
    }

    /** Take a line off the load entirely, with any returns against it. */
    public static function removeLine(int $lineId): bool
    {
        $line = SalesLoading::find($lineId);

        if (! $line || ! $line->isOpen()) {
            return false;
        }

        DB::connection('bil')->transaction(function () use ($line) {
            SalesLoadingReturn::where('barcode', $line->barcode)
                ->where('sod_id', $line->sod_id)->delete();
            $line->delete();
        });

        return true;
    }

    /* ---------------- Returns ---------------- */

    /**
     * Take goods back off the truck.
     *
     * Recorded as a return AND taken off the line's stored quantity, because
     * that quantity is net — the two together are what makes loaded + returned
     * equal what originally went on. Capped at what is on the line: a return
     * cannot take off more than was loaded.
     */
    public static function recordReturn(int $lineId, int $quantity): bool
    {
        $line = SalesLoading::find($lineId);

        if (! $line || ! $line->isOpen() || $quantity <= 0) {
            return false;
        }

        $quantity = min($quantity, (int) $line->quantityloaded);

        if ($quantity <= 0) {
            return false;
        }

        $user = auth()->user();

        DB::connection('bil')->transaction(function () use ($line, $quantity, $user) {
            SalesLoadingReturn::create([
                'barcode' => $line->barcode,
                'username' => (string) ($user?->username ?? $user?->name ?? 'gds'),
                'loading_id' => $line->id,
                'sod_id' => $line->sod_id,
                'quantityunloaded' => $quantity,
                'timestamp' => time(),
            ]);

            $line->update([
                'quantityloaded' => (int) $line->quantityloaded - $quantity,
                'timestamp' => time(),
            ]);
        });

        return true;
    }

    /** Undo every return on a line, putting the goods back on the truck. */
    public static function undoReturns(int $lineId): bool
    {
        $line = SalesLoading::find($lineId);

        if (! $line || ! $line->isOpen()) {
            return false;
        }

        DB::connection('bil')->transaction(function () use ($line) {
            $returned = (int) SalesLoadingReturn::where('barcode', $line->barcode)
                ->where('sod_id', $line->sod_id)->sum('quantityunloaded');

            if ($returned <= 0) {
                return;
            }

            SalesLoadingReturn::where('barcode', $line->barcode)
                ->where('sod_id', $line->sod_id)->delete();

            $line->update([
                'quantityloaded' => (int) $line->quantityloaded + $returned,
                'timestamp' => time(),
            ]);
        });

        return true;
    }

    /* ---------------- Print-outs ---------------- */

    /**
     * The loads on a day, for the Print Outs list.
     *
     * Reproduces the legacy includes/sales_loading_print.php: one row per load
     * number, newest first, with the transporter, the customer and whether it
     * has been delivered. That list is how the cageroom prints a day's work —
     * they open several and print them in a run — so it stays a flat list of
     * everything on the date rather than the open-only working queue.
     *
     * `sales_loading_customerid` is denormalised onto the loading row, which is
     * how the legacy list got the customer without the order chain. It is used
     * here for the same reason, and it sidesteps the utf8mb3/latin1 join.
     */
    public static function printoutList(string $dateSlash): array
    {
        return DB::connection('bil')->table('sales_loading as l')
            ->leftJoin('sales_transporters as t', 'l.transporterid', '=', 't.id')
            ->leftJoin('sales_customers as c', 'l.sales_loading_customerid', '=', 'c.id')
            ->where('l.dateofloading', $dateSlash)
            ->groupBy('l.barcode')
            ->selectRaw('l.barcode, MAX(l.loadnumber) as loadnumber,
                         MAX(t.transportername) as transportername,
                         MAX(c.customername) as customername,
                         MAX(l.trucknumber) as trucknumber, MAX(l.truckdriver) as truckdriver,
                         MAX(l.loader) as loader, MAX(l.dateofloading) as dateofloading,
                         MAX(l.status) as status,
                         COUNT(*) as line_count, SUM(l.quantityloaded) as loaded')
            ->orderByDesc('loadnumber')
            ->get()->all();
    }

    /**
     * Everything needed to print one or more loads.
     *
     * Returns one entry per barcode: the header the legacy printout showed
     * (customer and address, the sales orders it draws on, warehouse, cage
     * rooms, loader, transporter, vehicle and driver) and its lines.
     *
     * Built for a SET of barcodes in a fixed number of queries rather than one
     * load at a time, because printing a day's work in one go is the whole
     * point of the screen it is reached from.
     */
    public static function printouts(array $barcodes): array
    {
        $barcodes = array_values(array_filter(array_unique($barcodes)));

        if ($barcodes === []) {
            return [];
        }

        $rows = DB::connection('bil')->table('sales_loading as l')
            ->leftJoin('sales_order_details as d', 'l.sod_id', '=', 'd.id')
            ->leftJoin('products as p', 'd.productid', '=', 'p.productid')
            ->leftJoin('sales_transporters as t', 'l.transporterid', '=', 't.id')
            ->whereIn('l.barcode', $barcodes)
            ->orderBy('l.barcode')->orderBy('p.productname')
            ->get([
                'l.id', 'l.barcode', 'l.loadnumber', 'l.dateofloading', 'l.quantityloaded',
                'l.cageroomcode', 'l.loader', 'l.trucknumber', 'l.truckdriver', 'l.status',
                'l.sales_loading_customerid as customerid', 't.transportername',
                'd.orderid', 'd.foc', 'd.productid', 'p.productname', 'p.productcode',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        // Cageroom code -> name, from the gates the codes were imported as.
        $cagerooms = DB::connection('core')->table('warehouse_gates')
            ->whereIn('legacy_name', $rows->pluck('cageroomcode')->filter()->unique()->all() ?: ['~none~'])
            ->pluck('name', 'legacy_name');

        // The warehouse a load left from, via the order's warehousecode.
        $orderIds = $rows->pluck('orderid')->filter()->unique()->all();
        $orders = $orderIds === [] ? collect() : DB::connection('bil')->table('sales_order')
            ->whereIn('orderid', $orderIds)->pluck('warehousecode', 'orderid');
        $warehouses = DB::connection('bil')->table('sales_warehouse')
            ->pluck('warehouselocation', 'warehousecode');

        $customers = DB::connection('bil')->table('sales_customers')
            ->whereIn('id', $rows->pluck('customerid')->filter()->unique()->all() ?: [0])
            ->get(['id', 'customername', 'customeraddress'])->keyBy('id');

        $out = [];

        foreach ($rows->groupBy('barcode') as $barcode => $lines) {
            $first = $lines->first();
            $customer = $first->customerid ? ($customers[$first->customerid] ?? null) : null;

            $orderList = $lines->pluck('orderid')->filter()->unique()->values()->all();
            $warehouse = collect($orderList)
                ->map(fn ($o) => $warehouses[$orders[$o] ?? ''] ?? null)
                ->filter()->unique()->implode(', ');

            $out[] = (object) [
                'barcode' => $barcode,
                'loadnumber' => $first->loadnumber,
                'dateofloading' => $first->dateofloading,
                'status' => $first->status,
                'customername' => $customer->customername ?? null,
                'customeraddress' => $customer->customeraddress ?? null,
                'orders' => $orderList,
                'warehouse' => $warehouse ?: null,
                'cagerooms' => $lines->pluck('cageroomcode')->filter()->unique()
                    ->map(fn ($c) => $cagerooms[$c] ?? $c)->values()->all(),
                'loader' => $first->loader,
                'transportername' => $first->transportername,
                'trucknumber' => $first->trucknumber,
                'truckdriver' => $first->truckdriver,
                'lines' => self::printLines($lines),
                'total' => (int) $lines->sum('quantityloaded'),
            ];
        }

        // Print in the order the operator picked them off the list.
        usort($out, fn ($a, $b) => array_search($a->barcode, $barcodes, true)
            <=> array_search($b->barcode, $barcodes, true));

        return $out;
    }

    /**
     * The print-out's product rows: ONE PER PRODUCT, quantities summed.
     *
     * An order can carry the same product twice — the sold line and a free-of-
     * charge one — and each becomes its own `sales_loading` row. The truck
     * carries one quantity of that product, so the sheet shows one line, which
     * is what the legacy printout did: SUM(quantityloaded) GROUP BY productid,
     * ordered by product name, with `foc` not selected at all.
     *
     * FOC is still flagged, but only when the WHOLE quantity is free of charge.
     * Marking a merged row that is mostly sold would misstate what is owed, and
     * that is the one thing this sheet must not do.
     */
    protected static function printLines($lines): array
    {
        return $lines->groupBy('productid')
            ->map(fn ($group) => (object) [
                'productid' => $group->first()->productid,
                'productcode' => $group->first()->productcode,
                'productname' => $group->first()->productname,
                'quantityloaded' => (int) $group->sum('quantityloaded'),
                'foc' => $group->every(fn ($l) => (int) $l->foc === 1),
            ])
            // Case-INSENSITIVELY, to match the collation MySQL sorted the
            // legacy sheet with: "Rose of Africa" sorts before "Rose Plus"
            // there, and after it under PHP's byte order. The printed row order
            // is what operators scan down, so it stays as it was.
            ->sortBy(fn ($l) => mb_strtolower((string) $l->productname))
            ->values()->all();
    }

    /* ---------------- Helpers ---------------- */

    /** Open = the load exists and no line of it has been closed off. */
    public static function isOpen(string $barcode): bool
    {
        return SalesLoading::forLoad($barcode)->exists()
            && SalesLoading::forLoad($barcode)->whereNotNull('status')->doesntExist();
    }

    /** Cagerooms the user may load from, as out-gates on their warehouses. */
    public static function cagerooms(?array $warehouseIds = null): array
    {
        return DB::connection('core')->table('warehouse_gates')
            ->where('direction', 'out')
            ->where('legacy_name', 'like', 'CGR%')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouseIds ?: [0]))
            ->orderBy('sort_order')
            ->pluck('name', 'legacy_name')->all();
    }

    public static function transporters(): array
    {
        return DB::connection('bil')->table('sales_transporters')
            ->orderBy('transportername')->pluck('transportername', 'id')->all();
    }

    /**
     * Truck numbers already used, for type-ahead.
     *
     * 90 days rather than a year: DISTINCT over a varchar cannot use an index,
     * so the window is the cost, and a year of it was 216ms for a datalist.
     * Ninety days is also the better list — it is the fleet actually running.
     */
    public static function recentTrucks(int $limit = 400): array
    {
        return DB::connection('bil')->table('sales_loading')
            ->where('dateofloading', '>=', now()->subDays(90)->format('Y/m/d'))
            ->whereNotNull('trucknumber')->where('trucknumber', '<>', '')
            ->distinct()->orderBy('trucknumber')->limit($limit)
            ->pluck('trucknumber')->all();
    }

    /** Drivers already used, for type-ahead. See recentTrucks() on the window. */
    public static function recentDrivers(int $limit = 400): array
    {
        return DB::connection('bil')->table('sales_loading')
            ->where('dateofloading', '>=', now()->subDays(90)->format('Y/m/d'))
            ->whereNotNull('truckdriver')->where('truckdriver', '<>', '')
            ->distinct()->orderBy('truckdriver')->limit($limit)
            ->pluck('truckdriver')->all();
    }
}
