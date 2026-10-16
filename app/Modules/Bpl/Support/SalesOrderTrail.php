<?php

namespace Modules\Bpl\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Bil\Support\SalesOrderTrail as BilTrail;
use Modules\Core\Support\Prefs;

/**
 * Everything that ever happened to ONE BPL export order.
 *
 * The other sales reports answer "what happened in this period"; this answers
 * "what happened to this order", which is the question a customer asks on the
 * phone — and for an export order the answer is spread over seven tables:
 *
 *   order        bpl_sales + bpl_sales_items        the promise
 *   proforma     bpl_proforma + bpl_proforma_items  the quote
 *   approvals    core.actions (pfi_*)               customer, production, loading
 *   shipment     bpl_packing_list                   what went in the containers
 *   bill of lad. bpl_bill_of_lading                 issued, shipped, arrived
 *   payment      bpl_invoice_payments               what came back
 *
 * ## Finding the order: NINE ways in
 *
 * A BPL order is referred to by a different number depending on who is asking.
 * The shipping line quotes a bill of lading, the bank quotes an NXP, the
 * customer quotes their own purchase-order reference, the warehouse quotes a
 * container. Searching only the order number — which is what the BIL trail
 * does, because a BIL order has only one — would send the reader off to guess.
 *
 * So every identifier the chain carries is searched at once, and each hit SAYS
 * WHICH ONE MATCHED. See suggest() for the list.
 */
class SalesOrderTrail
{
    /** Order of the stages within one day, so a day reads as a narrative. */
    private const STAGE_RANK = [
        'order' => 0, 'proforma' => 1, 'approved' => 2, 'produced' => 3,
        'loaded' => 4, 'shipment' => 5, 'bl' => 6, 'arrival' => 7, 'payment' => 8,
    ];

    private static function db()
    {
        return DB::connection('bpl');
    }

    /**
     * Strip what rides along with a copy-paste — the non-breaking spaces and
     * zero-width characters that `trim()` does not know about, and a leading
     * '#'.
     *
     * Shared with the BIL trail rather than copied: the reason it exists (a
     * pasted number matching nothing, so the page says the order does not
     * exist) is identical, and two copies would drift.
     */
    public static function clean(?string $term): string
    {
        return BilTrail::clean($term);
    }

    /** A stored date in the reader's chosen format (Appearance → Date format). */
    public static function humanDate(?string $stored): string
    {
        $stored = trim((string) $stored);

        if ($stored === '' || str_starts_with($stored, '0000')) {
            return '';
        }

        try {
            return Carbon::parse($stored)->format(Prefs::dateFormat());
        } catch (\Throwable) {
            return $stored;
        }
    }

    /* ---------------- Finding the order ---------------- */

    /** The base query behind both find() and suggest(). */
    private static function orderQuery()
    {
        return self::db()->table('bpl_sales as s')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 's.id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->whereNull('s.deleted_at');
    }

    /** The number as the documents show it. */
    private static function numberExpr(): string
    {
        return 'COALESCE(`s`.`orderno`,'
            . " NULLIF(REPLACE(CONCAT('BPL/', `co`.`iso`, '/', `c`.`customerlabel`, '/', `s`.`ref`), ' ', ''), ''),"
            . " CONCAT('#', `s`.`ref`))";
    }

    /** One order and everything about it that does not change, or null. */
    public static function find($orderId): ?object
    {
        $orderId = self::clean((string) $orderId);

        if ($orderId === '') {
            return null;
        }

        $q = self::orderQuery()->selectRaw(
            '`s`.`id`, `s`.`ref`, `s`.`orderno`, `s`.`date`, `s`.`username`, `s`.`company`,'
            . ' ' . self::numberExpr() . ' as `number`,'
            . ' `c`.`id` as `customer_id`, `c`.`customername`, `c`.`customerlabel`,'
            . ' `c`.`customeraddress`, `c`.`customercountry`, `c`.`port`, `c`.`email`,'
            . ' `pf`.`date` as `proforma_date`, `pf`.`customer_ref`, `pf`.`nxp`,'
            . ' `pf`.`terms`, `pf`.`shipment`, `pf`.`container`, `pf`.`freight`, `pf`.`freight_price`,'
            . ' `cur`.`code` as `currency`, `cur`.`symbol`'
        );

        // Numeric means the internal id; anything else is an order number.
        return ctype_digit($orderId)
            ? $q->where('s.id', (int) $orderId)->first()
            : $q->where('s.orderno', $orderId)->first();
    }

