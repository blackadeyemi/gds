<?php

namespace Modules\Bpl\Livewire\Sales\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Support\SalesFigures;

/**
 * BPL → Sales → Reports → Orders. Every export order raised, over `bpl_sales`
 * and `bpl_sales_items`.
 *
 * Rebuilt from the legacy `report_bpl_sales` page, which listed the orders and
 * stopped there. The question people actually ask of an order list is how far
 * each one got, so every row carries its own progress: whether a proforma has
 * been raised, whether the customer approved it, and whether anything has
 * shipped against it.
 *
 * ## An order with no proforma has no value yet
 *
 * The price lives on the PROFORMA, not the order — `bpl_sales_items` carries a
 * weight and nothing else. So a fresh order shows its tonnage and a blank
 * amount, which is the truth: nobody has priced it. The "Not yet priced" view
 * is that list, and it is the one worth working down.
 */
#[Title('BPL Sales Orders Report')]
class Orders extends SalesReport
{
    public function title(): string
    {
        return 'Sales Orders Report';
    }

    public function printKey(): string
    {
        return 'orders';
    }

    protected function headline(): string
    {
        return 'Every export order raised, and how far it has got.';
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
            'stage' => [
                'none' => 'No proforma yet',
                'proforma' => 'Proforma raised',
                'approved' => 'Approved by the customer',
                'shipped' => 'Shipped',
            ],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'customer' => ['label' => 'Customer', 'options' => $o['customers'], 'width' => 260],
            'country' => ['label' => 'Country', 'options' => $o['countries'], 'width' => 180],
            'currency' => ['label' => 'Currency', 'options' => $o['currencies'], 'width' => 150],
            'stage' => ['label' => 'Got As Far As', 'options' => $o['stage'], 'width' => 210],
        ];
    }

    /** Country and currency both come off joined tables. */
    protected function joinedFilterKeys(): array
    {
        return ['country', 'currency', 'stage'];
    }

    /* ---------------- The query ---------------- */

    protected function base()
    {
        $q = $this->db()->table('bpl_sales as s')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 's.id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->whereNull('s.deleted_at');

        $this->applyDate($q, 's.date', true);
        $this->applyFilters($q, [
            'customer' => 's.customerid',
            'country' => 'c.customercountry',
            'currency' => 'pf.currency_id',
        ]);

        return $this->applyStage($q);
    }

    /**
     * The stage filter, which is four different predicates rather than one
     * column — there is no "stage" anywhere in the data, only the presence or
     * absence of the next document.
     */
    private function applyStage($q)
    {
        $stage = (string) ($this->filters['stage'] ?? '');

        $approved = "EXISTS(SELECT 1 FROM `core`.`actions` `a`"
            . " WHERE `a`.`request` = CONCAT('pfi_customer', `s`.`id`))";
        $shipped = 'EXISTS(SELECT 1 FROM `bpl_packing_list` `pl` WHERE `pl`.`order_id` = `s`.`id`)';

        return match ($stage) {
            'none' => $q->whereNull('pf.order_id'),
            'proforma' => $q->whereNotNull('pf.order_id')->whereRaw('NOT ' . $approved),
            'approved' => $q->whereRaw($approved)->whereRaw('NOT ' . $shipped),
            'shipped' => $q->whereRaw($shipped),
            default => $q,
        };
    }

    /** The orders table alone, for a cheap total when no joined filter is set. */
    protected function countQuery()
    {
        $q = $this->db()->table('bpl_sales as s')->whereNull('s.deleted_at');
        $this->applyDate($q, 's.date', true);
        $this->applyFilters($q, ['customer' => 's.customerid']);

        return $q;
    }

    /** Every column the views select, built once. */
    protected function select()
    {
        return $this->base()->selectRaw(
            '`s`.`id`,'
            . ' ' . $this->orderNumberExpr() . ' as `number`,'
            . ' `s`.`ref`, `s`.`date`, `s`.`username` as `user`, `s`.`company`,'
            . " COALESCE(`c`.`customername`, '—') as `customer`,"
            . " COALESCE(`c`.`customercountry`, '') as `country`,"
            . ' ' . SalesFigures::ORDER_LINES . ' as `lines`,'
            . ' ' . SalesFigures::ORDER_PRICED_LINES . ' as `priced_lines`,'
            . ' ' . SalesFigures::ORDER_WEIGHT . ' as `weight`,'
            . ' ' . SalesFigures::ORDER_QUOTED_VALUE . ' as `value`,'
            . ' `pf`.`order_id` IS NOT NULL as `has_proforma`,'
            . ' `pf`.`date` as `proforma_date`,'
            . " COALESCE(`pf`.`nxp`, '') as `nxp`,"
            . " COALESCE(`cur`.`code`, '') as `currency`,"
            . ' `cur`.`symbol`,'
            . " EXISTS(SELECT 1 FROM `core`.`actions` `a` WHERE `a`.`request` = CONCAT('pfi_customer', `s`.`id`)) as `approved`,"
            . ' (SELECT COUNT(*) FROM `bpl_packing_list` `pl`'
            . " WHERE `pl`.`order_id` = `s`.`id` AND `pl`.`split` = '0') as `shipments`"
        );
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['s.orderno', 's.ref', 'c.customername', 'c.customerlabel',
        's.username', 's.company', 'pf.nxp', 'pf.customer_ref'];

    public function views(): array
    {
        $columns = [
            ['Order', 'number', fn ($r) => '<span class="mono">' . e((string) $r->number) . '</span>'],
            $this->dateCol('Date', 'date'),
            ['Customer', 'customer'],
            ['Country', 'country'],
            ['Lines', 'lines', function ($r) {
                $all = (int) $r->lines;
                $priced = (int) $r->priced_lines;

                return $priced >= $all && $all > 0
                    ? number_format($all)
                    : '<span class="badge badge-warning" title="' . $priced . ' of ' . $all . ' priced">'
                        . $priced . ' / ' . $all . '</span>';
            }],
            ['Tonnes', 'weight', $this->tonnesCell('weight')],
            ['Quoted Value', 'value', $this->moneyCell('value')],
            ['Stage', 'stage', fn ($r) => $this->stageCell($r)],
        ];

        $money = ['value'];
        $weights = ['weight'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()->orderByDesc('s.date')->orderByDesc('s.id'),
                'totals' => $this->moneyTotals($money, $weights),
                'sortable' => ['number', 'date', 'customer', 'country', 'lines', 'weight', 'value'],
            ],

            'unpriced' => [
                'label' => 'Not yet priced',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                // An order with no proforma at all, or one whose proforma
                // leaves some of its lines without a price — both mean the
                // order cannot yet be invoiced in full.
                'query' => fn () => $this->select()
                    ->whereRaw(SalesFigures::ORDER_PRICED_LINES . ' < ' . SalesFigures::ORDER_LINES)
                    ->orderByDesc('s.date')->orderByDesc('s.id'),
                'totals' => $this->moneyTotals($money, $weights),
                'sortable' => ['number', 'date', 'customer', 'lines', 'weight'],
            ],

            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customer'],
                    ['Country', 'country'],
                    // Grouped by currency as well, because a customer quoted in
                    // two of them cannot have one meaningful value — so the
                    // column has to be here saying which row is which.
                    ['Currency', 'currency'],
                    ['Orders', 'orders'],
                    ['Tonnes', 'weight', $this->tonnesCell('weight')],
                    ['Quoted Value', 'value', $this->moneyCell('value')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(`c`.`customername`, '—') as `customer`,"
                    . " COALESCE(`c`.`customercountry`, '') as `country`,"
                    . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(DISTINCT `s`.`id`) as `orders`,'
                    . ' ROUND(SUM(' . SalesFigures::ORDER_WEIGHT . '), 2) as `weight`,'
                    . ' ROUND(SUM(' . SalesFigures::ORDER_QUOTED_VALUE . '), 2) as `value`'
                )->groupBy('c.customername', 'c.customercountry', 'cur.code', 'cur.symbol')
                    ->orderByDesc(DB::raw('SUM(' . SalesFigures::ORDER_WEIGHT . ')')),
                'totals' => $this->moneyTotals($money, ['orders', 'weight']),
            ],

            'by_country' => [
                'label' => 'Summary (by country)',
                'type' => 'summary',
                'columns' => [
                    ['Country', 'country'],
                    ['Customers', 'customers'],
                    ['Orders', 'orders'],
                    ['Tonnes', 'weight', $this->tonnesCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(NULLIF(`c`.`customercountry`, ''), '—') as `country`,"
                    . ' COUNT(DISTINCT `c`.`id`) as `customers`,'
                    . ' COUNT(DISTINCT `s`.`id`) as `orders`,'
                    . ' ROUND(SUM(' . SalesFigures::ORDER_WEIGHT . '), 2) as `weight`'
                )->groupBy('country')
                    ->orderByDesc(DB::raw('SUM(' . SalesFigures::ORDER_WEIGHT . ')')),
                'totals' => ['customers', 'orders', 'weight'],
            ],

            'by_month' => [
                'label' => 'Summary (by month)',
                'type' => 'summary',
                'columns' => [
                    ['Month', 'month'],
                    ['Orders', 'orders'],
                    ['Customers', 'customers'],
                    ['Tonnes', 'weight', $this->tonnesCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                // `date` is a legacy 'Y/m/d' varchar, so the month is its first
                // seven characters — it cannot be handed to DATE_FORMAT.
                'query' => fn () => $this->base()->selectRaw(
                    'LEFT(`s`.`date`, 7) as `month`,'
                    . ' COUNT(DISTINCT `s`.`id`) as `orders`,'
                    . ' COUNT(DISTINCT `c`.`id`) as `customers`,'
                    . ' ROUND(SUM(' . SalesFigures::ORDER_WEIGHT . '), 2) as `weight`'
                )->groupBy('month')->orderByDesc('month'),
                'totals' => ['orders', 'weight'],
            ],
        ];
    }

    /* ---------------- Cells ---------------- */

    private function stageCell($row): string
    {
        if ((int) $row->shipments > 0) {
            return '<span class="badge badge-success">Shipped</span>'
                . ((int) $row->shipments > 1
                    ? ' <span class="badge badge-muted">' . (int) $row->shipments . '</span>' : '');
        }

        if ($row->approved) {
            return '<span class="badge badge-success">Approved</span>';
        }

        if ($row->has_proforma) {
            return '<span class="badge badge-muted">Proforma raised</span>';
        }

        return '<span class="badge badge-warning">No proforma</span>';
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_customer' => ['customer', 'country', 'currency'],
            'by_country' => ['country'],
            'by_month' => ['month'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Order', 'number'],
            $this->dateCol('Date', 'date'),
            ['Customer', 'customer'],
            ['Lines', 'lines'],
            ['Tonnes', 'weight', $this->tonnesCell('weight')],
            ['Quoted Value', 'value', $this->moneyCell('value')],
            ['Stage', 'stage', fn ($r) => $this->stageCell($r)],
        ];
    }

    public function detailSearchable(): array
    {
        return ['s.orderno', 's.ref', 'c.customername'];
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
            // The summary prints an empty country as '' and the by-country
            // summary prints it as an em dash; both have to find their rows.
            'country' => DB::raw("COALESCE(`c`.`customercountry`, '')"),
            'currency' => DB::raw("COALESCE(`cur`.`code`, '')"),
            'month' => DB::raw('LEFT(`s`.`date`, 7)'),
        ];

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            $q->where($columns[$field], $value);
        }

        return $q->orderByDesc('s.date')->orderByDesc('s.id');
    }

    public function detailTitle(string $key): string
    {
        return implode(' · ', array_filter(
            $this->detailKeyParts($key),
            fn ($part) => (string) $part !== ''
        ));
    }
}
