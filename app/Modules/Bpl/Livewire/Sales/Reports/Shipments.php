<?php

namespace Modules\Bpl\Livewire\Sales\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Support\PackingList;
use Modules\Bpl\Support\SalesFigures;

/**
 * BPL → Sales → Reports → Shipments. What actually left, what it was invoiced
 * at, and what has been paid — over `bpl_packing_list`, its bill of lading and
 * its invoice payments.
 *
 * Rebuilt from the legacy `report_bpl_invoice`, `report_bpl_invoice_metrics`
 * and `report_bpl_pending_invoice`, which between them showed the shipments,
 * some totals and an outstanding-by-customer list. They are one report here,
 * because they are one question asked three ways.
 *
 * ## Invoiced, not quoted
 *
 * The amount is the FINAL invoice: each proforma line charged on the weight
 * that actually shipped, plus freight for the containers actually shipped.
 * That is what PackingList::finalInvoice() prints, and the arithmetic is
 * shared with it through Modules\Bpl\Support\SalesFigures — the test checks
 * the two agree for every packing list in the database.
 *
 * ## Split parents are not shipments
 *
 * An order shipped in two parts keeps the original packing list marked
 * `split = '1'` and creates `…(A)` and `…(B)` beneath it. The parent is a
 * container for the split, not a shipment, and counting it would double
 * everything — SalesFigures::shipments() excludes it, so this report never
 * sees one.
 *
 * ## Unpriced weight is shown, not swallowed
 *
 * A lot's description is a frozen copy of the proforma line's. Edit the
 * product afterwards and the shipped lot no longer matches any priced line —
 * 9 packing lists and 168,570 kg are in that state. Those rows would otherwise
 * quietly under-invoice, so `Unpriced` is a column and **Unpriced weight** is
 * a view.
 */
#[Title('BPL Shipments Report')]
class Shipments extends SalesReport
{
    public function title(): string
    {
        return 'Shipments Report';
    }

    public function printKey(): string
    {
        return 'shipments';
    }

    protected function headline(): string
    {
        return 'What shipped, what it was invoiced at, and what is still outstanding.';
    }