    /**
     * Every way of naming a BPL order, and what each one is called when it
     * matches. The order here is the order the hits are grouped in.
     *
     *   key      => [label shown on the hit, how it reads]
     */
    public const WAYS = [
        'orderno' => 'Order number',
        'ref' => 'Order reference',
        // THE CODE COMES BEFORE THE NAME. It is matched from the start of a
        // short, deliberate identifier; the name is matched anywhere inside a
        // long piece of prose. 60 of BPL's 131 customers have a label that is
        // a substring of their own name (EVERPACK / "Everpack Ltd"), so with
        // the name first, typing the code was reported as "Customer" for
        // nearly half the customer base and "Customer code" could not be the
        // reason for any of them.
        'label' => 'Customer code',
        'customer' => 'Customer',
        'customer_ref' => 'Customer’s own reference',
        'nxp' => 'NXP number',
        'packing' => 'Packing list',
        'bl' => 'Bill of lading',
        'container' => 'Container',
    ];

    /**
     * Orders matching what was typed, across every identifier the chain
     * carries.
     *
     * Each way is its own query rather than one query with nine ORed LIKEs:
     * ORs across five joined tables cannot use an index and would scan the lot,
     * and — more to the point — a single query cannot say WHICH field matched,
     * which is the whole reason this searches widely. Nine small indexed
     * lookups, capped, then merged by order id keeping the first (most
     * specific) reason.
     *
     * @return array<int, object>  each with ->id ->number ->date ->customername ->matched ->matched_on
     */
    public static function suggest(string $term, int $limit = 15): array
    {
        $term = self::clean($term);

        if ($term === '' || mb_strlen($term) < 2) {
            return [];
        }

        $like = '%' . $term . '%';
        $hits = [];

        // An identifier is matched from the start where it has an index worth
        // using, and anywhere where it does not.
        $ways = [
            'orderno' => fn ($q) => $q->where('s.orderno', 'like', $term . '%'),
            'ref' => fn ($q) => $q->where('s.ref', $term),
            'label' => fn ($q) => $q->where('c.customerlabel', 'like', $term . '%'),
            'customer' => fn ($q) => $q->where('c.customername', 'like', $like),
            'customer_ref' => fn ($q) => $q->where('pf.customer_ref', 'like', $like),
            'nxp' => fn ($q) => $q->where('pf.nxp', 'like', $like),
            // A packing list is STORED as `BPL/SN/:19/122` and READ as
            // `BPL/SN/SAFT/122` — the `:customerid` placeholder is replaced with
            // the customer's label when the document prints. Nobody types the
            // stored form, so both are matched; `c` is the outer query's
            // customer, which is what makes the second one possible.
            'packing' => fn ($q) => $q->whereExists(fn ($w) => $w->selectRaw('1')
                ->from('bpl_packing_list as pl')->whereColumn('pl.order_id', 's.id')
                ->where(fn ($p) => $p->where('pl.number', 'like', $like)
                    ->orWhereRaw("REPLACE(`pl`.`number`, CONCAT(':', `c`.`id`), `c`.`customerlabel`) LIKE ?", [$like]))),
            'bl' => fn ($q) => $q->whereExists(fn ($w) => $w->selectRaw('1')
                ->from('bpl_packing_list as pl')
                ->join('bpl_bill_of_lading as b', 'b.packing_id', '=', 'pl.id')
                ->whereColumn('pl.order_id', 's.id')
                ->where('b.number', 'like', $like)),
            // The container numbers live inside the packing list's JSON, one
            // per element of the array — JSON_SEARCH walks it and takes the
            // same % wildcards a LIKE does.
            'container' => fn ($q) => $q->whereExists(fn ($w) => $w->selectRaw('1')
                ->from('bpl_packing_list as pl')->whereColumn('pl.order_id', 's.id')
                ->whereRaw("JSON_SEARCH(`pl`.`containers`, 'one', ?, NULL, '$[*].name') IS NOT NULL", [$like])),
        ];

        foreach ($ways as $key => $apply) {
            $q = self::orderQuery()->selectRaw(
                '`s`.`id`, `s`.`date`, ' . self::numberExpr() . ' as `number`,'
                . " COALESCE(`c`.`customername`, '—') as `customername`,"
                . " COALESCE(`c`.`customercountry`, '') as `country`"
            );

            $apply($q);

            foreach ($q->orderByDesc('s.date')->orderByDesc('s.id')->limit($limit + 1)->get() as $row) {
                // First reason wins: the ways are declared most-specific first,
                // so an order found by its own number is not relabelled
                // "customer" because the name also matched.
                if (isset($hits[$row->id])) {
                    continue;
                }

                $row->matched_on = $key;
                $row->matched = self::WAYS[$key];
                $hits[$row->id] = $row;
            }

            // An order number typed in full is the answer, and running the
            // other eight ways could only add rows that are less relevant than
            // the one already found.
            if ($key === 'orderno' && count($hits) === 1
                && strcasecmp((string) reset($hits)->number, $term) === 0) {
                break;
            }
        }

        $rows = array_values($hits);

        usort($rows, fn ($a, $b) => [$b->date, $b->id] <=> [$a->date, $a->id]);

        return $rows;
    }

