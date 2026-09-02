<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;
use Modules\Bil\Models\SalesReturn;

/**
 * Goods coming back from a customer, after they were delivered.
 *
 * NOT the same thing as `sales_loading_return`, which is goods coming back off
 * a truck in the cage room before it ever left. That is a correction to a load;
 * this is a customer sending goods back days or weeks later, and the two have
 * separate tables, screens and stock effects.
 *
 * WHY THIS SCREEN IS SHAPED DIFFERENTLY FROM LOADING AND DELIVERY. Those start
 * from a load, because a load is the thing in front of you. A return does not
 * arrive with a load number on it — a customer sends back bundles of a product,
 * and which delivery they came off is something the office has to work out. So
 * entry starts where the event starts: CUSTOMER, then PRODUCT, then HOW MANY,
 * and only then which order it is booked against.
 *
 * THE ORDER LINE STILL HAS TO BE CHOSEN, because `sales_return.sod_id` is a
 * sales-order-detail id — that is the only handle the table has, and the
 * printout, the reports and the legacy screens all read through it.
 * eligibleLines() offers the customer's deliveries of that product with what is
 * left returnable on each, newest first.
 *
 * Measured before building: across 366 return lines since 2024, a single
 * delivery always had enough left on it, so one order is picked in the ordinary
 * case. splitAcross() exists for the day that is not true, because refusing to
 * record a real return is worse than a second row.
 *
 * STOCK. Returned-minus-rejected goes back into sellable stock; the rejected
 * part goes to the damaged-goods warehouse. Both are DERIVED by
 * FinishedGoodsStock from this table, so nothing here writes a stock movement.
 * The legacy contradicted itself here — its floor update added the whole return
 * back (and, on a second return, the running total again), while the
 * stock_update() beside it added only the difference.
 */
class SalesReturns
{
    /** How far back the customer/product pickers look. The legacy used a year. */
    public const LOOKBACK_DAYS = 365;

    /* ---------------- The queue ---------------- */

