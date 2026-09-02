<?php

namespace Modules\Bil\Livewire\Sales\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * BIL → Sales → Reports → Returns. Rebuild of the legacy report_sales.php on
 * "Return" — Report\Sales\Returned::option1/2/3.
 *
 * Goods coming back FROM A CUSTOMER, over `sales_return`. Not to be confused
 * with the Loading report's "Returned to Store" view, which is stock taken back
 * off a truck at the cageroom before it ever left.
 *
 * Two quantities, and they are not the same thing: RETURNED came back in
 * saleable condition and goes to stock, REJECTED came back damaged and goes to
 * the damaged-goods warehouse. Totalling them together would double-count what
 * the customer sent, so they stay in separate columns everywhere.
 *
 * A return is booked against a specific order line, so every one of these rows
 * carries the sales order it came from — which is what makes the customer
 * grouping possible at all.
 */
#[Title('Sales Returns Report')]
class Returns extends SalesReport
{
    public function title(): string
    {
        return 'Sales Returns Report';
    }

    public function printKey(): string
    {
        return 'returns';
    }

    public function subtitle(): string
    {
        return 'What customers sent back — returned to stock and rejected as damaged.';
    }

    public function filterDefs(): array
    {
        return $this->commonFilterDefs() + ['foc' => $this->focFilterDef()];
    }

