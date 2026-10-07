<?php

namespace Modules\Bpl\Livewire\Finance\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Support\PackingList;
use Modules\Bpl\Support\Receivables as Rule;
use Modules\Bpl\Support\SalesFigures;

/**
 * BPL → Finance → Reports → Receivables. What customers owe, and how late.
 *
 * Rebuilt from the legacy `bpl_export_payments` screen and
 * `report_bpl_invoice_stats`, which showed a due date and a one-word status
 * per shipment and nothing that could be worked down: a shipment part-paid
 * three years ago read exactly like one part-paid yesterday.
 *
 * ## Aged, which is the whole point
 *
 * The credit period comes off the proforma's payment terms and runs from the
 * day the goods were DOCUMENTED — the bill of lading for a vessel, the packing
 * list for an inland truck. Every unsettled invoice is then placed in an
 * ageing bucket. See Modules\Bpl\Support\Receivables for the rule, which is
 * the legacy's arithmetic to the day, including its `+1`.
 *
 * ## Two things this surfaces that nothing else did
 *
 * **Awaiting B/L.** A vessel shipment with no bill of lading has no due date —
 * the clock has not started — so it cannot be overdue and must not be counted
 * as such. Sixteen shipments, ~$293k and €99k, are in that state today. They
 * are not hidden: they are a bucket of their own, because an invoice nobody
 * can chase is a problem even though nobody is late.
 *
 * **Overpaid.** Sixteen shipments show more received than invoiced. For seven
 * of them the invoice is understated rather than the payment excessive: their
 * goods shipped under a description no priced line matches (see SalesFigures),
 * so part of the load is invoiced at nothing. The Overpaid view separates the
 * two, because one is a bookkeeping question and the other is a data repair.
 */
#[Title('BPL Receivables Report')]
class Receivables extends FinanceReport
{
    public function title(): string
    {
        return 'Receivables Report';
    }

    public function printKey(): string
    {
        return 'receivables';
    }

    protected function headline(): string
    {
        return 'What customers owe on shipped invoices, and how far past due.';
    }

    /**
     * A snapshot of the ledger, not a period.
     *
     * A debt does not stop existing because the date filter moved, and a
     * receivables figure that changes when somebody picks "last 30 days" is a
     * figure nobody can act on. The date range is therefore off entirely —
     * the Invoice Payments report is where a period is the question.
     */
    public function hasDateRange(): bool
    {
        return false;
    }

