<?php

namespace Modules\Bpl\Livewire\Sales\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Support\ProformaMilestones;
use Modules\Bpl\Support\SalesFigures;

/**
 * BPL → Sales → Reports → Proforma Invoices. Every proforma raised, over
 * `bpl_proforma` and `bpl_proforma_items`.
 *
 * Rebuilt from the legacy `report_bpl_invoice` and `report_bpl_pending_invoice`
 * pages. The screen at Sales → Proforma Invoices is for working on one; this is
 * for reading a period of them — what was quoted, in which currency, on what
 * terms, and which of the three approvals each one is still waiting for.
 *
 * ## The three approvals
 *
 * A proforma moves through customer approval, production and loading, each
 * recorded as a row in `core.actions` keyed `pfi_customer{order}` and so on
 * (see ProformaMilestones). They are the only record of where a quote has got
 * to, and the legacy had no report that showed them at all — you had to open
 * each proforma. The **Awaiting approval** view is the working list.
 *
 * ## Quoted, not invoiced
 *
 * The value here is what the proforma QUOTED: every priced line at its
 * per-tonne price, plus freight for the containers quoted. What was actually
 * invoiced depends on what shipped, and lives on the Shipments report.
 */
#[Title('BPL Proforma Invoices Report')]
class Proformas extends SalesReport
{
    public function title(): string
    {
        return 'Proforma Invoices Report';
    }

    public function printKey(): string
    {
        return 'proformas';
    }

    protected function headline(): string
    {
        return 'Every proforma raised — what was quoted, and which approval it is waiting for.';
    }

    public function defaultSort(): array
    {
        return ['date', 'desc'];
    }

