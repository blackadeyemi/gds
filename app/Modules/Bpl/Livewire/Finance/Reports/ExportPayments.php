<?php

namespace Modules\Bpl\Livewire\Finance\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Support\PackingList;
use Modules\Bpl\Support\Receivables as Rule;
use Modules\Bpl\Support\SalesFigures;

/**
 * BPL → Finance → Reports → Export Payments. Every shipment as the export
 * paperwork sees it: its NXP number, the bank handling it, the bill of lading,
 * what it is worth, when it falls due and where it stands.
 *
 * A direct rebuild of the legacy `bpl_export_payments` page, which was
 * restricted to three users and showed exactly these columns — NXP, Bank, PFI,
 * B/L Date, Invoice Value, Due Date, Payment Terms, Status — searchable on
 * five of them.
 *
 * ## Why this is not the Receivables report
 *
 * They share the due-date rule and nothing else. Receivables answers "who owes
 * us and how late are they", so it lists only what is outstanding and leads
 * with the customer and the age. This answers "where does each export stand",
 * so it lists EVERY shipment, settled or not, and leads with the NXP number
 * and the bank — the two things a trade-finance desk works from and neither of
 * which appears on the other report.
 *
 * ## The NXP number
 *
 * The NXP is the export form the transaction is processed against, and the
 * bank named on the proforma is the one that handles it. Four vessel shipments
 * carry no NXP at all, which is a documentation gap worth its own view. Inland
 * truck shipments routinely have none, so the view asks only about vessels —
 * flagging the trucks would bury the four that matter under ten that do not.
 *
 * ## Two legacy faults not carried over
 *
 * The legacy listing had no `split = '0'` condition while its record count did,
 * so it paged 409 rows against a stated total of 360: the 49 split parents —
 * scaffolding rows, not shipments — were listed and counted twice over. This
 * reads shipments through SalesFigures, which excludes them.
 *
 * The legacy also showed the value with a currency symbol and offered no way
 * to filter by currency, so its column could not be added up. Here the totals
 * follow the same rule as every other BPL money report: only within one
 * currency.
 */
#[Title('BPL Export Payments Report')]
class ExportPayments extends FinanceReport
{
    public function title(): string
    {
        return 'Export Payments Report';
    }

    public function printKey(): string
    {
        return 'export-payments';
    }

    protected function headline(): string
    {
        return 'Every export shipment by its NXP number and bank — value, due date and standing.';
    }

    /**
     * A register, not a period. The legacy page listed every shipment ever
     * made and that is what it is for: looking one up by its NXP. The B/L
     * month summary is where a period gets asked about.
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
            'banks' => $this->db()->table('bpl_banks')
                ->whereNotNull('name')->where('name', '<>', '')
                ->orderBy('name')->pluck('name', 'id')->all(),
            'currencies' => $this->shared('currencies'),
            'customers' => $this->shared('customers'),
            'terms' => $this->db()->table('bpl_payment_terms')
                ->orderBy('payment_terms')->pluck('payment_terms', 'id')->all(),
            'shipment' => $this->db()->table('bpl_proforma')
                ->whereNotNull('shipment')->where('shipment', '<>', '')
                ->select('shipment')->distinct()->orderBy('shipment')
                ->pluck('shipment', 'shipment')->all(),
            'nxp' => ['yes' => 'Has an NXP number', 'no' => 'No NXP number'],
            'states' => Rule::STATES,
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'bank' => ['label' => 'Bank', 'options' => $o['banks'], 'width' => 240],
            'customer' => ['label' => 'Customer', 'options' => $o['customers'], 'width' => 250],
            'currency' => ['label' => 'Currency', 'options' => $o['currencies'], 'width' => 150],
            'shipment' => ['label' => 'Shipment', 'options' => $o['shipment'], 'width' => 150],
            'payment_term' => ['label' => 'Payment Terms', 'options' => $o['terms'], 'width' => 210],
            'nxp' => ['label' => 'NXP', 'options' => $o['nxp'], 'width' => 190],
            'state' => ['label' => 'Status', 'options' => $o['states'], 'width' => 210],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['bank', 'customer', 'currency', 'shipment', 'payment_term', 'nxp', 'state'];
    }

    /* ---------------- The query ---------------- */

