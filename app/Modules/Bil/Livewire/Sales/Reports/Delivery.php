<?php

namespace Modules\Bil\Livewire\Sales\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * BIL → Sales → Reports → Delivery. Rebuild of the legacy report_sales.php on
 * "Delivery" — Report\Sales\Delivery::option1/2/3.
 *
 * A DELIVERY IS A STATE OF A LOADING, not a table of its own quantities.
 * `sales_delivery` records the confirmation — a number, a barcode and a date —
 * and the quantities stay on the loading rows it closed, which is why the date
 * range here is applied to `sales_loading.status`: that column holds the date
 * the load was confirmed delivered, and is NULL while it is still out. The
 * legacy report did the same thing by string-replacing `dateoforder` with
 * `status` in its WHERE clause.
 *
 * So this report is the confirmed half of the Loading report. Anything loaded
 * but not yet confirmed appears there, marked in transit, and not here at all.
 */
#[Title('Sales Delivery Report')]
class Delivery extends SalesReport
{
    public function title(): string
    {
        return 'Sales Delivery Report';
    }

    public function printKey(): string
    {
        return 'delivery';
    }

    public function subtitle(): string
    {
        return 'Loads the customer has confirmed receiving — by customer, product and day.';
    }

    public function filterDefs(): array
    {
        return $this->commonFilterDefs() + ['foc' => $this->focFilterDef()];
    }