    /**
     * Returns in the date range.
     *
     * `dateofreturn` has no index and does not need one: the whole table is
     * 2,384 rows after eleven years, so a scan of it costs less than the index
     * lookup would. Everything expensive here is on the other side of the join,
     * and both hops (`sr_sod_id_idx`, then the order's `orderid`) are indexed.
     *
     * Both hops are LEFT joins so a return whose order line has since been
     * deleted still appears — there is one, carrying 100 bundles, and an inner
     * join loses it without saying so.
     */
    protected function base()
    {
        $q = DB::connection('bil')->table('sales_return as r')
            ->leftJoin('sales_order_details as sod', 'sod.id', '=', 'r.sod_id')
            ->leftJoin('sales_order as so', 'so.orderid', '=', 'sod.orderid')
            ->leftJoin('products as p', 'p.productid', '=', 'sod.productid')
            ->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid');

        return $this->applyCommonFilters($this->applyDate($q, 'r.dateofreturn', slash: true))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('so.orderid', 'like', $term)
                  ->orWhere('c.customername', 'like', $term)
                  ->orWhere('p.productname', 'like', $term)
                  ->orWhere('p.productcode', 'like', $term)
                  ->orWhere('r.username', 'like', $term);
            }));
    }

    /** The line columns, shared by the Returns view and the drill-down. */
    protected function lineColumns(bool $withCustomer): array
    {
        $columns = [
            ['Return No.', 'returnnumber'],
            $this->dateCol('Date of Return', 'dateofreturn'),
        ];

        if ($withCustomer) {
            $columns[] = ['Customer', 'customername', fn ($r) => $this->customerCell($r)];
        }

        return array_merge($columns, [
            ['Sales Order', 'orderid', fn ($r) => e($r->orderid ?: '—')],
            ['Code', 'productcode', fn ($r) => e($r->productcode ?: '—')],
            ['Product', 'productname', fn ($r) => e($r->productname ?: '—')],
            ['Type', 'foc', fn ($r) => $r->foc === null ? '—' : $this->focCell($r->foc)],
            ['Returned', 'returned', fn ($r) => $this->num($r->returned)],
            ['Rejected', 'rejected', fn ($r) => $this->num($r->rejected)],
            ['Booked by', 'username', fn ($r) => e($r->username ?: '—')],
        ]);
    }

    protected function lineSelect($q)
    {
        return $q->select('r.id', 'r.returnnumber', 'r.dateofreturn', 'r.username',
            'so.orderid', 'so.customerid', 'c.customername', 'p.productcode', 'p.productname',
            'sod.foc', 'r.quantityreturned as returned', 'r.quantityrejected as rejected');
    }

    public function views(): array
    {
        return [
            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customername', fn ($r) => $this->customerCell($r)],
                    ['Returns', 'returns', fn ($r) => $this->num($r->returns)],
                    ['Lines', 'linecount', fn ($r) => $this->num($r->linecount)],
                    ['Returned', 'returned', fn ($r) => $this->num($r->returned)],
                    ['Rejected', 'rejected', fn ($r) => $this->num($r->rejected)],
                ],
                'sortable' => ['customername', 'returns', 'linecount', 'returned', 'rejected'],
                // A return number restarts every day, so a return is identified
                // by (date, number) — counting the number alone would merge
                // every Monday's first return into one.
                'query' => fn () => $this->base()
                    ->selectRaw('so.customerid, c.customername,
                                 COUNT(DISTINCT CONCAT(r.dateofreturn, "#", r.returnnumber)) as returns,
                                 COUNT(*) as linecount,
                                 SUM(r.quantityreturned) as returned,
                                 SUM(r.quantityrejected) as rejected')
                    ->groupBy('so.customerid', 'c.customername')
                    ->orderByDesc('returned'),
            ],
            'returns' => [
                'label' => 'Return Lines',
                'type' => 'table',
                'columns' => $this->lineColumns(withCustomer: true),
                'sortable' => ['returnnumber', 'dateofreturn', 'customername', 'orderid',
                    'productcode', 'productname', 'foc', 'returned', 'rejected', 'username'],
                'query' => fn () => $this->lineSelect($this->base())
                    ->orderByDesc('r.dateofreturn')->orderByDesc('r.id'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Lines', 'linecount', fn ($r) => $this->num($r->linecount)],
                    ['Returned', 'returned', fn ($r) => $this->num($r->returned)],
                    ['Rejected', 'rejected', fn ($r) => $this->num($r->rejected)],
                ],
                'query' => fn () => $this->base()
                    ->selectRaw('p.productcode, p.productname, COUNT(*) as linecount,
                                 SUM(r.quantityreturned) as returned,
                                 SUM(r.quantityrejected) as rejected')
                    ->groupBy('p.productcode', 'p.productname')
                    ->orderByDesc('returned'),
            ],
            'daily' => [
                'label' => 'Summary (daily)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Date of Return', 'dateofreturn'),
                    ['Returns', 'returns', fn ($r) => $this->num($r->returns)],
                    ['Customers', 'customers', fn ($r) => $this->num($r->customers)],
                    ['Returned', 'returned', fn ($r) => $this->num($r->returned)],
                    ['Rejected', 'rejected', fn ($r) => $this->num($r->rejected)],
                ],
                'query' => fn () => $this->base()
                    ->selectRaw('r.dateofreturn,
                                 COUNT(DISTINCT r.returnnumber) as returns,
                                 COUNT(DISTINCT so.customerid) as customers,
                                 SUM(r.quantityreturned) as returned,
                                 SUM(r.quantityrejected) as rejected')
                    ->groupBy('r.dateofreturn')
                    ->orderByDesc('r.dateofreturn'),
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
        return ['so.orderid', 'p.productname', 'p.productcode', 'r.username'];
    }

    public function detailQuery(string $key)
    {
        return $this->lineSelect($this->whereDetailCustomer($this->base(), $key))
            ->orderByDesc('r.dateofreturn')->orderByDesc('r.id');
    }

    public function detailSubtitle(string $key): string
    {
        $row = $this->whereDetailCustomer($this->base(), $key)
            ->selectRaw('COUNT(*) as linecount,
                         SUM(r.quantityreturned) as returned,
                         SUM(r.quantityrejected) as rejected')
            ->first();

        if (! $row) {
            return '';
        }

        return $this->num($row->linecount) . ' line(s) · '
            . $this->num($row->returned) . ' returned to stock · '
            . $this->num($row->rejected) . ' rejected as damaged';
    }
}