    /* ---------------- Filters ---------------- */

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    protected function options(): array
    {
        return $this->optCache ??= [
            'customers' => $this->shared('customers'),
            'currencies' => $this->shared('currencies'),
            'shipments' => $this->db()->table('bpl_proforma')
                ->whereNotNull('shipment')->where('shipment', '<>', '')
                ->select('shipment')->distinct()->orderBy('shipment')
                ->pluck('shipment', 'shipment')->all(),
            'terms' => $this->db()->table('bpl_payment_terms')
                ->orderBy('payment_terms')->pluck('payment_terms', 'id')->all(),
            'stage' => [
                'awaiting' => 'Awaiting customer approval',
                'approved' => 'Approved, not yet produced',
                'produced' => 'Produced, not yet loaded',
                'loaded' => 'Loaded',
                'nxp' => 'Has an NXP number',
                'nonxp' => 'No NXP number',
            ],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'customer' => ['label' => 'Customer', 'options' => $o['customers'], 'width' => 260],
            'currency' => ['label' => 'Currency', 'options' => $o['currencies'], 'width' => 150],
            'shipment' => ['label' => 'Shipment', 'options' => $o['shipments'], 'width' => 160],
            'payment_term' => ['label' => 'Payment Terms', 'options' => $o['terms'], 'width' => 200],
            'stage' => ['label' => 'Stage', 'options' => $o['stage'], 'width' => 230],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['customer', 'stage'];
    }

    /* ---------------- The query ---------------- */

    /** Has this order reached a milestone? SQL, so it can be filtered on. */
    private function milestone(string $stage): string
    {
        return "EXISTS(SELECT 1 FROM `core`.`actions` `a` WHERE `a`.`request`"
            . " = CONCAT('" . ProformaMilestones::requestFor($stage, 0) . "', `pf`.`order_id`))";
    }

    protected function base()
    {
        $q = $this->db()->table('bpl_proforma as pf')
            ->join('bpl_sales as s', 's.id', '=', 'pf.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_payment_terms as pt', 'pt.id', '=', 'pf.payment_term_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->whereNull('pf.deleted_at')
            ->whereNull('s.deleted_at');

        $this->applyDate($q, 'pf.date', true);
        $this->applyFilters($q, [
            'customer' => 's.customerid',
            'currency' => 'pf.currency_id',
            'shipment' => 'pf.shipment',
            'payment_term' => 'pf.payment_term_id',
        ]);

        return $this->applyStage($q);
    }

    private function applyStage($q)
    {
        $approved = $this->milestone(ProformaMilestones::APPROVED);
        $produced = $this->milestone(ProformaMilestones::PRODUCED);
        $loaded = $this->milestone(ProformaMilestones::LOADED);

        return match ((string) ($this->filters['stage'] ?? '')) {
            'awaiting' => $q->whereRaw('NOT ' . $approved),
            'approved' => $q->whereRaw($approved)->whereRaw('NOT ' . $produced),
            'produced' => $q->whereRaw($produced)->whereRaw('NOT ' . $loaded),
            'loaded' => $q->whereRaw($loaded),
            'nxp' => $q->whereRaw("COALESCE(`pf`.`nxp`, '') <> ''"),
            'nonxp' => $q->whereRaw("COALESCE(`pf`.`nxp`, '') = ''"),
            default => $q,
        };
    }

    protected function countQuery()
    {
        $q = $this->db()->table('bpl_proforma as pf')->whereNull('pf.deleted_at');
        $this->applyDate($q, 'pf.date', true);
        $this->applyFilters($q, [
            'currency' => 'pf.currency_id',
            'shipment' => 'pf.shipment',
            'payment_term' => 'pf.payment_term_id',
        ]);

        return $q;
    }

    protected function select()
    {
        return $this->base()->selectRaw(
            '`pf`.`order_id` as `id`,'
            . ' ' . $this->orderNumberExpr() . ' as `number`,'
            . " COALESCE(`pf`.`customer_ref`, '') as `customer_ref`,"
            . ' `pf`.`date`,'
            . " COALESCE(`c`.`customername`, '—') as `customer`,"
            . " COALESCE(`c`.`customercountry`, '') as `country`,"
            . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
            . ' `pf`.`shipment`, `pf`.`terms`,'
            . " COALESCE(`pt`.`payment_terms`, '') as `payment_terms`,"
            . " COALESCE(`pf`.`nxp`, '') as `nxp`,"
            . ' `pf`.`container` as `containers`,'
            . ' ' . SalesFigures::ORDER_WEIGHT . ' as `weight`,'
            . ' ' . SalesFigures::ORDER_LINES . ' as `lines`,'
            . ' ' . SalesFigures::ORDER_PRICED_LINES . ' as `priced_lines`,'
            . ' ' . SalesFigures::ORDER_QUOTED_VALUE . ' as `goods_value`,'
            . ' ROUND(`pf`.`container` * `pf`.`freight_price`, 2) as `freight_charge`,'
            . ' ROUND(' . SalesFigures::ORDER_QUOTED_VALUE . ' + `pf`.`container` * `pf`.`freight_price`, 2) as `total_value`,'
            . ' ' . $this->milestone(ProformaMilestones::APPROVED) . ' as `approved`,'
            . ' ' . $this->milestone(ProformaMilestones::PRODUCED) . ' as `produced`,'
            . ' ' . $this->milestone(ProformaMilestones::LOADED) . ' as `loaded`,'
            . ' (SELECT COUNT(*) FROM `bpl_packing_list` `pl`'
            . " WHERE `pl`.`order_id` = `pf`.`order_id` AND `pl`.`split` = '0') as `shipments`"
        );
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['s.orderno', 's.ref', 'c.customername', 'c.customerlabel',
        'pf.nxp', 'pf.customer_ref', 'pf.shipment', 'pf.terms'];

    public function views(): array
    {
        $columns = [
            ['Number', 'number', fn ($r) => '<span class="mono">' . e((string) $r->number) . '</span>'
                . ($r->customer_ref !== '' ? '<div class="text-sm text-muted">' . e((string) $r->customer_ref) . '</div>' : '')],
            $this->dateCol('Date', 'date'),
            ['Customer', 'customer'],
            ['Currency', 'currency'],
            ['Tonnes', 'weight', $this->tonnesCell('weight')],
            ['Goods', 'goods_value', $this->moneyCell('goods_value')],
            ['Freight', 'freight_charge', $this->moneyCell('freight_charge')],
            ['Total', 'total_value', $this->moneyCell('total_value')],
            ['Progress', 'progress', fn ($r) => $this->progressCell($r)],
        ];

        $money = ['goods_value', 'freight_charge', 'total_value'];
        $sortable = ['number', 'date', 'customer', 'currency', 'weight',
            'goods_value', 'freight_charge', 'total_value'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()->orderByDesc('pf.date')->orderByDesc('pf.order_id'),
                'totals' => $this->moneyTotals($money, ['weight']),
                'sortable' => $sortable,
            ],

            'awaiting' => [
                'label' => 'Awaiting approval',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()
                    ->whereRaw('NOT ' . $this->milestone(ProformaMilestones::APPROVED))
                    ->orderByDesc('pf.date')->orderByDesc('pf.order_id'),
                'totals' => $this->moneyTotals($money, ['weight']),
                'sortable' => $sortable,
            ],

            'unshipped' => [
                'label' => 'Nothing shipped yet',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_packing_list as pl')
                        ->whereColumn('pl.order_id', 'pf.order_id'))
                    ->orderByDesc('pf.date')->orderByDesc('pf.order_id'),
                'totals' => $this->moneyTotals($money, ['weight']),
                'sortable' => $sortable,
            ],

            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customer'],
                    ['Currency', 'currency'],
                    ['Proformas', 'proformas'],
                    ['Tonnes', 'weight', $this->tonnesCell('weight')],
                    ['Total Quoted', 'total_value', $this->moneyCell('total_value')],
                ],
                'searchable' => self::SEARCHABLE,
                // Grouped by currency as well as customer, so a customer billed
                // in two currencies shows two honest rows rather than one
                // nonsensical sum.
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(`c`.`customername`, '—') as `customer`,"
                    . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `proformas`,'
                    . ' ROUND(SUM(' . SalesFigures::ORDER_WEIGHT . '), 2) as `weight`,'
                    . ' ROUND(SUM(' . SalesFigures::ORDER_QUOTED_VALUE
                        . ' + `pf`.`container` * `pf`.`freight_price`), 2) as `total_value`'
                )->groupBy('c.customername', 'cur.code', 'cur.symbol')
                    ->orderByDesc(DB::raw('SUM(' . SalesFigures::ORDER_QUOTED_VALUE . ')')),
                'totals' => $this->moneyTotals(['total_value'], ['proformas', 'weight']),
            ],