    /* ---------------- Filters ---------------- */

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    protected function options(): array
    {
        return $this->optCache ??= [
            'customers' => $this->shared('customers'),
            'currencies' => $this->shared('currencies'),
            'countries' => $this->shared('countries'),
            'payment' => [
                'unpaid' => 'Nothing received',
                'part' => 'Part paid',
                'paid' => 'Paid in full',
                'outstanding' => 'Anything outstanding',
            ],
            'bl' => ['yes' => 'Bill of lading issued', 'no' => 'No bill of lading'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'customer' => ['label' => 'Customer', 'options' => $o['customers'], 'width' => 260],
            'country' => ['label' => 'Country', 'options' => $o['countries'], 'width' => 180],
            'currency' => ['label' => 'Currency', 'options' => $o['currencies'], 'width' => 150],
            'payment' => ['label' => 'Payment', 'options' => $o['payment'], 'width' => 190],
            'bl' => ['label' => 'Bill of Lading', 'options' => $o['bl'], 'width' => 190],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['customer', 'country', 'currency', 'payment', 'bl'];
    }

    /* ---------------- The query ---------------- */

    /** The invoice total: goods actually shipped, plus freight per container shipped. */
    private const INVOICE_TOTAL = 'ROUND(`f`.`goods_value` + `f`.`containers` * COALESCE(`pf`.`freight_price`, 0), 2)';

    protected function base()
    {
        $q = $this->db()->table('bpl_packing_list as pl')
            // One row per packing list, carrying what shipped in it and what it
            // is worth — see SalesFigures for the arithmetic and why the join
            // back to a price is on the description.
            ->joinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->leftJoin('bpl_bill_of_lading as bl', 'bl.packing_id', '=', 'pl.id');

        $this->applyDate($q, 'pl.date', true);
        $this->applyFilters($q, [
            'customer' => 's.customerid',
            'country' => 'c.customercountry',
            'currency' => 'pf.currency_id',
        ]);

        return $this->applyPayment($this->applyBl($q));
    }

    private function applyBl($q)
    {
        return match ((string) ($this->filters['bl'] ?? '')) {
            'yes' => $q->whereNotNull('bl.id'),
            'no' => $q->whereNull('bl.id'),
            default => $q,
        };
    }

    private function applyPayment($q)
    {
        $paid = SalesFigures::SHIPMENT_PAID;
        $total = self::INVOICE_TOTAL;

        return match ((string) ($this->filters['payment'] ?? '')) {
            'unpaid' => $q->whereRaw($paid . ' <= 0'),
            // A tolerance of one unit: these are floats, and a payment entered
            // to the kobo against a computed invoice will not land exactly.
            'part' => $q->whereRaw($paid . ' > 0')->whereRaw($paid . ' < ' . $total . ' - 1'),
            'paid' => $q->whereRaw($paid . ' >= ' . $total . ' - 1'),
            'outstanding' => $q->whereRaw($paid . ' < ' . $total . ' - 1'),
            default => $q,
        };
    }

    protected function select()
    {
        $paid = SalesFigures::SHIPMENT_PAID;

        return $this->base()->selectRaw(
            '`pl`.`id`, `pl`.`order_id`, `pl`.`date`,'
            // The stored number carries a `:customerid` placeholder that the
            // documents replace with the customer's label; the report shows
            // what the document shows.
            . ' `pl`.`number` as `raw_number`, `c`.`id` as `customer_id`, `c`.`customerlabel`,'
            // One packing list (476) points at an order that is no longer in
            // `bpl_sales` at all, so even the ref fallback is NULL for it. The
            // order id is the last thing left that identifies the row.
            . ' COALESCE(' . $this->orderNumberExpr() . ", CONCAT('Order #', `pl`.`order_id`)) as `order_number`,"
            . " COALESCE(`c`.`customername`, '—') as `customer`,"
            . " COALESCE(`c`.`customercountry`, '') as `country`,"
            . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
            . ' `f`.`containers`, `f`.`rolls`, `f`.`net_weight`, `f`.`gross_weight`,'
            . ' `f`.`goods_value`, `f`.`unpriced_weight`,'
            . ' ROUND(`f`.`containers` * COALESCE(`pf`.`freight_price`, 0), 2) as `freight_charge`,'
            . ' ' . self::INVOICE_TOTAL . ' as `invoice_total`,'
            . ' ' . $paid . ' as `paid`,'
            . ' ROUND(' . self::INVOICE_TOTAL . ' - ' . $paid . ', 2) as `balance`,'
            . " COALESCE(`bl`.`number`, '') as `bl_number`,"
            . " COALESCE(`bl`.`shipped_date`, '') as `shipped_date`,"
            . " COALESCE(`bl`.`arrival_date`, '') as `arrival_date`,"
            . " COALESCE(`pf`.`nxp`, '') as `nxp`,"
            . " COALESCE(`pf`.`shipment`, '') as `shipment`"
        );
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['pl.number', 's.orderno', 's.ref', 'c.customername',
        'c.customerlabel', 'bl.number', 'pf.nxp', 'pf.customer_ref'];

    public function views(): array
    {
        $number = fn ($r) => '<span class="mono">'
            . e(PackingList::label($r->raw_number, (int) $r->customer_id, $r->customerlabel))
            . '</span>';

        $columns = [
            ['Shipment', 'raw_number', $number],
            $this->dateCol('Date', 'date'),
            ['Customer', 'customer'],
            ['Containers', 'containers'],
            ['Tonnes', 'net_weight', $this->tonnesCell('net_weight')],
            ['Invoiced', 'invoice_total', $this->moneyCell('invoice_total')],
            ['Paid', 'paid', $this->moneyCell('paid')],
            ['Balance', 'balance', $this->moneyCell('balance')],
            ['Status', 'status', fn ($r) => $this->statusCell($r)],
        ];

        $money = ['invoice_total', 'paid', 'balance'];
        $counts = ['containers', 'net_weight'];
        $sortable = ['date', 'customer', 'containers', 'net_weight',
            'invoice_total', 'paid', 'balance'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()->orderByDesc('pl.date')->orderByDesc('pl.id'),
                'totals' => $this->moneyTotals($money, $counts),
                'sortable' => $sortable,
            ],

            'outstanding' => [
                'label' => 'Outstanding',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()
                    ->whereRaw(SalesFigures::SHIPMENT_PAID . ' < ' . self::INVOICE_TOTAL . ' - 1')
                    ->orderByDesc('pl.date')->orderByDesc('pl.id'),
                'totals' => $this->moneyTotals($money, $counts),
                'sortable' => $sortable,
            ],

            'owed_by_customer' => [
                'label' => 'Outstanding by customer',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customer'],
                    ['Currency', 'currency'],
                    ['Shipments', 'shipments'],
                    ['Tonnes', 'net_weight', $this->tonnesCell('net_weight')],
                    ['Invoiced', 'invoice_total', $this->moneyCell('invoice_total')],
                    ['Paid', 'paid', $this->moneyCell('paid')],
                    ['Outstanding', 'balance', $this->moneyCell('balance')],
                ],
                'searchable' => self::SEARCHABLE,
                // The legacy's "pending invoice" screen, which grouped by
                // customer AND currency for the same reason this does: one
                // customer can owe in two currencies.
                'query' => fn () => $this->base()
                    ->whereRaw(SalesFigures::SHIPMENT_PAID . ' < ' . self::INVOICE_TOTAL . ' - 1')
                    ->selectRaw(
                        "COALESCE(`c`.`customername`, '—') as `customer`,"
                        . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
                        . ' COUNT(*) as `shipments`,'
                        . ' ROUND(SUM(`f`.`net_weight`), 2) as `net_weight`,'
                        . ' ROUND(SUM(' . self::INVOICE_TOTAL . '), 2) as `invoice_total`,'
                        . ' ROUND(SUM(' . SalesFigures::SHIPMENT_PAID . '), 2) as `paid`,'
                        . ' ROUND(SUM(' . self::INVOICE_TOTAL . ' - ' . SalesFigures::SHIPMENT_PAID . '), 2) as `balance`'
                    )
                    ->groupBy('c.customername', 'cur.code', 'cur.symbol')
                    ->orderByDesc('balance'),
                'totals' => $this->moneyTotals(['invoice_total', 'paid', 'balance'], ['shipments', 'net_weight']),
            ],

            'no_bl' => [
                'label' => 'No bill of lading',
                'type' => 'table',
                'columns' => array_merge(array_slice($columns, 0, 5), [
                    ['NXP', 'nxp'],
                    ['Shipment', 'shipment'],
                    ['Invoiced', 'invoice_total', $this->moneyCell('invoice_total')],
                ]),
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()->whereNull('bl.id')
                    ->orderByDesc('pl.date')->orderByDesc('pl.id'),
                'totals' => $this->moneyTotals(['invoice_total'], $counts),
                'sortable' => $sortable,
            ],

            'unpriced' => [
                'label' => 'Unpriced weight',
                'type' => 'table',
                'columns' => [
                    ['Shipment', 'raw_number', $number],
                    $this->dateCol('Date', 'date'),
                    ['Customer', 'customer'],
                    ['Tonnes Shipped', 'net_weight', $this->tonnesCell('net_weight')],
                    ['Tonnes Unpriced', 'unpriced_weight', fn ($r) => '<span class="badge badge-warning">'
                        . number_format((float) $r->unpriced_weight / 1000, 3) . '</span>'],
                    ['Invoiced', 'invoice_total', $this->moneyCell('invoice_total')],
                ],
                'searchable' => self::SEARCHABLE,
                // Goods that shipped under a description no current proforma
                // line matches — so the invoice above leaves them out. Almost
                // always a product whose specification was edited after the
                // packing list was written.
                'query' => fn () => $this->select()->where('f.unpriced_weight', '>', 0)
                    ->orderByDesc('f.unpriced_weight'),
                'totals' => $this->moneyTotals(['invoice_total'], ['net_weight', 'unpriced_weight']),
                'sortable' => ['date', 'customer', 'net_weight', 'unpriced_weight'],
            ],

            'by_currency' => [
                'label' => 'Summary (by currency)',
                'type' => 'summary',
                'columns' => [
                    ['Currency', 'currency'],
                    ['Shipments', 'shipments'],
                    ['Containers', 'containers'],
                    ['Tonnes', 'net_weight', $this->tonnesCell('net_weight')],
                    ['Invoiced', 'invoice_total', $this->moneyCell('invoice_total')],
                    ['Paid', 'paid', $this->moneyCell('paid')],
                    ['Outstanding', 'balance', $this->moneyCell('balance')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(`cur`.`code`, '—') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `shipments`,'
                    . ' SUM(`f`.`containers`) as `containers`,'
                    . ' ROUND(SUM(`f`.`net_weight`), 2) as `net_weight`,'
                    . ' ROUND(SUM(' . self::INVOICE_TOTAL . '), 2) as `invoice_total`,'
                    . ' ROUND(SUM(' . SalesFigures::SHIPMENT_PAID . '), 2) as `paid`,'
                    . ' ROUND(SUM(' . self::INVOICE_TOTAL . ' - ' . SalesFigures::SHIPMENT_PAID . '), 2) as `balance`'
                )->groupBy('cur.code', 'cur.symbol')->orderByDesc('invoice_total'),
                'totals' => ['shipments', 'containers', 'net_weight'],
            ],

            'by_month' => [
                'label' => 'Summary (by month)',
                'type' => 'summary',
                'columns' => [
                    ['Month', 'month'],
                    ['Currency', 'currency'],
                    ['Shipments', 'shipments'],
                    ['Containers', 'containers'],
                    ['Tonnes', 'net_weight', $this->tonnesCell('net_weight')],
                    ['Invoiced', 'invoice_total', $this->moneyCell('invoice_total')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    'LEFT(`pl`.`date`, 7) as `month`,'
                    . " COALESCE(`cur`.`code`, '—') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `shipments`,'
                    . ' SUM(`f`.`containers`) as `containers`,'
                    . ' ROUND(SUM(`f`.`net_weight`), 2) as `net_weight`,'
                    . ' ROUND(SUM(' . self::INVOICE_TOTAL . '), 2) as `invoice_total`'
                )->groupBy('month', 'cur.code', 'cur.symbol')
                    ->orderByDesc('month')->orderBy('currency'),
                'totals' => $this->moneyTotals(['invoice_total'], ['shipments', 'containers', 'net_weight']),
            ],
        ];
    }

    /* ---------------- Cells ---------------- */

    private function statusCell($row): string
    {
        $total = (float) $row->invoice_total;
        $paid = (float) $row->paid;

        $badge = $paid <= 0
            ? '<span class="badge badge-warning">Unpaid</span>'
            : ($paid >= $total - 1
                ? '<span class="badge badge-success">Paid</span>'
                : '<span class="badge badge-muted">Part paid</span>');

        if ((string) $row->bl_number !== '') {
            $badge .= ' <span class="badge badge-muted" title="Bill of lading '
                . e((string) $row->bl_number) . '">B/L</span>';
        }

        if ((float) $row->unpriced_weight > 0) {
            $badge .= ' <span class="badge badge-warning" title="'
                . number_format((float) $row->unpriced_weight) . ' kg shipped under a description no priced line matches">!</span>';
        }

        return $badge;
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'owed_by_customer' => ['customer', 'currency'],
            'by_currency' => ['currency'],
            'by_month' => ['month', 'currency'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Shipment', 'raw_number', fn ($r) => PackingList::label($r->raw_number, (int) $r->customer_id, $r->customerlabel)],
            $this->dateCol('Date', 'date'),
            ['Customer', 'customer'],
            ['Containers', 'containers'],
            ['Tonnes', 'net_weight', $this->tonnesCell('net_weight')],
            ['Invoiced', 'invoice_total', $this->moneyCell('invoice_total')],
            ['Paid', 'paid', $this->moneyCell('paid')],
            ['Balance', 'balance', $this->moneyCell('balance')],
            ['B/L', 'bl_number'],
        ];
    }

    public function detailSearchable(): array
    {
        return ['pl.number', 'c.customername', 'bl.number'];
    }

    public function detailQuery(string $key)
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        $q = $this->select();

        // The outstanding summary only counts shipments that owe something, so
        // its drill-down has to say the same or the rows will not add up to
        // the figure clicked.
        if ($this->view === 'owed_by_customer') {
            $q->whereRaw(SalesFigures::SHIPMENT_PAID . ' < ' . self::INVOICE_TOTAL . ' - 1');
        }

        $columns = [
            'customer' => DB::raw("COALESCE(`c`.`customername`, '—')"),
            'currency' => DB::raw("COALESCE(`cur`.`code`, '—')"),
            'month' => DB::raw('LEFT(`pl`.`date`, 7)'),
        ];

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            $q->where($columns[$field], $value);
        }

        return $q->orderByDesc('pl.date')->orderByDesc('pl.id');
    }

    public function detailTitle(string $key): string
    {
        return implode(' · ', array_filter(
            $this->detailKeyParts($key),
            fn ($part) => (string) $part !== ''
        ));
    }
}
