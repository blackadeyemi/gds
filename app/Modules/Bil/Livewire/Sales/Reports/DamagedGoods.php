<?php

namespace Modules\Bil\Livewire\Sales\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Support\FinishedGoodsStock;

/**
 * BIL → Sales → Reports → Damaged Goods. Finished goods that came back from a
 * customer UNSELLABLE — `sales_return.quantityrejected`.
 *
 * It sits under Sales rather than Finished Goods because that is where the
 * figure is entered: the Returns screen records what came back and how much of
 * it was rejected, and this report is the read-out of the rejected half. The
 * stock itself lands in the Damaged Goods (FG) warehouse, counted but never
 * sellable, and the current holding is shown in the subtitle.
 *
 * ⚠️ REJECTED IS PART OF RETURNED, NOT ADDITIONAL TO IT. A return of 100 with
 * 30 rejected put 70 bundles back into sellable stock and 30 into the damaged
 * warehouse — it did not bring back 130. That is why the Returns report keeps
 * the two in separate columns and this one reports only the rejected part.
 *
 * Not to be confused with Raw Materials → Reports → Damaged Goods, which is
 * raw material written off inside the factory, over a different table entirely.
 */
#[Title('Damaged Goods Report')]
class DamagedGoods extends SalesReport
{
    public function title(): string
    {
        return 'Damaged Goods Report';
    }

    public function printKey(): string
    {
        return 'damaged-goods';
    }

    /**
     * What is held right now, alongside what came back in the range — two
     * different questions, and the holding is not a figure a date range can
     * answer, so it goes here rather than pretending to be a view.
     */
    public function subtitle(): string
    {
        $held = $this->heldNow();

        return 'Finished goods returned by customers as unsellable. '
            . ($held === null
                ? 'No damaged-goods warehouse is set up.'
                : number_format($held) . ' bundle(s) held in the damaged-goods warehouse now.');
    }

    protected function heldNow(): ?int
    {
        $warehouse = FinishedGoodsStock::damagedWarehouseId();

        if ($warehouse === null) {
            return null;
        }

        return (int) DB::connection('bil')->table('finished_goods_warehouse_stock')
            ->where('warehouse_id', $warehouse)->sum('bundles');
    }

    public function filterDefs(): array
    {
        return $this->commonFilterDefs();
    }

    /**
     * Rejected return lines in the date range.
     *
     * `quantityrejected > 0` is the whole report: a return row with nothing
     * rejected is a clean return and belongs on the Returns report, not here.
     * The order spine is LEFT joined so a rejection whose order line has been
     * deleted still appears — the bundles are in the warehouse either way.
     */
    protected function base()
    {
        $q = DB::connection('bil')->table('sales_return as r')
            ->leftJoin('sales_order_details as sod', 'sod.id', '=', 'r.sod_id')
            ->leftJoin('sales_order as so', 'so.orderid', '=', 'sod.orderid')
            ->leftJoin('products as p', 'p.productid', '=', 'sod.productid')
            ->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid')
            ->where('r.quantityrejected', '>', 0);

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

    /** The line columns, shared by the Rejections view and the drill-down. */
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
            ['Rejected', 'rejected', fn ($r) => $this->num($r->rejected)],
            ['Of a return of', 'returned', fn ($r) => $this->num($r->returned)],
            ['Share', 'rejected', fn ($r) => e($this->share($r))],
            ['Booked by', 'username', fn ($r) => e($r->username ?: '—')],
        ]);
    }

    /** How much of what came back was unsellable — the number worth watching. */
    protected function share($row): string
    {
        $returned = (int) ($row->returned ?? 0);

        return $returned <= 0 ? '—' : round((int) $row->rejected / $returned * 100) . '%';
    }

    protected function lineSelect($q)
    {
        return $q->select('r.id', 'r.returnnumber', 'r.dateofreturn', 'r.username',
            'so.orderid', 'so.customerid', 'c.customername', 'p.productcode', 'p.productname',
            'r.quantityreturned as returned', 'r.quantityrejected as rejected');
    }

    public function views(): array
    {
        return [
            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customername', fn ($r) => $this->customerCell($r)],
                    ['Rejections', 'linecount', fn ($r) => $this->num($r->linecount)],
                    ['Rejected', 'rejected', fn ($r) => $this->num($r->rejected)],
                    ['Of a return of', 'returned', fn ($r) => $this->num($r->returned)],
                    ['Share', 'rejected', fn ($r) => e($this->share($r))],
                ],
                'sortable' => ['customername', 'linecount', 'rejected', 'returned'],
                'query' => fn () => $this->base()
                    ->selectRaw('so.customerid, c.customername, COUNT(*) as linecount,
                                 SUM(r.quantityrejected) as rejected,
                                 SUM(r.quantityreturned) as returned')
                    ->groupBy('so.customerid', 'c.customername')
                    ->orderByDesc('rejected'),
            ],
            'rejections' => [
                'label' => 'Rejections',
                'type' => 'table',
                'columns' => $this->lineColumns(withCustomer: true),
                // Share is computed per row from two columns, not selected.
                'sortable' => ['returnnumber', 'dateofreturn', 'customername', 'orderid',
                    'productcode', 'productname', 'rejected', 'returned', 'username'],
                'query' => fn () => $this->lineSelect($this->base())
                    ->orderByDesc('r.dateofreturn')->orderByDesc('r.id'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Rejections', 'linecount', fn ($r) => $this->num($r->linecount)],
                    ['Rejected', 'rejected', fn ($r) => $this->num($r->rejected)],
                    ['Of a return of', 'returned', fn ($r) => $this->num($r->returned)],
                    ['Share', 'rejected', fn ($r) => e($this->share($r))],
                ],
                'sortable' => ['productcode', 'productname', 'linecount', 'rejected', 'returned'],
                'query' => fn () => $this->base()
                    ->selectRaw('p.productcode, p.productname, COUNT(*) as linecount,
                                 SUM(r.quantityrejected) as rejected,
                                 SUM(r.quantityreturned) as returned')
                    ->groupBy('p.productcode', 'p.productname')
                    ->orderByDesc('rejected'),
            ],
            'daily' => [
                'label' => 'Summary (daily)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Date of Return', 'dateofreturn'),
                    ['Customers', 'customers', fn ($r) => $this->num($r->customers)],
                    ['Rejections', 'linecount', fn ($r) => $this->num($r->linecount)],
                    ['Rejected', 'rejected', fn ($r) => $this->num($r->rejected)],
                ],
                'sortable' => ['dateofreturn', 'customers', 'linecount', 'rejected'],
                'query' => fn () => $this->base()
                    ->selectRaw('r.dateofreturn, COUNT(DISTINCT so.customerid) as customers,
                                 COUNT(*) as linecount, SUM(r.quantityrejected) as rejected')
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
            ->selectRaw('COUNT(*) as linecount, SUM(r.quantityrejected) as rejected,
                         SUM(r.quantityreturned) as returned')
            ->first();

        if (! $row) {
            return '';
        }

        return $this->num($row->linecount) . ' rejection(s) · '
            . $this->num($row->rejected) . ' bundles unsellable · '
            . $this->share($row) . ' of the ' . $this->num($row->returned) . ' sent back';
    }
}