            'by_currency' => [
                'label' => 'Summary (by currency)',
                'type' => 'summary',
                'columns' => [
                    ['Currency', 'currency'],
                    ['Proformas', 'proformas'],
                    ['Tonnes', 'weight', $this->tonnesCell('weight')],
                    ['Goods', 'goods_value', $this->moneyCell('goods_value')],
                    ['Freight', 'freight_charge', $this->moneyCell('freight_charge')],
                    ['Total', 'total_value', $this->moneyCell('total_value')],
                ],
                'searchable' => self::SEARCHABLE,
                // The one summary where a total per row is always meaningful,
                // because the row IS a currency.
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(`cur`.`code`, '—') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `proformas`,'
                    . ' ROUND(SUM(' . SalesFigures::ORDER_WEIGHT . '), 2) as `weight`,'
                    . ' ROUND(SUM(' . SalesFigures::ORDER_QUOTED_VALUE . '), 2) as `goods_value`,'
                    . ' ROUND(SUM(`pf`.`container` * `pf`.`freight_price`), 2) as `freight_charge`,'
                    . ' ROUND(SUM(' . SalesFigures::ORDER_QUOTED_VALUE
                        . ' + `pf`.`container` * `pf`.`freight_price`), 2) as `total_value`'
                )->groupBy('cur.code', 'cur.symbol')->orderByDesc('total_value'),
                'totals' => ['proformas', 'weight'],
            ],

            'by_month' => [
                'label' => 'Summary (by month)',
                'type' => 'summary',
                'columns' => [
                    ['Month', 'month'],
                    ['Currency', 'currency'],
                    ['Proformas', 'proformas'],
                    ['Tonnes', 'weight', $this->tonnesCell('weight')],
                    ['Total Quoted', 'total_value', $this->moneyCell('total_value')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    'LEFT(`pf`.`date`, 7) as `month`,'
                    . " COALESCE(`cur`.`code`, '—') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `proformas`,'
                    . ' ROUND(SUM(' . SalesFigures::ORDER_WEIGHT . '), 2) as `weight`,'
                    . ' ROUND(SUM(' . SalesFigures::ORDER_QUOTED_VALUE
                        . ' + `pf`.`container` * `pf`.`freight_price`), 2) as `total_value`'
                )->groupBy('month', 'cur.code', 'cur.symbol')
                    ->orderByDesc('month')->orderBy('currency'),
                'totals' => $this->moneyTotals(['total_value'], ['proformas', 'weight']),
            ],
        ];
    }

    /* ---------------- Cells ---------------- */

    /** The three approvals as A / P / L dots, the way the screen shows them. */
    private function progressCell($row): string
    {
        $out = '';

        foreach ([
            'approved' => ['A', ProformaMilestones::APPROVED],
            'produced' => ['P', ProformaMilestones::PRODUCED],
            'loaded' => ['L', ProformaMilestones::LOADED],
        ] as $field => [$letter, $stage]) {
            $done = (bool) $row->{$field};

            $out .= '<span class="badge ' . ($done ? 'badge-success' : 'badge-muted') . '"'
                . ' style="margin-right:.2rem;" title="'
                . e(ProformaMilestones::label($stage) . ($done ? '' : ' — not yet'))
                . '">' . $letter . '</span>';
        }

        if ((int) $row->shipments > 0) {
            $out .= '<span class="badge badge-success" title="Shipped">S</span>';
        }

        return $out;
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_customer' => ['customer', 'currency'],
            'by_currency' => ['currency'],
            'by_month' => ['month', 'currency'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Number', 'number'],
            $this->dateCol('Date', 'date'),
            ['Customer', 'customer'],
            ['Tonnes', 'weight', $this->tonnesCell('weight')],
            ['Goods', 'goods_value', $this->moneyCell('goods_value')],
            ['Freight', 'freight_charge', $this->moneyCell('freight_charge')],
            ['Total', 'total_value', $this->moneyCell('total_value')],
            ['NXP', 'nxp'],
        ];
    }

    public function detailSearchable(): array
    {
        return ['s.orderno', 's.ref', 'c.customername', 'pf.nxp'];
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
            'currency' => DB::raw("COALESCE(`cur`.`code`, '—')"),
            'month' => DB::raw('LEFT(`pf`.`date`, 7)'),
        ];

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            $q->where($columns[$field], $value);
        }

        return $q->orderByDesc('pf.date')->orderByDesc('pf.order_id');
    }

    public function detailTitle(string $key): string
    {
        return implode(' · ', array_filter(
            $this->detailKeyParts($key),
            fn ($part) => (string) $part !== ''
        ));
    }
}
