<?php

namespace Modules\Bpl\Livewire\Finance\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Support\PackingList;
use Modules\Bpl\Support\SalesFigures;

/**
 * BPL → Finance → Reports → Invoice Payments. Money received from customers,
 * over `bpl_invoice_payments`.
 *
 * Where the Receivables report is the ledger as it stands, this one is the
 * period: what came in, from whom, in which currency, and against which
 * shipment.
 *
 * ## One row per shipment, not per receipt
 *
 * The legacy updated the payment row in place rather than adding another, so
 * `amount` is the RUNNING TOTAL received against a shipment and its `date` is
 * the day of the LATEST receipt. gds keeps that shape so both apps read the
 * same figure (see the Invoice Payments screen). It means a part payment in
 * March followed by the balance in June appears once, in June — the money is
 * right, the history is not recoverable, and this report does not pretend
 * otherwise. Where a receipt-by-receipt history exists — the haulier side —
 * the Waybill Payments report shows it.
 *
 * ## Two dates in December 2021
 *
 * Two rows held `d/m/y` where every other row holds `Y/m/d`, which sorted them
 * before every other payment ever made and excluded them from their own
 * month. Repaired in 2026_09_25_160000.
 */
#[Title('BPL Invoice Payments Report')]
class InvoicePayments extends FinanceReport
{
    public function title(): string
    {
        return 'Invoice Payments Report';
    }

    public function printKey(): string
    {
        return 'invoice-payments';
    }

    protected function headline(): string
    {
        return 'Money received from customers against shipped invoices.';
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
            'settled' => ['yes' => 'Settled in full', 'no' => 'Still owing'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'customer' => ['label' => 'Customer', 'options' => $o['customers'], 'width' => 260],
            'country' => ['label' => 'Country', 'options' => $o['countries'], 'width' => 180],
            'currency' => ['label' => 'Currency', 'options' => $o['currencies'], 'width' => 150],
            'settled' => ['label' => 'Shipment', 'options' => $o['settled'], 'width' => 180],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['customer', 'country', 'currency', 'settled'];
    }

    /* ---------------- The query ---------------- */

    protected function base()
    {
        $q = $this->db()->table('bpl_invoice_payments as ip')
            ->leftJoin('bpl_packing_list as pl', 'pl.id', '=', 'ip.packing_id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->leftJoin('bpl_accounts as acc', 'acc.id', '=', 'pf.account_id')
            ->leftJoin('bpl_banks as bank', 'bank.id', '=', 'acc.beneficiary');

        $this->applyDate($q, 'ip.date', true);
        $this->applyFilters($q, [
            'customer' => 's.customerid',
            'country' => 'c.customercountry',
            'currency' => 'pf.currency_id',
        ]);

        // "Settled" needs the shipment's invoice, which only exists inside the
        // figures subquery — joined lazily so the common case stays cheap.
        if (($settled = (string) ($this->filters['settled'] ?? '')) !== '') {
            $q->joinSub(SalesFigures::shipments(), 'fs', 'fs.packing_id', '=', 'pl.id');

            $balance = 'ROUND(`fs`.`goods_value` + `fs`.`containers` * COALESCE(`pf`.`freight_price`, 0), 2)'
                . ' - ' . SalesFigures::SHIPMENT_PAID;

            $settled === 'yes'
                ? $q->whereRaw($balance . ' < 1')
                : $q->whereRaw($balance . ' >= 1');
        }

        return $q;
    }

    /** The receipts table alone, for a cheap total when no joined filter is set. */
    protected function countQuery()
    {
        $q = $this->db()->table('bpl_invoice_payments as ip');
        $this->applyDate($q, 'ip.date', true);

        return $q;
    }

    protected function select()
    {
        return $this->base()->selectRaw(
            '`ip`.`id`, `ip`.`date`, `ip`.`amount`, `ip`.`packing_id`,'
            . ' `pl`.`number` as `raw_number`, `c`.`id` as `customer_id`, `c`.`customerlabel`,'
            . " COALESCE(`c`.`customername`, '—') as `customer`,"
            . " COALESCE(`c`.`customercountry`, '') as `country`,"
            . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
            . ' ' . $this->orderNumberExpr() . ' as `order_number`,'
            . " COALESCE(`bank`.`name`, '') as `bank`,"
            . " COALESCE(`acc`.`account`, '') as `account`,"
            . ' `pl`.`date` as `shipped_on`'
        );
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['pl.number', 's.orderno', 's.ref', 'c.customername',
        'c.customerlabel', 'bank.name', 'acc.account'];

    public function views(): array
    {
        $number = fn ($r) => '<span class="mono">'
            . e(PackingList::label($r->raw_number, (int) $r->customer_id, $r->customerlabel))
            . '</span>';

        $columns = [
            $this->dateCol('Received', 'date'),
            ['Shipment', 'raw_number', $number],
            ['Customer', 'customer'],
            ['Country', 'country'],
            ['Currency', 'currency'],
            ['Amount', 'amount', $this->moneyCell('amount')],
            ['Into', 'bank'],
        ];

        $sortable = ['date', 'customer', 'country', 'currency', 'amount', 'bank'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()->orderByDesc('ip.date')->orderByDesc('ip.id'),
                'totals' => $this->moneyTotals(['amount']),
                'sortable' => $sortable,
            ],

            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customer'],
                    ['Country', 'country'],
                    ['Currency', 'currency'],
                    ['Payments', 'payments'],
                    ['Received', 'amount', $this->moneyCell('amount')],
                    $this->dateCol('Last Payment', 'last_paid'),
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(`c`.`customername`, '—') as `customer`,"
                    . " COALESCE(`c`.`customercountry`, '') as `country`,"
                    . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `payments`,'
                    . ' ROUND(SUM(`ip`.`amount`), 2) as `amount`,'
                    . ' MAX(`ip`.`date`) as `last_paid`'
                )->groupBy('c.customername', 'c.customercountry', 'cur.code', 'cur.symbol')
                    ->orderByDesc('amount'),
                'totals' => $this->moneyTotals(['amount'], ['payments']),
            ],

            'by_currency' => [
                'label' => 'Summary (by currency)',
                'type' => 'summary',
                'columns' => [
                    ['Currency', 'currency'],
                    ['Payments', 'payments'],
                    ['Customers', 'customers'],
                    ['Received', 'amount', $this->moneyCell('amount')],
                ],
                'searchable' => self::SEARCHABLE,
                // The one summary where a per-row total always means something,
                // because the row IS a currency.
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(`cur`.`code`, '—') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `payments`,'
                    . ' COUNT(DISTINCT `c`.`id`) as `customers`,'
                    . ' ROUND(SUM(`ip`.`amount`), 2) as `amount`'
                )->groupBy('cur.code', 'cur.symbol')->orderByDesc('amount'),
                'totals' => ['payments', 'customers'],
            ],

            'by_bank' => [
                'label' => 'Summary (by receiving bank)',
                'type' => 'summary',
                'columns' => [
                    ['Bank', 'bank'],
                    ['Account', 'account'],
                    ['Currency', 'currency'],
                    ['Payments', 'payments'],
                    ['Received', 'amount', $this->moneyCell('amount')],
                ],
                'searchable' => self::SEARCHABLE,
                // The account the PROFORMA named, which is where the money was
                // asked for — not proof of where it landed, which BPL's own
                // statements would have to say.
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(NULLIF(`bank`.`name`, ''), '— not named on the proforma —') as `bank`,"
                    . " COALESCE(`acc`.`account`, '') as `account`,"
                    . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `payments`,'
                    . ' ROUND(SUM(`ip`.`amount`), 2) as `amount`'
                )->groupBy('bank.name', 'acc.account', 'cur.code', 'cur.symbol')
                    ->orderByDesc('amount'),
                'totals' => $this->moneyTotals(['amount'], ['payments']),
            ],