    private function balance(): string
    {
        return '(' . self::INVOICE_TOTAL . ' - ' . SalesFigures::SHIPMENT_PAID . ')';
    }

    protected function base()
    {
        $q = $this->db()->table('bpl_packing_list as pl')
            // Split parents are scaffolding, not shipments — the legacy listed
            // them and its own count did not.
            ->joinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('bpl_payment_terms as pt', 'pt.id', '=', 'pf.payment_term_id')
            ->leftJoin('bpl_bill_of_lading as bl', 'bl.packing_id', '=', 'pl.id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->leftJoin('bpl_accounts as acc', 'acc.id', '=', 'pf.account_id')
            ->leftJoin('bpl_banks as bank', 'bank.id', '=', 'acc.beneficiary');

        $this->applyFilters($q, [
            'bank' => 'acc.beneficiary',
            'customer' => 's.customerid',
            'currency' => 'pf.currency_id',
            'shipment' => 'pf.shipment',
            'payment_term' => 'pf.payment_term_id',
        ]);

        $q = match ((string) ($this->filters['nxp'] ?? '')) {
            'yes' => $q->whereRaw("COALESCE(`pf`.`nxp`, '') <> ''"),
            'no' => $q->whereRaw("COALESCE(`pf`.`nxp`, '') = ''"),
            default => $q,
        };

        if (($state = (string) ($this->filters['state'] ?? '')) !== '') {
            $q->whereRaw(Rule::state($this->balance()) . ' = ?', [$state]);
        }

        return $q;
    }

    protected function select()
    {
        return $this->base()->selectRaw(
            '`pl`.`id`, `pl`.`date`,'
            . " COALESCE(`pf`.`nxp`, '') as `nxp`,"
            // A dangling beneficiary is a real condition in this data, so it
            // reads as one rather than as a blank cell.
            . " CASE WHEN `acc`.`id` IS NULL THEN ''"
            . " WHEN `bank`.`id` IS NULL THEN CONCAT('Missing bank #', `acc`.`beneficiary`)"
            . ' ELSE `bank`.`name` END as `bank`,'
            . " COALESCE(`bank`.`swiftcode`, '') as `swift`,"
            . ' `pl`.`number` as `raw_number`, `c`.`id` as `customer_id`, `c`.`customerlabel`,'
            . ' ' . $this->orderNumberExpr() . ' as `order_number`,'
            . " COALESCE(`c`.`customername`, '—') as `customer`,"
            . " COALESCE(`c`.`customercountry`, '') as `country`,"
            . " COALESCE(`pf`.`shipment`, '') as `shipment`,"
            . " COALESCE(`bl`.`number`, '') as `bl_number`,"
            . " COALESCE(`bl`.`issued_date`, '') as `bl_date`,"
            . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
            . " COALESCE(`pt`.`payment_terms`, '') as `payment_terms`,"
            . ' ' . self::INVOICE_TOTAL . ' as `invoice_total`,'
            . ' ' . SalesFigures::SHIPMENT_PAID . ' as `paid`,'
            . ' ROUND(' . $this->balance() . ', 2) as `balance`,'
            . ' ' . Rule::DUE_DATE . ' as `due_date`,'
            . ' ' . Rule::DAYS_OVERDUE . ' as `days_overdue`,'
            . ' ' . Rule::state($this->balance()) . ' as `state`'
        );
    }

    /* ---------------- Views ---------------- */

    /** The five the legacy said its search box covered, plus the customer. */
    private const SEARCHABLE = ['pf.nxp', 'bank.name', 'pl.number', 'bl.issued_date',
        'pt.payment_terms', 'c.customername', 'bl.number'];

    public function views(): array
    {
        $number = fn ($r) => '<span class="mono">'
            . e(PackingList::label($r->raw_number, (int) $r->customer_id, $r->customerlabel))
            . '</span>';

        // The legacy's eight columns, in its order, with the customer added —
        // an NXP number alone does not tell you whose shipment it is.
        $columns = [
            ['NXP', 'nxp', fn ($r) => $r->nxp === ''
                ? '<span class="badge badge-warning">none</span>'
                : '<span class="mono">' . e((string) $r->nxp) . '</span>'],
            ['Bank', 'bank'],
            ['PFI', 'raw_number', $number],
            ['Customer', 'customer'],
            $this->dateCol('B/L Date', 'bl_date'),
            ['Invoice Value', 'invoice_total', $this->moneyCell('invoice_total')],
            $this->dateCol('Due Date', 'due_date'),
            ['Payment Terms', 'payment_terms'],
            ['Status', 'state', fn ($r) => $this->stateCell($r)],
        ];

        $sortable = ['nxp', 'bank', 'customer', 'bl_date', 'invoice_total', 'due_date', 'payment_terms'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                // Newest export first; a shipment with no bill of lading sorts
                // with the undated rather than at the top.
                'query' => fn () => $this->select()
                    ->orderByDesc(DB::raw("COALESCE(NULLIF(`bl`.`issued_date`, ''), `pl`.`date`)"))
                    ->orderByDesc('pl.id'),
                'totals' => $this->moneyTotals(['invoice_total', 'paid', 'balance']),
                'sortable' => $sortable,
            ],

            'no_nxp' => [
                'label' => 'No NXP number',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                // Vessels only. An inland truck routinely has no NXP, and
                // listing the ten of those would bury the four sea exports
                // that should have one.
                'query' => fn () => $this->select()
                    ->whereRaw("COALESCE(`pf`.`nxp`, '') = ''")
                    ->where('pf.shipment', '<>', 'Truck')
                    ->orderByDesc('pl.date'),
                'totals' => $this->moneyTotals(['invoice_total']),
                'sortable' => $sortable,
            ],

            'open' => [
                'label' => 'Not yet settled',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()
                    ->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE)
                    ->orderByDesc(DB::raw('COALESCE(' . Rule::DAYS_OVERDUE . ', -99999)')),
                'totals' => $this->moneyTotals(['invoice_total', 'paid', 'balance']),
                'sortable' => $sortable,
            ],

            'by_bank' => [
                'label' => 'Summary (by bank)',
                'type' => 'summary',
                'columns' => [
                    ['Bank', 'bank'],
                    ['Currency', 'currency'],
                    ['Shipments', 'shipments'],
                    ['Invoice Value', 'invoice_total', $this->moneyCell('invoice_total')],
                    ['Received', 'paid', $this->moneyCell('paid')],
                    ['Outstanding', 'balance', $this->moneyCell('balance')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    "CASE WHEN `acc`.`id` IS NULL THEN '— no account named —'"
                    . " WHEN `bank`.`id` IS NULL THEN CONCAT('Missing bank #', `acc`.`beneficiary`)"
                    . ' ELSE `bank`.`name` END as `bank`,'
                    . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `shipments`,'
                    . ' ROUND(SUM(' . self::INVOICE_TOTAL . '), 2) as `invoice_total`,'
                    . ' ROUND(SUM(' . SalesFigures::SHIPMENT_PAID . '), 2) as `paid`,'
                    . ' ROUND(SUM(' . $this->balance() . '), 2) as `balance`'
                )->groupBy('bank', 'cur.code', 'cur.symbol')->orderByDesc('invoice_total'),
                'totals' => $this->moneyTotals(['invoice_total', 'paid', 'balance'], ['shipments']),
            ],

            'by_terms' => [
                'label' => 'Summary (by payment terms)',
                'type' => 'summary',
                'columns' => [
                    ['Payment Terms', 'payment_terms'],
                    ['Days', 'term_days'],
                    ['Currency', 'currency'],
                    ['Shipments', 'shipments'],
                    ['Invoice Value', 'invoice_total', $this->moneyCell('invoice_total')],
                    ['Outstanding', 'balance', $this->moneyCell('balance')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(`pt`.`payment_terms`, '—') as `payment_terms`,"
                    . ' COALESCE(`pt`.`days`, 0) as `term_days`,'
                    . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `shipments`,'
                    . ' ROUND(SUM(' . self::INVOICE_TOTAL . '), 2) as `invoice_total`,'
                    . ' ROUND(SUM(' . $this->balance() . '), 2) as `balance`'
                )->groupBy('pt.payment_terms', 'pt.days', 'cur.code', 'cur.symbol')
                    ->orderByDesc('invoice_total'),
                'totals' => $this->moneyTotals(['invoice_total', 'balance'], ['shipments']),
            ],

            'by_month' => [
                'label' => 'Summary (by B/L month)',
                'type' => 'summary',
                'columns' => [
                    ['Month', 'month'],
                    ['Currency', 'currency'],
                    ['Shipments', 'shipments'],
                    ['Invoice Value', 'invoice_total', $this->moneyCell('invoice_total')],
                    ['Outstanding', 'balance', $this->moneyCell('balance')],
                ],
                'searchable' => self::SEARCHABLE,
                // By the month the goods were BILLED, which is the month an
                // export belongs to for the bank — not the month it was packed.
                'query' => fn () => $this->base()
                    ->whereRaw("COALESCE(`bl`.`issued_date`, '') <> ''")
                    ->selectRaw(
                        'LEFT(`bl`.`issued_date`, 7) as `month`,'
                        . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
                        . ' COUNT(*) as `shipments`,'
                        . ' ROUND(SUM(' . self::INVOICE_TOTAL . '), 2) as `invoice_total`,'
                        . ' ROUND(SUM(' . $this->balance() . '), 2) as `balance`'
                    )
                    ->groupBy('month', 'cur.code', 'cur.symbol')
                    ->orderByDesc('month')->orderBy('currency'),
                'totals' => $this->moneyTotals(['invoice_total', 'balance'], ['shipments']),
            ],
        ];
    }

    /* ---------------- Cells ---------------- */

    private function stateCell($row): string
    {
        $badge = '<span class="badge ' . Rule::tone($row->state) . '">'
            . e(Rule::label($row->state)) . '</span>';

        if (Rule::isOverdue($row->state) && $row->days_overdue !== null) {
            $badge .= ' <span class="text-muted text-sm">' . number_format((int) $row->days_overdue) . 'd</span>';
        }

        return $badge;
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_bank' => ['bank', 'currency'],
            'by_terms' => ['payment_terms', 'term_days', 'currency'],
            'by_month' => ['month', 'currency'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['NXP', 'nxp'],
            ['PFI', 'raw_number', fn ($r) => PackingList::label($r->raw_number, (int) $r->customer_id, $r->customerlabel)],
            ['Customer', 'customer'],
            $this->dateCol('B/L Date', 'bl_date'),
            ['Invoice Value', 'invoice_total', $this->moneyCell('invoice_total')],
            $this->dateCol('Due Date', 'due_date'),
            ['Outstanding', 'balance', $this->moneyCell('balance')],
            ['Status', 'state', fn ($r) => $this->stateCell($r)],
        ];
    }

    public function detailSearchable(): array
    {
        return ['pf.nxp', 'pl.number', 'c.customername', 'bl.number'];
    }

    public function detailQuery(string $key)
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        $q = $this->select();

        // The B/L month summary only counts shipments that have a bill of
        // lading, so its drill-down has to say the same.
        if ($this->view === 'by_month') {
            $q->whereRaw("COALESCE(`bl`.`issued_date`, '') <> ''");
        }

        $columns = [
            'bank' => DB::raw(
                "CASE WHEN `acc`.`id` IS NULL THEN '— no account named —'"
                . " WHEN `bank`.`id` IS NULL THEN CONCAT('Missing bank #', `acc`.`beneficiary`)"
                . ' ELSE `bank`.`name` END'
            ),
            'payment_terms' => DB::raw("COALESCE(`pt`.`payment_terms`, '—')"),
            'term_days' => DB::raw('COALESCE(`pt`.`days`, 0)'),
            'currency' => DB::raw("COALESCE(`cur`.`code`, '')"),
            'month' => DB::raw('LEFT(`bl`.`issued_date`, 7)'),
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