    /* ---------------- Filters ---------------- */

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    protected function options(): array
    {
        return $this->optCache ??= [
            'customers' => $this->shared('customers'),
            'countries' => $this->shared('countries'),
            'currencies' => $this->shared('currencies'),
            'states' => Rule::STATES,
            'terms' => $this->db()->table('bpl_payment_terms')
                ->orderBy('payment_terms')->pluck('payment_terms', 'id')->all(),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'customer' => ['label' => 'Customer', 'options' => $o['customers'], 'width' => 260],
            'country' => ['label' => 'Country', 'options' => $o['countries'], 'width' => 180],
            'currency' => ['label' => 'Currency', 'options' => $o['currencies'], 'width' => 150],
            'state' => ['label' => 'Status', 'options' => $o['states'], 'width' => 210],
            'payment_term' => ['label' => 'Payment Terms', 'options' => $o['terms'], 'width' => 210],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['customer', 'country', 'currency', 'state', 'payment_term'];
    }

    /* ---------------- The query ---------------- */

    /** What is still owed on a shipment. */
    private function balance(): string
    {
        return '(' . self::INVOICE_TOTAL . ' - ' . SalesFigures::SHIPMENT_PAID . ')';
    }

    protected function base()
    {
        $q = $this->db()->table('bpl_packing_list as pl')
            ->joinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('bpl_payment_terms as pt', 'pt.id', '=', 'pf.payment_term_id')
            ->leftJoin('bpl_bill_of_lading as bl', 'bl.packing_id', '=', 'pl.id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id');

        $this->applyFilters($q, [
            'customer' => 's.customerid',
            'country' => 'c.customercountry',
            'currency' => 'pf.currency_id',
            'payment_term' => 'pf.payment_term_id',
        ]);

        if (($state = (string) ($this->filters['state'] ?? '')) !== '') {
            $q->whereRaw(Rule::state($this->balance()) . ' = ?', [$state]);
        }

        return $q;
    }

    protected function select()
    {
        return $this->base()->selectRaw(
            '`pl`.`id`, `pl`.`date`,'
            . ' `pl`.`number` as `raw_number`, `c`.`id` as `customer_id`, `c`.`customerlabel`,'
            . " COALESCE(`c`.`customername`, '—') as `customer`,"
            . " COALESCE(`c`.`customercountry`, '') as `country`,"
            . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
            . " COALESCE(`pt`.`payment_terms`, '') as `payment_terms`,"
            . ' COALESCE(`pt`.`days`, 0) as `term_days`,'
            . " COALESCE(`pf`.`shipment`, '') as `shipment`,"
            . " COALESCE(`bl`.`number`, '') as `bl_number`,"
            . ' ' . Rule::CLOCK_START . ' as `billed_on`,'
            . ' ' . Rule::DUE_DATE . ' as `due_date`,'
            . ' ' . Rule::DAYS_OVERDUE . ' as `days_overdue`,'
            . ' ' . self::INVOICE_TOTAL . ' as `invoice_total`,'
            . ' ' . SalesFigures::SHIPMENT_PAID . ' as `paid`,'
            . ' ROUND(' . $this->balance() . ', 2) as `balance`,'
            . ' `f`.`unpriced_weight`,'
            . ' ' . Rule::state($this->balance()) . ' as `state`'
        );
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['pl.number', 's.orderno', 's.ref', 'c.customername',
        'c.customerlabel', 'bl.number', 'pf.nxp'];

    public function views(): array
    {
        $number = fn ($r) => '<span class="mono">'
            . e(PackingList::label($r->raw_number, (int) $r->customer_id, $r->customerlabel))
            . '</span>';

        $columns = [
            ['Shipment', 'raw_number', $number],
            ['Customer', 'customer'],
            ['Currency', 'currency'],
            $this->dateCol('Billed', 'billed_on'),
            ['Terms', 'payment_terms'],
            $this->dateCol('Due', 'due_date'),
            ['Age', 'days_overdue', fn ($r) => $this->ageCell($r)],
            ['Invoiced', 'invoice_total', $this->moneyCell('invoice_total')],
            ['Paid', 'paid', $this->moneyCell('paid')],
            ['Owed', 'balance', $this->moneyCell('balance')],
            ['Status', 'state', fn ($r) => $this->stateCell($r)],
        ];

        $money = ['invoice_total', 'paid', 'balance'];
        $sortable = ['customer', 'currency', 'billed_on', 'due_date', 'days_overdue',
            'invoice_total', 'paid', 'balance'];

        // Everything still owed, oldest debt first — the working order.
        $owing = fn () => $this->select()
            ->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE)
            ->orderByDesc(DB::raw('COALESCE(' . Rule::DAYS_OVERDUE . ', -99999)'));

        return [
            'default' => [
                'label' => 'Outstanding',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => $owing,
                'totals' => $this->moneyTotals($money),
                'sortable' => $sortable,
            ],

            'overdue' => [
                'label' => 'Overdue only',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                // Past the due date, so the "awaiting B/L" shipments — which
                // have no due date at all — are correctly left out.
                'query' => fn () => $owing()->whereRaw(Rule::DAYS_OVERDUE . ' > 0'),
                'totals' => $this->moneyTotals($money),
                'sortable' => $sortable,
            ],

            'by_age' => [
                'label' => 'Summary (by age)',
                'type' => 'summary',
                'columns' => [
                    ['Status', 'state', fn ($r) => $this->stateCell($r)],
                    ['Currency', 'currency'],
                    ['Shipments', 'shipments'],
                    ['Owed', 'balance', $this->moneyCell('balance')],
                    ['Oldest (days)', 'oldest'],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE)
                    ->selectRaw(
                        Rule::state($this->balance()) . ' as `state`,'
                        . " COALESCE(`cur`.`code`, '—') as `currency`, `cur`.`symbol`,"
                        . ' COUNT(*) as `shipments`,'
                        . ' ROUND(SUM(' . $this->balance() . '), 2) as `balance`,'
                        . ' MAX(' . Rule::DAYS_OVERDUE . ') as `oldest`'
                    )
                    ->groupBy('state', 'cur.code', 'cur.symbol')
                    ->orderByDesc('oldest'),
                'totals' => $this->moneyTotals(['balance'], ['shipments']),
            ],

            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customer'],
                    ['Country', 'country'],
                    ['Currency', 'currency'],
                    ['Shipments', 'shipments'],
                    ['Invoiced', 'invoice_total', $this->moneyCell('invoice_total')],
                    ['Paid', 'paid', $this->moneyCell('paid')],
                    ['Owed', 'balance', $this->moneyCell('balance')],
                    ['Oldest (days)', 'oldest'],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE)
                    ->selectRaw(
                        "COALESCE(`c`.`customername`, '—') as `customer`,"
                        . " COALESCE(`c`.`customercountry`, '') as `country`,"
                        . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
                        . ' COUNT(*) as `shipments`,'
                        . ' ROUND(SUM(' . self::INVOICE_TOTAL . '), 2) as `invoice_total`,'
                        . ' ROUND(SUM(' . SalesFigures::SHIPMENT_PAID . '), 2) as `paid`,'
                        . ' ROUND(SUM(' . $this->balance() . '), 2) as `balance`,'
                        . ' MAX(' . Rule::DAYS_OVERDUE . ') as `oldest`'
                    )
                    ->groupBy('c.customername', 'c.customercountry', 'cur.code', 'cur.symbol')
                    ->orderByDesc('balance'),
                'totals' => $this->moneyTotals(['invoice_total', 'paid', 'balance'], ['shipments']),
            ],

            'awaiting_bl' => [
                'label' => 'Awaiting bill of lading',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                // Invoiced, unpaid, and not chaseable: a vessel shipment with
                // no bill of lading has no due date, so the credit period has
                // never started running.
                'query' => fn () => $this->select()
                    ->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE)
                    ->whereRaw(Rule::DUE_DATE . ' IS NULL')
                    ->orderBy('pl.date'),
                'totals' => $this->moneyTotals($money),
                'sortable' => $sortable,
            ],