    /**
     * Returns already recorded, newest first — one row per RETURN, not per line.
     *
     * A return is (customer, date, return number): the number is shared by
     * every line the customer sent back that day, which is what the printout
     * prints and what the legacy grouped on.
     */
    public static function recent(?string $search = null, int $limit = 100): array
    {
        $rows = DB::connection('bil')->table('sales_return as r')
            ->join('sales_order_details as d', 'r.sod_id', '=', 'd.id')
            ->groupBy('r.dateofreturn', 'r.returnnumber')
            ->selectRaw('r.dateofreturn, r.returnnumber,
                         COUNT(*) as line_count,
                         SUM(r.quantityreturned) as returned,
                         SUM(r.quantityrejected) as rejected,
                         MAX(r.id) as last_id, MAX(r.username) as username')
            ->orderByDesc('r.dateofreturn')->orderByDesc('r.returnnumber')
            ->limit($limit)->get()->all();

        $rows = self::decorate($rows);

        if ($search) {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower(
                $r->dateofreturn . ' ' . $r->returnnumber . ' ' . ($r->customername ?? '')
            ), $needle)));
        }

        return $rows;
    }

    /** Every return on a date — the Print Outs list. */
    public static function returnsOn(string $dateSlash): array
    {
        return self::decorate(DB::connection('bil')->table('sales_return as r')
            ->where('r.dateofreturn', $dateSlash)
            ->groupBy('r.dateofreturn', 'r.returnnumber')
            ->selectRaw('r.dateofreturn, r.returnnumber,
                         COUNT(*) as line_count,
                         SUM(r.quantityreturned) as returned,
                         SUM(r.quantityrejected) as rejected,
                         MAX(r.id) as last_id, MAX(r.username) as username')
            ->orderByDesc('r.returnnumber')->get()->all());
    }

    /**
     * Fill in customer and warehouse for a set of returns.
     *
     * Resolved after the query rather than joined: `sales_order.orderid` is
     * latin1 where `sales_order_details.orderid` is utf8mb3, and MySQL cannot
     * use an index across that mismatch.
     */
    private static function decorate(array $returns): array
    {
        if ($returns === []) {
            return [];
        }

        $keys = array_map(fn ($r) => $r->dateofreturn . '|' . $r->returnnumber, $returns);

        // One read for every line of every listed return, then grouped in PHP.
        $lines = DB::connection('bil')->table('sales_return as r')
            ->join('sales_order_details as d', 'r.sod_id', '=', 'd.id')
            ->whereIn('r.dateofreturn', array_unique(array_map(fn ($r) => $r->dateofreturn, $returns)))
            ->get(['r.dateofreturn', 'r.returnnumber', 'd.orderid'])
            ->groupBy(fn ($l) => $l->dateofreturn . '|' . $l->returnnumber);

        $orderIds = $lines->flatten(1)->pluck('orderid')->filter()->unique()->all();

        $orders = $orderIds === [] ? collect() : DB::connection('bil')->table('sales_order')
            ->whereIn('orderid', $orderIds)->get(['orderid', 'customerid', 'warehousecode'])
            ->keyBy('orderid');

        $customers = $orders->isEmpty() ? collect() : DB::connection('bil')->table('sales_customers')
            ->whereIn('id', $orders->pluck('customerid')->filter()->unique()->all())
            ->get(['id', 'customername', 'customeraddress'])->keyBy('id');

        $warehouses = DB::connection('bil')->table('sales_warehouse')
            ->pluck('warehouselocation', 'warehousecode');

        foreach ($returns as $r) {
            $mine = $lines[$r->dateofreturn . '|' . $r->returnnumber] ?? collect();
            $ids = $mine->pluck('orderid')->filter()->unique()->values()->all();
            $order = $ids === [] ? null : ($orders[$ids[0]] ?? null);
            $customer = $order && $order->customerid ? ($customers[$order->customerid] ?? null) : null;

            $r->orders = $ids;
            $r->customerid = $order->customerid ?? null;
            $r->customername = $customer->customername ?? null;
            $r->customeraddress = $customer->customeraddress ?? null;
            $r->warehouse = $order ? ($warehouses[$order->warehousecode] ?? null) : null;
            $r->returned = (int) $r->returned;
            $r->rejected = (int) $r->rejected;
            $r->line_count = (int) $r->line_count;
        }

        unset($keys);

        return $returns;
    }

    /* ---------------- One return ---------------- */

    public static function find(string $dateSlash, int $returnNumber): ?object
    {
        $rows = self::decorate(DB::connection('bil')->table('sales_return as r')
            ->where('r.dateofreturn', $dateSlash)->where('r.returnnumber', $returnNumber)
            ->groupBy('r.dateofreturn', 'r.returnnumber')
            ->selectRaw('r.dateofreturn, r.returnnumber,
                         COUNT(*) as line_count,
                         SUM(r.quantityreturned) as returned,
                         SUM(r.quantityrejected) as rejected,
                         MAX(r.id) as last_id, MAX(r.username) as username')
            ->get()->all());

        return $rows[0] ?? null;
    }

    /** The lines of one return, with the order each was booked against. */
    public static function lines(string $dateSlash, int $returnNumber): array
    {
        return DB::connection('bil')->table('sales_return as r')
            ->leftJoin('sales_order_details as d', 'r.sod_id', '=', 'd.id')
            ->leftJoin('products as p', 'd.productid', '=', 'p.productid')
            ->where('r.dateofreturn', $dateSlash)->where('r.returnnumber', $returnNumber)
            ->orderBy('p.productname')
            ->get([
                'r.id', 'r.sod_id', 'r.quantityreturned', 'r.quantityrejected',
                'r.dateofreturn', 'r.returnnumber', 'r.username',
                'd.orderid', 'd.productid', 'd.foc', 'p.productname', 'p.productcode',
            ])
            ->map(function ($l) {
                $l->quantityreturned = (int) $l->quantityreturned;
                $l->quantityrejected = (int) $l->quantityrejected;
                // What actually went back into sellable stock.
                $l->to_stock = $l->quantityreturned - $l->quantityrejected;

                return $l;
            })->all();
    }

    /* ---------------- Choosing what a return comes off ---------------- */

    /** Customers who have ordered within the lookback window. */
    public static function customers(): array
    {
        $since = now()->subDays(self::LOOKBACK_DAYS)->format('Y/m/d');

        return DB::connection('bil')->table('sales_order as o')
            ->join('sales_customers as c', 'o.customerid', '=', 'c.id')
            ->where('o.dateoforder', '>=', $since)
            ->distinct()->orderBy('c.customername')
            ->pluck('c.customername', 'c.id')->all();
    }

    /**
     * Products this customer has actually had DELIVERED, within the window.
     *
     * Only delivered ones: you cannot send back what never arrived, and the
     * legacy picker made the same restriction by joining `sales_loading`.
     */
    public static function productsFor(int $customerId): array
    {
        $since = now()->subDays(self::LOOKBACK_DAYS)->format('Y/m/d');

        $orderIds = DB::connection('bil')->table('sales_order')
            ->where('customerid', $customerId)->where('dateoforder', '>=', $since)
            ->pluck('orderid')->all();

        if ($orderIds === []) {
            return [];
        }

        return DB::connection('bil')->table('sales_order_details as d')
            ->join('sales_loading as l', 'l.sod_id', '=', 'd.id')
            ->join('products as p', 'd.productid', '=', 'p.productid')
            ->whereIn('d.orderid', $orderIds)
            ->whereNotNull('l.status')
            ->distinct()->orderBy('p.productname')
            ->pluck('p.productname', 'p.productid')->all();
    }

    /**
     * The customer's deliveries of one product, with what is still returnable.
     *
     * `remaining` = delivered minus everything already returned against that
     * order line. Delivered means a load that was CONFIRMED — `status IS NOT
     * NULL` — because goods still on a truck have not reached the customer and
     * cannot be coming back from them.
     *
     * Newest delivery first: a customer sending goods back is usually sending
     * back their most recent delivery, and the legacy picker sorted the same
     * way.
     */
    public static function eligibleLines(int $customerId, int $productid): array
    {
        $since = now()->subDays(self::LOOKBACK_DAYS)->format('Y/m/d');

        $orders = DB::connection('bil')->table('sales_order')
            ->where('customerid', $customerId)->where('dateoforder', '>=', $since)
            ->pluck('dateoforder', 'orderid');

        if ($orders->isEmpty()) {
            return [];
        }

        $details = DB::connection('bil')->table('sales_order_details as d')
            ->whereIn('d.orderid', $orders->keys()->all())
            ->where('d.productid', $productid)
            ->get(['d.id', 'd.orderid', 'd.quantityordered', 'd.foc']);

        if ($details->isEmpty()) {
            return [];
        }

        $ids = $details->pluck('id')->all();

        $delivered = DB::connection('bil')->table('sales_loading')
            ->whereIn('sod_id', $ids)->whereNotNull('status')
            ->groupBy('sod_id')->selectRaw('sod_id, SUM(quantityloaded) as qty')
            ->pluck('qty', 'sod_id');

        $returned = DB::connection('bil')->table('sales_return')
            ->whereIn('sod_id', $ids)
            ->groupBy('sod_id')->selectRaw('sod_id, SUM(quantityreturned) as qty')
            ->pluck('qty', 'sod_id');

        // The delivery date, so the operator can recognise the consignment —
        // an order id alone means little once a customer has twenty of them.
        $deliveredOn = DB::connection('bil')->table('sales_loading')
            ->whereIn('sod_id', $ids)->whereNotNull('status')
            ->groupBy('sod_id')->selectRaw('sod_id, MAX(status) as last_delivery')
            ->pluck('last_delivery', 'sod_id');

        return $details->map(function ($d) use ($delivered, $returned, $deliveredOn, $orders) {
            $d->delivered = (int) ($delivered[$d->id] ?? 0);
            $d->returned = (int) ($returned[$d->id] ?? 0);
            $d->remaining = max(0, $d->delivered - $d->returned);
            $d->dateoforder = (string) ($orders[$d->orderid] ?? '');
            $d->last_delivery = (string) ($deliveredOn[$d->id] ?? '');

            return $d;
        })
            ->filter(fn ($d) => $d->delivered > 0)
            ->sortByDesc(fn ($d) => $d->last_delivery ?: $d->dateoforder)
            ->values()->all();
    }

    /**
     * Spread a quantity over eligible lines, newest first.
     *
     * The fallback for the day no single delivery covers the return. Returns
     * [sod_id => quantity]; anything it could not place is left over and the
     * caller refuses the save rather than booking a short return.
     */
    public static function splitAcross(array $eligible, int $quantity): array
    {
        $out = [];

        foreach ($eligible as $line) {
            if ($quantity <= 0) {
                break;
            }

            $take = min($quantity, (int) $line->remaining);

            if ($take > 0) {
                $out[(int) $line->id] = $take;
                $quantity -= $take;
            }
        }

        return $out;
    }

    /* ---------------- Numbering ---------------- */

    /**
     * The return number for a customer on a day.
     *
     * Reuses the number already given to that customer on that date, so
     * everything they sent back in one go prints on one sheet, and otherwise
     * takes the next number for the day. The same shape as load numbers, and
     * reproduced from sales_return_request.php because the printout and the
     * legacy screens group on it.
     */
    public static function returnNumberFor(string $dateSlash, int $customerId): int
    {
        $existing = DB::connection('bil')->table('sales_return as r')
            ->join('sales_order_details as d', 'r.sod_id', '=', 'd.id')
            ->join('sales_order as o', 'd.orderid', '=', 'o.orderid')
            ->where('r.dateofreturn', $dateSlash)
            ->where('o.customerid', $customerId)
            ->orderByDesc('r.id')
            ->value('r.returnnumber');

        return $existing ? (int) $existing : self::nextReturnNumber($dateSlash);
    }

    /** Return numbers restart daily. */
    public static function nextReturnNumber(string $dateSlash): int
    {
        return (int) DB::connection('bil')->table('sales_return')
            ->where('dateofreturn', $dateSlash)->max('returnnumber') + 1;
    }

    /* ---------------- Writing ---------------- */

    /**
     * Record a return. `$lines` = [['sod_id' => int, 'returned' => int,
     * 'rejected' => int], …]. Returns ['ok', 'message', 'number'].
     *
     * One transaction over the whole basket: half a customer's return saved is
     * worse than none, because the number is already spent and the sheet would
     * print short.
     *
     * A SEPARATE ROW PER LINE, always. The legacy UPDATEd the existing row for
     * a `sod_id` with no date filter, so a return in August silently merged
     * into a March one and rewrote its date — which is why only 3 of 2,377
     * sod_ids in nine years carry two rows. Each return is its own record here.
     */
    public static function create(int $customerId, string $dateSlash, array $lines): array
    {
        $clean = [];

        foreach ($lines as $line) {
            $sodId = (int) ($line['sod_id'] ?? 0);
            $returned = (int) ($line['returned'] ?? 0);
            $rejected = (int) ($line['rejected'] ?? 0);

            if ($sodId <= 0 || $returned <= 0) {
                continue;
            }

            if ($rejected < 0 || $rejected > $returned) {
                return ['ok' => false, 'message' => 'Rejected cannot be more than returned.'];
            }

            $clean[] = ['sod_id' => $sodId, 'returned' => $returned, 'rejected' => $rejected];
        }

        if ($clean === []) {
            return ['ok' => false, 'message' => 'Nothing to return — enter a quantity on at least one line.'];
        }

        // Re-read what is returnable at SAVE time rather than trusting the page.
        // The screen may have been open a while, and another return against the
        // same delivery in the meantime would otherwise be booked twice.
        $check = self::verifyAgainstDeliveries($clean, $dateSlash);

        if (! $check['ok']) {
            return $check;
        }

        $user = auth()->user();
        $username = (string) ($user?->username ?? $user?->name ?? 'gds');

        $number = DB::connection('bil')->transaction(function () use ($clean, $customerId, $dateSlash, $username) {
            $number = self::returnNumberFor($dateSlash, $customerId);

            foreach ($clean as $line) {
                SalesReturn::create([
                    'username' => $username,
                    'returnnumber' => $number,
                    'sod_id' => $line['sod_id'],
                    'quantityreturned' => $line['returned'],
                    'quantityrejected' => $line['rejected'],
                    'dateofreturn' => $dateSlash,
                ]);
            }

            return $number;
        });

        return ['ok' => true, 'number' => $number,
            'message' => 'Return #' . $number . ' recorded with ' . count($clean) . ' line(s).'];
    }

    /**
     * Refuse a return of more than was delivered and not already sent back, and
     * a return dated before the order it is booked against.
     *
     * The legacy checked both in JavaScript only, so anything that reached the
     * request script directly was written unchecked.
     */
    private static function verifyAgainstDeliveries(array $lines, string $dateSlash, ?int $ignoreId = null): array
    {
        $ids = array_column($lines, 'sod_id');

        $delivered = DB::connection('bil')->table('sales_loading')
            ->whereIn('sod_id', $ids)->whereNotNull('status')
            ->groupBy('sod_id')->selectRaw('sod_id, SUM(quantityloaded) as qty')
            ->pluck('qty', 'sod_id');

        $returned = DB::connection('bil')->table('sales_return')
            ->whereIn('sod_id', $ids)
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '<>', $id))
            ->groupBy('sod_id')->selectRaw('sod_id, SUM(quantityreturned) as qty')
            ->pluck('qty', 'sod_id');

        $orderDates = DB::connection('bil')->table('sales_order_details as d')
            ->join('sales_order as o', 'd.orderid', '=', 'o.orderid')
            ->whereIn('d.id', $ids)
            ->pluck('o.dateoforder', 'd.id');

        foreach ($lines as $line) {
            $sodId = $line['sod_id'];
            $available = (int) ($delivered[$sodId] ?? 0) - (int) ($returned[$sodId] ?? 0);

            if ($line['returned'] > $available) {
                return ['ok' => false, 'message' =>
                    'Only ' . number_format(max(0, $available)) . ' left returnable on one of these deliveries.'];
            }

            $orderDate = (string) ($orderDates[$sodId] ?? '');

            if ($orderDate !== '' && $dateSlash < $orderDate) {
                return ['ok' => false, 'message' =>
                    'A return cannot be dated before the order it came from (' . $orderDate . ').'];
            }
        }

        return ['ok' => true];
    }

    /* ---------------- Modification ---------------- */

    /** Change one line's quantities. */
    public static function updateLine(int $id, int $returned, int $rejected): array
    {
        $line = SalesReturn::find($id);

        if (! $line) {
            return ['ok' => false, 'message' => 'That return line could not be found.'];
        }

        if ($returned <= 0) {
            return ['ok' => false, 'message' => 'Use remove to take a line off the return.'];
        }

        if ($rejected < 0 || $rejected > $returned) {
            return ['ok' => false, 'message' => 'Rejected cannot be more than returned.'];
        }

        $check = self::verifyAgainstDeliveries(
            [['sod_id' => (int) $line->sod_id, 'returned' => $returned, 'rejected' => $rejected]],
            (string) $line->dateofreturn,
            $id
        );

        if (! $check['ok']) {
            return $check;
        }

        $line->update(['quantityreturned' => $returned, 'quantityrejected' => $rejected]);

        return ['ok' => true, 'message' => 'Return line updated.'];
    }

    /** Take a line off a return. */
    public static function removeLine(int $id): array
    {
        $line = SalesReturn::find($id);

        if (! $line) {
            return ['ok' => false, 'message' => 'That return line could not be found.'];
        }

        $line->delete();

        return ['ok' => true, 'message' => 'Return line removed.'];
    }

    /* ---------------- Print-outs ---------------- */

    /**
     * Everything needed to print one or more returns.
     *
     * Keyed by "{date}|{number}" because that pair IS the return — there is no
     * id on the sheet, and the number alone repeats every day.
     */
    public static function printouts(array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            [$dateSlash, $number] = array_pad(explode('|', (string) $key), 2, null);

            if (! $dateSlash || $number === null) {
                continue;
            }

            $return = self::find($dateSlash, (int) $number);

            if (! $return) {
                continue;
            }

            $lines = self::lines($dateSlash, (int) $number);

            $return->key = $key;
            $return->lines = self::printLines($lines);
            $return->total_returned = array_sum(array_map(fn ($l) => $l->quantityreturned, $lines));
            $return->total_rejected = array_sum(array_map(fn ($l) => $l->quantityrejected, $lines));

            $out[] = $return;
        }

        return $out;
    }

    /**
     * One row per product on the sheet, quantities summed.
     *
     * The same rule as the loading and delivery sheets: a product booked
     * against two order lines is one thing coming back through the gate, and
     * sorted case-insensitively to match the collation MySQL printed the legacy
     * sheet with.
     */
    private static function printLines(array $lines): array
    {
        return collect($lines)->groupBy('productid')
            ->map(fn ($group) => (object) [
                'productid' => $group->first()->productid,
                'productcode' => $group->first()->productcode,
                'productname' => $group->first()->productname,
                'quantityreturned' => (int) $group->sum('quantityreturned'),
                'quantityrejected' => (int) $group->sum('quantityrejected'),
                'foc' => $group->every(fn ($l) => (int) $l->foc === 1),
            ])
            ->sortBy(fn ($l) => mb_strtolower((string) $l->productname))
            ->values()->all();
    }
}
