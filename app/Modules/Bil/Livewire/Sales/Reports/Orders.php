<?php

namespace Modules\Bil\Livewire\Sales\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * BIL → Sales → Reports → Orders. Rebuild of the legacy report_sales.php with
 * the switcher on "Order" — Report\Sales\Order::option1/2/3.
 *
 * What was ordered, and how much of it has actually gone out. The legacy
 * customer view already carried Order / Delivery / Balance side by side, and
 * that comparison is the point of the report: an order is a promise, and the
 * balance is what is still owed on it.
 *
 * "Delivery" is renamed LOADED here, because that column has always been
 * SUM(quantityloaded) — what left the warehouse on a truck, not what the
 * customer has confirmed receiving. The two differ by whatever is in transit;
 * the Delivery report is where confirmed figures live.
 */
#[Title('Sales Orders Report')]
class Orders extends SalesReport
{
    /**
     * Bundles loaded against one order line.
     *
     * A correlated subquery rather than a join, because a line has many loadings
     * and joining them multiplies `quantityordered` by that count — the legacy
     * report's own GROUP BY was doing exactly that and its "Order" column was
     * wrong on any line loaded more than once. `sl_sod_id_idx` makes each lookup
     * a single index hit: a year of orders summarised by customer is 934ms.
     */
    private const LOADED = '(SELECT SUM(l.quantityloaded) FROM sales_loading l WHERE l.sod_id = sod.id)';

    private const LOADED_SUM = 'SUM(COALESCE(' . self::LOADED . ', 0))';

    public function title(): string
    {
        return 'Sales Orders Report';
    }

    public function printKey(): string
    {
        return 'orders';
    }

    public function subtitle(): string
    {
        return 'What customers ordered, what has been loaded against it, and what is still owed.';
    }

    public function filterDefs(): array
    {
        return $this->commonFilterDefs() + ['foc' => $this->focFilterDef()];
    }

    /**
     * Order lines in the date range. Driven from `sales_order`, whose
     * `so_order_date_idx` carries the range, then out to the lines through
     * `sod_orderid_idx` — the hop that only became indexable once the two
     * `orderid` columns shared a charset.
     */
    protected function base()
    {
        $q = DB::connection('bil')->table('sales_order as so')
            ->join('sales_order_details as sod', 'sod.orderid', '=', 'so.orderid')
            ->leftJoin('products as p', 'p.productid', '=', 'sod.productid')
            ->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid');

        return $this->applyCommonFilters($this->applyDate($q, 'so.dateoforder', slash: true));
    }

    /**
     * The same rows without the product and customer joins. Both are LEFT joins
     * on the lookup side, so they cannot change the count, and every filter
     * except the search sits on `sales_order` or `sales_order_details`.
     */
    protected function countQuery()
    {
        $q = DB::connection('bil')->table('sales_order as so')
            ->join('sales_order_details as sod', 'sod.orderid', '=', 'so.orderid');

        return $this->applyCommonFilters($this->applyDate($q, 'so.dateoforder', slash: true));
    }

    protected function depot($code): string
    {
        return $this->salesOptions()['warehouses'][(string) $code] ?? (string) $code ?: '—';
    }

    /** The line columns, shared by the Order Lines view and the drill-down. */
    protected function lineColumns(bool $withCustomer): array
    {
        $columns = [
            ['Sales Order', 'orderid'],
            $this->dateCol('Date of Order', 'dateoforder'),
        ];

        if ($withCustomer) {
            $columns[] = ['Customer', 'customername'];
        }

        return array_merge($columns, [
            ['Depot', 'warehousecode', fn ($r) => e($this->depot($r->warehousecode))],
            ['Code', 'productcode'],
            ['Product', 'productname'],
            ['Type', 'foc', fn ($r) => $this->focCell($r->foc)],
            ['Ordered', 'ordered', fn ($r) => $this->num($r->ordered)],
            ['Loaded', 'loaded', fn ($r) => $this->qty($r->loaded)],
            ['Balance', 'balance', fn ($r) => $this->num($r->balance)],
        ]);
    }