    /* ---------------- The order's lines ---------------- */

    /**
     * The order's lines: what was asked for, what it was priced at, and what
     * has actually shipped against it.
     *
     * Shipped weight is matched on the DESCRIPTION, the only link a packing
     * list keeps back to a line — see SalesFigures for why, and for the nine
     * packing lists where that link is broken.
     */
    public static function lines(int $orderId): array
    {
        $rows = self::db()->table('bpl_sales_items as i')
            ->leftJoin('bpl_proforma_items as pi', 'pi.order_item_id', '=', 'i.id')
            ->leftJoin('bpl_products_hardroll as p', 'p.id', '=', 'i.productid')
            ->where('i.order_id', $orderId)
            ->orderBy('i.id')
            ->get(['i.id', 'i.weight', 'pi.price', 'p.productname', 'p.gradetype',
                'p.gsm', 'p.ply', 'p.width', 'p.diameter', 'p.slice']);

        $shipped = self::shippedByDescription($orderId);

        return $rows->map(function ($r) use ($shipped) {
            $description = ProformaInvoice::fullName($r);
            $out = (float) ($shipped[$description] ?? 0);

            return (object) [
                'id' => (int) $r->id,
                'productname' => (string) ($r->productname ?? '— product deleted —'),
                'description' => $description,
                'weight' => (float) $r->weight,
                'price' => $r->price !== null ? (float) $r->price : null,
                'amount' => $r->price !== null ? round((float) $r->price * (float) $r->weight / 1000, 2) : null,
                'shipped' => $out,
                'balance' => round((float) $r->weight - $out, 2),
            ];
        })->all();
    }

    /** Kilograms shipped for each goods description on an order. */
    private static function shippedByDescription(int $orderId): array
    {
        $out = [];

        foreach (self::db()->table('bpl_packing_list')
            ->where('order_id', $orderId)->where('split', '0')
            ->pluck('containers') as $json) {
            foreach (json_decode((string) $json, true) ?: [] as $container) {
                foreach ($container['goods'] ?? [] as $lot) {
                    $key = (string) ($lot['description'] ?? '');
                    $out[$key] = ($out[$key] ?? 0) + (float) ($lot['weight'] ?? 0);
                }
            }
        }

        return $out;
    }

    /* ---------------- The trail ---------------- */