            'by_month' => [
                'label' => 'Summary (by month)',
                'type' => 'summary',
                'columns' => [
                    ['Month', 'month'],
                    ['Currency', 'currency'],
                    ['Payments', 'payments'],
                    ['Received', 'amount', $this->moneyCell('amount')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    'LEFT(`ip`.`date`, 7) as `month`,'
                    . " COALESCE(`cur`.`code`, '—') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `payments`,'
                    . ' ROUND(SUM(`ip`.`amount`), 2) as `amount`'
                )->groupBy('month', 'cur.code', 'cur.symbol')
                    ->orderByDesc('month')->orderBy('currency'),
                'totals' => $this->moneyTotals(['amount'], ['payments']),
            ],
        ];
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_customer' => ['customer', 'country', 'currency'],
            'by_currency' => ['currency'],
            'by_bank' => ['bank', 'account', 'currency'],
            'by_month' => ['month', 'currency'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            $this->dateCol('Received', 'date'),
            ['Shipment', 'raw_number', fn ($r) => PackingList::label($r->raw_number, (int) $r->customer_id, $r->customerlabel)],
            ['Order', 'order_number'],
            ['Customer', 'customer'],
            ['Amount', 'amount', $this->moneyCell('amount')],
            ['Into', 'bank'],
        ];
    }

    public function detailSearchable(): array
    {
        return ['pl.number', 'c.customername', 's.orderno'];
    }

    public function detailQuery(string $key)
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        $q = $this->select();

        $columns = [
            'customer' => DB::raw("COALESCE(`c`.`customername`, '—')"),
            'country' => DB::raw("COALESCE(`c`.`customercountry`, '')"),
            'currency' => DB::raw("COALESCE(`cur`.`code`, '')"),
            'bank' => DB::raw("COALESCE(NULLIF(`bank`.`name`, ''), '— not named on the proforma —')"),
            'account' => DB::raw("COALESCE(`acc`.`account`, '')"),
            'month' => DB::raw('LEFT(`ip`.`date`, 7)'),
        ];

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            // The by-currency summary prints an em dash where the others print
            // an empty string; each drill-down matches its own summary.
            $field === 'currency' && $value === '—'
                ? $q->whereNull('cur.code')
                : $q->where($columns[$field], $value);
        }

        return $q->orderByDesc('ip.date')->orderByDesc('ip.id');
    }

    public function detailTitle(string $key): string
    {
        return implode(' · ', array_filter(
            $this->detailKeyParts($key),
            fn ($part) => (string) $part !== ''
        ));
    }
}