    protected function lineSelect($q)
    {
        return $q->selectRaw('sod.id, so.orderid, so.dateoforder, so.warehousecode, so.customerid,
                              c.customername, p.productcode, p.productname, sod.foc,
                              sod.quantityordered as ordered,
                              ' . self::LOADED . ' as loaded,
                              sod.quantityordered - COALESCE(' . self::LOADED . ', 0) as balance');
    }

    public function views(): array
    {
        return [
            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customername'],
                    ['Orders', 'orders', fn ($r) => $this->num($r->orders)],
                    ['Lines', 'linecount', fn ($r) => $this->num($r->linecount)],
                    ['Ordered', 'ordered', fn ($r) => $this->num($r->ordered)],
                    ['Loaded', 'loaded', fn ($r) => $this->qty($r->loaded)],
                    ['Balance', 'balance', fn ($r) => $this->num($r->balance)],
                ],
                'searchable' => ['c.customername'],
                'sortable' => ['customername', 'orders', 'linecount', 'ordered', 'loaded', 'balance'],
                'query' => fn () => $this->base()
                    ->selectRaw('so.customerid, c.customername,
                                 COUNT(DISTINCT so.orderid) as orders,
                                 COUNT(*) as linecount,
                                 SUM(sod.quantityordered) as ordered,
                                 ' . self::LOADED_SUM . ' as loaded,
                                 SUM(sod.quantityordered) - ' . self::LOADED_SUM . ' as balance')
                    ->groupBy('so.customerid', 'c.customername')
                    ->orderByDesc('ordered'),
            ],
            'lines' => [
                'label' => 'Order Lines',
                'type' => 'table',
                'columns' => $this->lineColumns(withCustomer: true),
                'searchable' => ['so.orderid', 'c.customername', 'p.productname', 'p.productcode'],
                // `loaded` and `balance` are correlated subqueries: sorting on
                // them would have to evaluate one per line across the whole
                // range before it could order them. The summaries below already
                // rank by those figures.
                'sortable' => ['orderid', 'dateoforder', 'customername', 'warehousecode',
                    'productcode', 'productname', 'foc', 'ordered'],
                'query' => fn () => $this->lineSelect($this->base())
                    // Along the (dateoforder) index rather than across it, newest
                    // order first, then stable on the line's own id.
                    ->orderByDesc('so.dateoforder')->orderByDesc('so.id')->orderBy('sod.id'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Lines', 'linecount', fn ($r) => $this->num($r->linecount)],
                    ['Ordered', 'ordered', fn ($r) => $this->num($r->ordered)],
                    ['Loaded', 'loaded', fn ($r) => $this->qty($r->loaded)],
                    ['Balance', 'balance', fn ($r) => $this->num($r->balance)],
                ],
                'searchable' => ['p.productname', 'p.productcode'],
                'query' => fn () => $this->base()
                    ->selectRaw('p.productcode, p.productname, COUNT(*) as linecount,
                                 SUM(sod.quantityordered) as ordered,
                                 ' . self::LOADED_SUM . ' as loaded,
                                 SUM(sod.quantityordered) - ' . self::LOADED_SUM . ' as balance')
                    ->groupBy('p.productcode', 'p.productname')
                    ->orderByDesc('ordered'),
            ],
            'daily' => [
                'label' => 'Summary (daily)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Date of Order', 'dateoforder'),
                    ['Orders', 'orders', fn ($r) => $this->num($r->orders)],
                    ['Customers', 'customers', fn ($r) => $this->num($r->customers)],
                    ['Lines', 'linecount', fn ($r) => $this->num($r->linecount)],
                    ['Ordered', 'ordered', fn ($r) => $this->num($r->ordered)],
                    ['Loaded', 'loaded', fn ($r) => $this->qty($r->loaded)],
                ],
                'searchable' => [],
                'query' => fn () => $this->base()
                    ->selectRaw('so.dateoforder,
                                 COUNT(DISTINCT so.orderid) as orders,
                                 COUNT(DISTINCT so.customerid) as customers,
                                 COUNT(*) as linecount,
                                 SUM(sod.quantityordered) as ordered,
                                 ' . self::LOADED_SUM . ' as loaded')
                    ->groupBy('so.dateoforder')
                    ->orderByDesc('so.dateoforder'),
            ],
        ];
    }

    /* ---------------- Drill-down ---------------- */

    public function detailColumns(): array
    {
        return $this->lineColumns(withCustomer: false);
    }

    public function detailSearchable(): array
    {
        return ['so.orderid', 'p.productname', 'p.productcode'];
    }

    public function detailQuery(string $key)
    {
        $id = $this->detailCustomerId($key);

        if ($id === null) {
            return null;
        }

        return $this->lineSelect($this->base()->where('so.customerid', $id))
            ->orderByDesc('so.dateoforder')->orderByDesc('so.id')->orderBy('sod.id');
    }

    /** The row's own totals, so the modal reconciles with the line it opened. */
    public function detailSubtitle(string $key): string
    {
        $id = $this->detailCustomerId($key);

        if ($id === null) {
            return '';
        }

        $row = $this->base()->where('so.customerid', $id)
            ->selectRaw('COUNT(DISTINCT so.orderid) as orders, COUNT(*) as linecount,
                         SUM(sod.quantityordered) as ordered, ' . self::LOADED_SUM . ' as loaded')
            ->first();

        if (! $row) {
            return '';
        }

        return $this->num($row->orders) . ' order(s) · ' . $this->num($row->linecount) . ' line(s) · '
            . $this->num($row->ordered) . ' ordered · ' . $this->num($row->loaded) . ' loaded · '
            . $this->num((int) $row->ordered - (int) $row->loaded) . ' balance';
    }
}