    /**
     * Every transaction on one order, in the order it happened.
     *
     * Sorted by date, then by where the stage sits in the chain, so a day
     * reads proforma → approved → shipped rather than in whatever order the
     * rows were written.
     */
    public static function trail(object $order): array
    {
        $events = [];
        $money = fn (float $v) => ProformaInvoice::symbol((string) ($order->symbol ?? '')) . number_format($v, 2);

        $events[] = self::event('order', (string) $order->date, 'Ordered', (string) $order->number,
            self::orderDetail($order));

        if ($order->proforma_date) {
            $lines = self::lines((int) $order->id);
            $goods = array_sum(array_map(fn ($l) => (float) ($l->amount ?? 0), $lines));
            $freight = round((float) $order->container * (float) $order->freight_price, 2);

            $events[] = self::event('proforma', (string) $order->proforma_date, 'Proforma raised',
                ProformaInvoice::numberFor($order->number, $order->customer_ref),
                implode(' · ', array_filter([
                    $money($goods + $freight) . ' quoted',
                    $order->container ? (int) $order->container . ' × ' . $order->freight : null,
                    $order->terms ?: null,
                    $order->shipment ?: null,
                    $order->nxp ? 'NXP ' . $order->nxp : null,
                ])));
        }

        foreach (ProformaMilestones::forOrder((int) $order->id) as $stage => $action) {
            $events[] = self::event($stage,
                // The milestone is stored as a real timestamp, unlike every
                // other date here; it is reduced to a day so it sorts with them.
                self::stageDate($action['date']),
                ProformaMilestones::label($stage), '',
                'recorded by ' . ($action['user'] ?: 'unknown'));
        }

        foreach (self::shipments((int) $order->id) as $shipment) {
            $events[] = self::event('shipment', (string) $shipment->date, 'Shipped',
                PackingList::label($shipment->number, (int) ($order->customer_id ?? 0), $order->customerlabel),
                implode(' · ', array_filter([
                    (int) $shipment->containers . ' ' . ($shipment->containers == 1 ? 'container' : 'containers'),
                    number_format((float) $shipment->net_weight / 1000, 3) . ' t',
                    (int) $shipment->rolls . ' rolls',
                    $shipment->goods_value > 0 ? $money((float) $shipment->goods_value) . ' invoiced' : null,
                ])), (float) $shipment->goods_value);

            if ($shipment->bl_number) {
                $events[] = self::event('bl', (string) ($shipment->issued_date ?: $shipment->date),
                    'Bill of lading', (string) $shipment->bl_number,
                    implode(' · ', array_filter([
                        $shipment->shipped_date ? 'sailed ' . self::humanDate($shipment->shipped_date) : null,
                        $shipment->arrival_date ? 'due ' . self::humanDate($shipment->arrival_date) : null,
                    ])) ?: 'issued');

                if ($shipment->arrival_date) {
                    $events[] = self::event('arrival', (string) $shipment->arrival_date, 'Arrived',
                        (string) $shipment->bl_number, 'at ' . ($order->port ?: 'the destination port'));
                }
            }

            foreach (self::payments((int) $shipment->id) as $payment) {
                $events[] = self::event('payment', (string) $payment->date, 'Payment received',
                    PackingList::label($shipment->number, (int) ($order->customer_id ?? 0), $order->customerlabel),
                    $money((float) $payment->amount) . ' received',
                    (float) $payment->amount);
            }
        }

        usort($events, fn ($a, $b) => [$a['date'], self::STAGE_RANK[$a['stage']] ?? 99]
            <=> [$b['date'], self::STAGE_RANK[$b['stage']] ?? 99]);

        return $events;
    }

    /** The shipments raised against an order, with their bill of lading. */
    public static function shipments(int $orderId)
    {
        return self::db()->table('bpl_packing_list as pl')
            ->joinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_bill_of_lading as bl', 'bl.packing_id', '=', 'pl.id')
            ->where('pl.order_id', $orderId)
            ->orderBy('pl.date')->orderBy('pl.id')
            ->get(['pl.id', 'pl.number', 'pl.date', 'f.containers', 'f.rolls', 'f.net_weight',
                'f.gross_weight', 'f.goods_value', 'f.unpriced_weight',
                'bl.number as bl_number', 'bl.issued_date', 'bl.shipped_date', 'bl.arrival_date']);
    }

    private static function payments(int $packingId)
    {
        return self::db()->table('bpl_invoice_payments')
            ->where('packing_id', $packingId)->orderBy('date')->orderBy('id')
            ->get(['date', 'amount']);
    }

    /* ---------------- Where the order has got to ---------------- */