            'overpaid' => [
                'label' => 'Overpaid',
                'type' => 'table',
                'columns' => [
                    ['Shipment', 'raw_number', $number],
                    ['Customer', 'customer'],
                    ['Currency', 'currency'],
                    $this->dateCol('Shipped', 'date'),
                    ['Invoiced', 'invoice_total', $this->moneyCell('invoice_total')],
                    ['Paid', 'paid', $this->moneyCell('paid')],
                    ['Excess', 'excess', $this->moneyCell('excess')],
                    ['Why', 'why', fn ($r) => $this->whyCell($r)],
                ],
                'searchable' => self::SEARCHABLE,
                // More received than invoiced. For most of these the invoice
                // is understated rather than the payment excessive — the Why
                // column says which, and the ones with unpriced weight are a
                // data repair, not a refund.
                'query' => fn () => $this->select()
                    ->selectRaw('ROUND(-1 * ' . $this->balance() . ', 2) as `excess`')
                    ->whereRaw($this->balance() . ' < -' . Rule::TOLERANCE)
                    ->orderByDesc('excess'),
                'totals' => $this->moneyTotals(['invoice_total', 'paid', 'excess']),
                'sortable' => ['customer', 'currency', 'date', 'invoice_total', 'paid', 'excess'],
            ],
        ];
    }

    /* ---------------- Cells ---------------- */

    private function stateCell($row): string
    {
        return '<span class="badge ' . Rule::tone($row->state) . '">'
            . e(Rule::label($row->state)) . '</span>';
    }

    private function ageCell($row): string
    {
        if ($row->days_overdue === null) {
            return '<span class="text-muted">—</span>';
        }

        $days = (int) $row->days_overdue;

        return $days > 0
            ? '<span class="badge ' . ($days > 90 ? 'badge-danger' : 'badge-warning') . '">'
                . number_format($days) . 'd late</span>'
            : '<span class="text-muted">' . number_format(abs($days)) . 'd left</span>';
    }

    private function whyCell($row): string
    {
        return (float) $row->unpriced_weight > 0
            ? '<span class="badge badge-warning" title="' . number_format((float) $row->unpriced_weight)
                . ' kg shipped under a description no priced line matches, so the invoice is short">'
                . 'invoice understated</span>'
            : '<span class="badge badge-muted">payment exceeds the invoice</span>';
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_age' => ['state', 'currency'],
            'by_customer' => ['customer', 'country', 'currency'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Shipment', 'raw_number', fn ($r) => PackingList::label($r->raw_number, (int) $r->customer_id, $r->customerlabel)],
            ['Customer', 'customer'],
            $this->dateCol('Billed', 'billed_on'),
            $this->dateCol('Due', 'due_date'),
            ['Age', 'days_overdue', fn ($r) => $this->ageCell($r)],
            ['Invoiced', 'invoice_total', $this->moneyCell('invoice_total')],
            ['Paid', 'paid', $this->moneyCell('paid')],
            ['Owed', 'balance', $this->moneyCell('balance')],
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

        // Both summaries count only what is still owed, so the drill-down has
        // to say the same or the rows will not add up to the figure clicked.
        $q = $this->select()->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE);

        $columns = [
            'state' => DB::raw(Rule::state($this->balance())),
            'customer' => DB::raw("COALESCE(`c`.`customername`, '—')"),
            'country' => DB::raw("COALESCE(`c`.`customercountry`, '')"),
            'currency' => DB::raw("COALESCE(`cur`.`code`, '')"),
        ];

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            // The age summary prints an em dash for a missing currency; the
            // customer summary prints an empty string. Each drill-down matches
            // what its own summary produced.
            $field === 'currency' && $value === '—'
                ? $q->whereNull('cur.code')
                : $q->where($columns[$field], $value);
        }

        return $q->orderByDesc(DB::raw('COALESCE(' . Rule::DAYS_OVERDUE . ', -99999)'));
    }

    public function detailTitle(string $key): string
    {
        $parts = $this->detailKeyParts($key);

        if ($this->view === 'by_age' && isset($parts[0])) {
            $parts[0] = Rule::label($parts[0]);
        }

        return implode(' · ', array_filter($parts, fn ($p) => (string) $p !== ''));
    }
}