    /**
     * The delivery row that closed a loading, as SUBQUERIES rather than a join.
     *
     * ⚠️ `sales_delivery` MUST NOT BE JOINED HERE. 462 (date, load number) pairs
     * carry two delivery rows — the double confirmation the legacy screen had no
     * guard against, and which the rebuilt Delivery screen now refuses — and a
     * join to them silently doubles the bundles on those loads. It did: on
     * 31 Jan 2022 the joined version reported 429,791 bundles against the
     * 428,040 actually confirmed.
     *
     * A scalar subquery cannot return two rows, so the figures stay right and
     * the barcode shown is the first confirmation, which is the real one. Both
     * are single index lookups on `sd_date_loadnumber_idx`.
     */
    private const DELIVERY_BARCODE = '(SELECT d.barcode FROM sales_delivery d
        WHERE d.dateofdelivery = l.status AND d.loadnumber = l.loadnumber ORDER BY d.id LIMIT 1)';

    private const DELIVERY_NUMBER = '(SELECT d.deliverynumber FROM sales_delivery d
        WHERE d.dateofdelivery = l.status AND d.loadnumber = l.loadnumber ORDER BY d.id LIMIT 1)';

    /**
     * Confirmed loadings in the date range.
     *
     * Driven from `sales_loading.status` (`sl_status_idx`), which holds the date
     * the load was confirmed; NULL means still out, and is excluded here.
     *
     * The two hops to the order are LEFT joins for the same reason as on the
     * Loading report: 71,943 confirmed loadings point at an order line that no
     * longer exists, and an inner join would drop every one of them.
     */
    protected function base()
    {
        $q = DB::connection('bil')->table('sales_loading as l')
            ->leftJoin('sales_order_details as sod', 'sod.id', '=', 'l.sod_id')
            ->leftJoin('sales_order as so', 'so.orderid', '=', 'sod.orderid')
            ->leftJoin('products as p', 'p.productid', '=', 'sod.productid')
            ->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid')
            ->whereNotNull('l.status');

        return $this->applyCommonFilters($this->applyDate($q, 'l.status', slash: true))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('l.barcode', 'like', $term)
                  ->orWhere('l.trucknumber', 'like', $term)
                  ->orWhere('so.orderid', 'like', $term)
                  ->orWhere('c.customername', 'like', $term)
                  ->orWhere('p.productname', 'like', $term)
                  ->orWhere('p.productcode', 'like', $term)
                  // The delivery barcode too, which is not a column of this
                  // query — EXISTS rather than a join, for the reason above.
                  ->orWhereExists(fn ($e) => $e->from('sales_delivery as d')
                      ->whereColumn('d.dateofdelivery', 'l.status')
                      ->whereColumn('d.loadnumber', 'l.loadnumber')
                      ->where('d.barcode', 'like', $term));
            }));
    }

    /** The same rows without the two lookup joins, which cannot change a count. */
    protected function countQuery()
    {
        $q = DB::connection('bil')->table('sales_loading as l')
            ->leftJoin('sales_order_details as sod', 'sod.id', '=', 'l.sod_id')
            ->leftJoin('sales_order as so', 'so.orderid', '=', 'sod.orderid')
            ->whereNotNull('l.status');

        return $this->applyCommonFilters($this->applyDate($q, 'l.status', slash: true));
    }

    /** The delivery-line columns, shared by the Deliveries view and the modal. */
    protected function lineColumns(bool $withCustomer): array
    {
        $columns = [
            ['Delivery Barcode', 'delivery_barcode', fn ($r) => e($r->delivery_barcode ?: '—')],
            $this->dateCol('Date of Delivery', 'status'),
        ];

        if ($withCustomer) {
            $columns[] = ['Customer', 'customername', fn ($r) => $this->customerCell($r)];
        }

        return array_merge($columns, [
            ['Sales Order', 'orderid', fn ($r) => e($r->orderid ?: '—')],
            $this->dateCol('Date of Order', 'dateoforder'),
            ['Loading Barcode', 'barcode'],
            ['Truck', 'trucknumber'],
            ['Code', 'productcode', fn ($r) => e($r->productcode ?: '—')],
            ['Product', 'productname', fn ($r) => e($r->productname ?: '—')],
            ['Type', 'foc', fn ($r) => $r->foc === null ? '—' : $this->focCell($r->foc)],
            ['Ordered', 'ordered', fn ($r) => $this->num($r->ordered)],
            ['Delivered', 'delivered', fn ($r) => $this->num($r->delivered)],
        ]);
    }

    protected function lineSelect($q)
    {
        return $q->selectRaw('l.id, l.barcode, l.status, l.trucknumber, l.loadnumber,
            ' . self::DELIVERY_BARCODE . ' as delivery_barcode,
            ' . self::DELIVERY_NUMBER . ' as deliverynumber,
            so.orderid, so.dateoforder, so.customerid, c.customername,
            p.productcode, p.productname, sod.foc,
            sod.quantityordered as ordered, l.quantityloaded as delivered');
    }

    public function views(): array
    {
        return [
            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customername', fn ($r) => $this->customerCell($r)],
                    ['Deliveries', 'deliveries', fn ($r) => $this->num($r->deliveries)],
                    ['Lines', 'linecount', fn ($r) => $this->num($r->linecount)],
                    ['Delivered', 'delivered', fn ($r) => $this->num($r->delivered)],
                ],
                'sortable' => ['customername', 'deliveries', 'linecount', 'delivered'],
                'query' => fn () => $this->base()
                    ->selectRaw('so.customerid, c.customername,
                                 COUNT(DISTINCT l.barcode) as deliveries,
                                 COUNT(*) as linecount,
                                 SUM(l.quantityloaded) as delivered')
                    ->groupBy('so.customerid', 'c.customername')
                    ->orderByDesc('delivered'),
            ],
            'deliveries' => [
                'label' => 'Deliveries',
                'type' => 'table',
                'columns' => $this->lineColumns(withCustomer: true),
                // `delivery_barcode` is a subquery per row — sorting on it
                // would evaluate one for every confirmed load in the range
                // before it could order them.
                'sortable' => ['status', 'customername', 'orderid', 'dateoforder',
                    'barcode', 'trucknumber', 'productcode', 'productname', 'foc', 'ordered', 'delivered'],
                'query' => fn () => $this->lineSelect($this->base())
                    ->orderByDesc('l.status')->orderByDesc('l.id'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Deliveries', 'deliveries', fn ($r) => $this->num($r->deliveries)],
                    ['Delivered', 'delivered', fn ($r) => $this->num($r->delivered)],
                ],
                'query' => fn () => $this->base()
                    ->selectRaw('p.productcode, p.productname,
                                 COUNT(DISTINCT l.barcode) as deliveries,
                                 SUM(l.quantityloaded) as delivered')
                    ->groupBy('p.productcode', 'p.productname')
                    ->orderByDesc('delivered'),
            ],
            'daily' => [
                'label' => 'Summary (daily)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Date of Delivery', 'status'),
                    ['Deliveries', 'deliveries', fn ($r) => $this->num($r->deliveries)],
                    ['Customers', 'customers', fn ($r) => $this->num($r->customers)],
                    ['Delivered', 'delivered', fn ($r) => $this->num($r->delivered)],
                ],
                'query' => fn () => $this->base()
                    ->selectRaw('l.status,
                                 COUNT(DISTINCT l.barcode) as deliveries,
                                 COUNT(DISTINCT so.customerid) as customers,
                                 SUM(l.quantityloaded) as delivered')
                    ->groupBy('l.status')
                    ->orderByDesc('l.status'),
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
        return ['l.barcode', 'so.orderid', 'p.productname', 'p.productcode'];
    }

    public function detailQuery(string $key)
    {
        return $this->lineSelect($this->whereDetailCustomer($this->base(), $key))
            ->orderByDesc('l.status')->orderByDesc('l.id');
    }

    public function detailSubtitle(string $key): string
    {
        $row = $this->whereDetailCustomer($this->base(), $key)
            ->selectRaw('COUNT(DISTINCT l.barcode) as deliveries, COUNT(*) as linecount,
                         SUM(l.quantityloaded) as delivered, SUM(sod.quantityordered) as ordered')
            ->first();

        if (! $row) {
            return '';
        }

        return $this->num($row->deliveries) . ' delivery(s) · '
            . $this->num($row->linecount) . ' line(s) · '
            . $this->num($row->delivered) . ' bundles delivered';
    }
}