    /**
     * The chain as a set of steps, each done or not — the strip across the top
     * of the page. A step with no date is one that has not happened.
     *
     * @return array<int, array{key:string,label:string,date:?string,done:bool}>
     */
    public static function progress(object $order): array
    {
        $milestones = ProformaMilestones::forOrder((int) $order->id);
        $shipments = self::shipments((int) $order->id);
        $first = $shipments->first();

        $paid = 0.0;
        $invoiced = 0.0;

        foreach ($shipments as $shipment) {
            $invoiced += (float) $shipment->goods_value
                + (float) $shipment->containers * (float) $order->freight_price;
            $paid += (float) self::db()->table('bpl_invoice_payments')
                ->where('packing_id', $shipment->id)->sum('amount');
        }

        $bl = $shipments->firstWhere('bl_number', '!=', null);

        return [
            self::step('order', 'Ordered', (string) $order->date),
            self::step('proforma', 'Proforma', $order->proforma_date),
            self::step('approved', 'Customer approved',
                isset($milestones[ProformaMilestones::APPROVED])
                    ? self::stageDate($milestones[ProformaMilestones::APPROVED]['date']) : null),
            self::step('produced', 'Produced',
                isset($milestones[ProformaMilestones::PRODUCED])
                    ? self::stageDate($milestones[ProformaMilestones::PRODUCED]['date']) : null),
            self::step('loaded', 'Loaded',
                isset($milestones[ProformaMilestones::LOADED])
                    ? self::stageDate($milestones[ProformaMilestones::LOADED]['date']) : null),
            self::step('shipment', 'Shipped', $first->date ?? null),
            self::step('bl', 'Bill of lading', $bl->issued_date ?? null),
            // Paid is the only step that is not a date: it is a comparison, and
            // a tolerance of one unit because a payment entered to the kobo
            // against a computed invoice will not land exactly.
            self::step('payment', 'Paid',
                $invoiced > 0 && $paid >= $invoiced - 1 ? 'yes' : null),
        ];
    }

    private static function step(string $key, string $label, ?string $date): array
    {
        $date = trim((string) $date);

        return [
            'key' => $key,
            'label' => $label,
            'date' => $date !== '' && $date !== 'yes' ? $date : null,
            'done' => $date !== '',
        ];
    }

    /** The whole order's figures, for the header. */
    public static function totals(object $order): array
    {
        $lines = self::lines((int) $order->id);
        $shipments = self::shipments((int) $order->id);

        $paid = 0.0;
        foreach ($shipments as $shipment) {
            $paid += (float) self::db()->table('bpl_invoice_payments')
                ->where('packing_id', $shipment->id)->sum('amount');
        }

        $invoiced = 0.0;
        foreach ($shipments as $shipment) {
            $invoiced += (float) $shipment->goods_value
                + (float) $shipment->containers * (float) $order->freight_price;
        }

        return [
            'lines' => count($lines),
            'ordered' => array_sum(array_map(fn ($l) => $l->weight, $lines)),
            'shipped' => array_sum(array_map(fn ($l) => $l->shipped, $lines)),
            'quoted' => round(array_sum(array_map(fn ($l) => (float) ($l->amount ?? 0), $lines))
                + (float) $order->container * (float) $order->freight_price, 2),
            'invoiced' => round($invoiced, 2),
            'paid' => round($paid, 2),
            'outstanding' => round($invoiced - $paid, 2),
            'containers' => (int) $shipments->sum('containers'),
            'shipments' => $shipments->count(),
        ];
    }

    /* ---------------- Helpers ---------------- */

    /** A milestone's 'd/m/Y' turned into the 'Y/m/d' the other dates sort by. */
    private static function stageDate(string $stored): string
    {
        try {
            return Carbon::createFromFormat('d/m/Y', $stored)->format('Y/m/d');
        } catch (\Throwable) {
            return $stored;
        }
    }

    private static function event(string $stage, string $date, string $label, string $reference,
        string $detail, ?float $money = null): array
    {
        return compact('stage', 'date', 'label', 'reference', 'detail', 'money');
    }

    private static function orderDetail(object $order): string
    {
        return implode(' · ', array_filter([
            $order->company ?: null,
            $order->customercountry ?: null,
            $order->username ? 'entered by ' . $order->username : null,
        ]));
    }
}
