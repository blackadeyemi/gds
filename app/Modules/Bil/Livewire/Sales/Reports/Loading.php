<?php

namespace Modules\Bil\Livewire\Sales\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * BIL → Sales → Reports → Loading. Rebuild of the legacy report_sales.php on
 * "Loading" (Report\Sales\Loading::option1/2/3) plus the three pages that were
 * split off it — report_sales_loading_cageroom.php (optionCage),
 * report_sales_loading_transporter.php (optionTransport) and
 * report_sales_loading_return.php (optionReturn) — which were the same query
 * grouped three other ways and are views here.
 *
 * Loading is where finished-goods stock actually leaves the warehouse, so this
 * is the report the depot reconciles against: every bundle here has come off a
 * cageroom's floor whether or not the customer has confirmed receiving it.
 *
 * `quantityloaded` is stored NET of anything taken back off the truck at the
 * gate — the unloads in `sales_loading_return` are already deducted from it, so
 * the Returned to Store view below is a record of what happened, NOT a figure
 * to subtract again.
 */
#[Title('Sales Loading Report')]
class Loading extends SalesReport
{
    public function title(): string
    {
        return 'Sales Loading Report';
    }

    public function printKey(): string
    {
        return 'loading';
    }

    public function subtitle(): string
    {
        return 'What went onto trucks — by customer, product, cageroom and transporter.';
    }

    public function filterDefs(): array
    {
        $o = $this->salesOptions();

        return $this->commonFilterDefs() + [
            'foc' => $this->focFilterDef(),
            'cageroom' => ['label' => 'Cageroom', 'options' => $o['cagerooms']],
            'transporter' => ['label' => 'Transporter', 'options' => $o['transporters'],
                'width' => self::NAME_FILTER_WIDTH],
            'delivered' => ['label' => 'Delivery', 'options' => [
                'yes' => 'Confirmed delivered', 'no' => 'Not yet confirmed',
            ]],
        ];
    }

    /**
     * Loadings in the date range, out to the order they belong to.
     *
     * `sl_loading_date_idx` carries the range; `sl_sod_id_idx` and the order's
     * own `orderid` index carry the two hops back to the customer. That second
     * hop is the one that used to cost twenty seconds before the two `orderid`
     * columns were given the same charset.
     *
     * `status` on a loading row holds the DELIVERY DATE, or NULL while the load
     * is still out — which is what the Delivery filter reads.
     *
     * The two hops to the order are LEFT joins, not inner ones. 71,972 loadings
     * — 6.7 million bundles, almost all of them 2017-18 rows written before
     * `sod_id` was populated — point at an order line that is not there, and an
     * inner join silently drops every one of them. They went out of the
     * warehouse, so they belong in a loading report; they group under
     * "no order line" and their totals still reconcile with the table.
     */
    protected function base()
    {
        $f = $this->filters;

        $q = DB::connection('bil')->table('sales_loading as l')
            ->leftJoin('sales_order_details as sod', 'sod.id', '=', 'l.sod_id')
            ->leftJoin('sales_order as so', 'so.orderid', '=', 'sod.orderid')
            ->leftJoin('products as p', 'p.productid', '=', 'sod.productid')
            ->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid')
            ->leftJoin('sales_transporters as t', 't.id', '=', 'l.transporterid');

        $q = $this->applyCommonFilters($this->applyDate($q, 'l.dateofloading', slash: true))
            ->when(($f['cageroom'] ?? '') !== '', fn ($q) => $q->where('l.cageroomcode', $f['cageroom']))
            ->when(($f['transporter'] ?? '') !== '', fn ($q) => $q->where('l.transporterid', $f['transporter']));

        if (($f['delivered'] ?? '') === 'yes') {
            $q->whereNotNull('l.status');
        } elseif (($f['delivered'] ?? '') === 'no') {
            $q->whereNull('l.status');
        }

        return $q->when($this->search !== '', fn ($q) => $q->where(function ($w) {
            $term = '%' . $this->search . '%';
            $w->where('l.barcode', 'like', $term)
              ->orWhere('l.trucknumber', 'like', $term)
              ->orWhere('l.truckdriver', 'like', $term)
              ->orWhere('so.orderid', 'like', $term)
              ->orWhere('c.customername', 'like', $term)
              ->orWhere('p.productname', 'like', $term)
              ->orWhere('p.productcode', 'like', $term);
        }));
    }

    /**
     * The count without the three lookup joins. The order spine stays — LEFT,
     * as above, so it cannot change the count — because the depot, customer and
     * type filters live on it and dropping it would count rows they exclude.
     *
     * Null for the unload view, which counts a different table entirely.
     */
    protected function countQuery()
    {
        if ($this->currentView()['key'] === 'unloaded') {
            return null;
        }

        $f = $this->filters;

        $q = DB::connection('bil')->table('sales_loading as l')
            ->leftJoin('sales_order_details as sod', 'sod.id', '=', 'l.sod_id')
            ->leftJoin('sales_order as so', 'so.orderid', '=', 'sod.orderid');

        $q = $this->applyCommonFilters($this->applyDate($q, 'l.dateofloading', slash: true))
            ->when(($f['cageroom'] ?? '') !== '', fn ($q) => $q->where('l.cageroomcode', $f['cageroom']))
            ->when(($f['transporter'] ?? '') !== '', fn ($q) => $q->where('l.transporterid', $f['transporter']));

        if (($f['delivered'] ?? '') === 'yes') {
            $q->whereNotNull('l.status');
        } elseif (($f['delivered'] ?? '') === 'no') {
            $q->whereNull('l.status');
        }

        return $q;
    }

    /** Cageroom codes are legacy names on the core gate table. */
    protected function gate($code): string
    {
        return $this->salesOptions()['cagerooms'][(string) $code] ?? ((string) $code ?: '—');
    }

    /** The load-line columns, shared by the Loads view and the drill-down. */
    protected function lineColumns(bool $withCustomer): array
    {
        $columns = [
            ['Barcode', 'barcode'],
            $this->dateCol('Date of Loading', 'dateofloading'),
        ];

        if ($withCustomer) {
            $columns[] = ['Customer', 'customername', fn ($r) => $this->customerCell($r)];
        }

        return array_merge($columns, [
            ['Sales Order', 'orderid', fn ($r) => e($r->orderid ?: '—')],
            ['Cageroom', 'cageroomcode', fn ($r) => e($this->gate($r->cageroomcode))],
            ['Truck', 'trucknumber'],
            ['Transporter', 'transportername', fn ($r) => e($r->transportername ?: '—')],
            ['Code', 'productcode', fn ($r) => e($r->productcode ?: '—')],
            ['Product', 'productname', fn ($r) => e($r->productname ?: '—')],
            ['Type', 'foc', fn ($r) => $r->foc === null ? '—' : $this->focCell($r->foc)],
            ['Bundles', 'loaded', fn ($r) => $this->num($r->loaded)],
            // No confirmation yet: the load is LIVE — still being worked, not
            // a delivery to chase. Green rather than amber for that reason;
            // an open load is the normal state of a load, not a problem.
            ['Delivered', 'status', fn ($r) => $r->status
                ? e($this->fmtDate($r->status))
                : '<span class="badge badge-success">Live</span>'],
        ]);
    }

    protected function lineSelect($q)
    {
        return $q->select('l.id', 'l.barcode', 'l.dateofloading', 'l.cageroomcode',
            'l.trucknumber', 'l.truckdriver', 'l.status', 'so.orderid', 'so.customerid',
            'c.customername', 't.transportername', 'p.productcode', 'p.productname',
            'sod.foc', 'l.quantityloaded as loaded');
    }

    public function views(): array
    {
        return [
            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customername', fn ($r) => $this->customerCell($r)],
                    ['Loads', 'loads', fn ($r) => $this->num($r->loads)],
                    ['Lines', 'linecount', fn ($r) => $this->num($r->linecount)],
                    ['Bundles', 'loaded', fn ($r) => $this->num($r->loaded)],
                    ['Live', 'intransit', fn ($r) => $this->qty($r->intransit)],
                ],
                'sortable' => ['customername', 'loads', 'linecount', 'loaded', 'intransit'],
                'query' => fn () => $this->base()
                    ->selectRaw('so.customerid, c.customername,
                                 COUNT(DISTINCT l.barcode) as loads,
                                 COUNT(*) as linecount,
                                 SUM(l.quantityloaded) as loaded,
                                 SUM(CASE WHEN l.status IS NULL THEN l.quantityloaded ELSE 0 END) as intransit')
                    ->groupBy('so.customerid', 'c.customername')
                    ->orderByDesc('loaded'),
            ],
            'loads' => [
                'label' => 'Loads',
                'type' => 'table',
                'columns' => $this->lineColumns(withCustomer: true),
                'sortable' => ['barcode', 'dateofloading', 'customername', 'orderid', 'cageroomcode',
                    'trucknumber', 'transportername', 'productcode', 'productname', 'foc', 'loaded', 'status'],
                'query' => fn () => $this->lineSelect($this->base())
                    // Along the (dateofloading) index, newest first, then stable
                    // on the row's own id.
                    ->orderByDesc('l.dateofloading')->orderByDesc('l.id'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Loads', 'loads', fn ($r) => $this->num($r->loads)],
                    ['Bundles', 'loaded', fn ($r) => $this->num($r->loaded)],
                ],
                'query' => fn () => $this->base()
                    ->selectRaw('p.productcode, p.productname,
                                 COUNT(DISTINCT l.barcode) as loads, SUM(l.quantityloaded) as loaded')
                    ->groupBy('p.productcode', 'p.productname')
                    ->orderByDesc('loaded'),
            ],
            'by_cageroom' => [
                'label' => 'Summary (by cageroom)',
                'type' => 'summary',
                'columns' => [
                    ['Cageroom', 'cageroomcode', fn ($r) => e($this->gate($r->cageroomcode))],
                    ['Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Loads', 'loads', fn ($r) => $this->num($r->loads)],
                    ['Bundles', 'loaded', fn ($r) => $this->num($r->loaded)],
                ],
                'query' => fn () => $this->base()
                    ->selectRaw('l.cageroomcode, p.productcode, p.productname,
                                 COUNT(DISTINCT l.barcode) as loads, SUM(l.quantityloaded) as loaded')
                    ->groupBy('l.cageroomcode', 'p.productcode', 'p.productname')
                    ->orderBy('l.cageroomcode')->orderByDesc('loaded'),
            ],
            'by_transporter' => [
                'label' => 'Summary (by transporter)',
                'type' => 'summary',
                'columns' => [
                    ['Transporter', 'transportername', fn ($r) => e($r->transportername ?: '— none recorded —')],
                    ['Customers', 'customers', fn ($r) => $this->num($r->customers)],
                    ['Loads', 'loads', fn ($r) => $this->num($r->loads)],
                    ['Bundles', 'loaded', fn ($r) => $this->num($r->loaded)],
                ],
                'query' => fn () => $this->base()
                    ->selectRaw('t.transportername,
                                 COUNT(DISTINCT so.customerid) as customers,
                                 COUNT(DISTINCT l.barcode) as loads,
                                 SUM(l.quantityloaded) as loaded')
                    ->groupBy('t.transportername')
                    ->orderByDesc('loaded'),
            ],
            'daily' => [
                'label' => 'Summary (daily)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Date of Loading', 'dateofloading'),
                    ['Loads', 'loads', fn ($r) => $this->num($r->loads)],
                    ['Customers', 'customers', fn ($r) => $this->num($r->customers)],
                    ['Bundles', 'loaded', fn ($r) => $this->num($r->loaded)],
                ],
                'query' => fn () => $this->base()
                    ->selectRaw('l.dateofloading,
                                 COUNT(DISTINCT l.barcode) as loads,
                                 COUNT(DISTINCT so.customerid) as customers,
                                 SUM(l.quantityloaded) as loaded')
                    ->groupBy('l.dateofloading')
                    ->orderByDesc('l.dateofloading'),
            ],
            'unloaded' => [
                'label' => 'Returned to Store',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    $this->dateCol('Date of Loading', 'dateofloading'),
                    ['Customer', 'customername'],
                    ['Cageroom', 'cageroomcode', fn ($r) => e($this->gate($r->cageroomcode))],
                    ['Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Unloaded', 'quantityunloaded', fn ($r) => $this->num($r->quantityunloaded)],
                    ['Time', 'timestamp', fn ($r) => e($r->timestamp
                        ? date('d/M/Y H:i', (int) $r->timestamp) : '—')],
                ],
                'sortable' => ['barcode', 'dateofloading', 'customername', 'cageroomcode',
                    'productcode', 'productname', 'quantityunloaded', 'timestamp'],
                // Joined on (sod_id, barcode) rather than `loading_id`, which
                // 58,364 of the older rows never had. Driven from the loading
                // side so the date range is the indexed one and every filter on
                // this page still applies.
                //
                // Only rows where something actually came back: the gate writes
                // a zero row for every line it checks, and 302,004 of the
                // 336,373 unload rows say nothing was taken off.
                'query' => fn () => $this->base()
                    ->join('sales_loading_return as u', function ($j) {
                        $j->on('u.sod_id', '=', 'l.sod_id')->on('u.barcode', '=', 'l.barcode');
                    })
                    ->where('u.quantityunloaded', '>', 0)
                    ->select('u.id', 'l.barcode', 'l.dateofloading', 'l.cageroomcode',
                        'c.customername', 'p.productcode', 'p.productname',
                        'u.quantityunloaded', 'u.timestamp')
                    ->orderByDesc('l.dateofloading')->orderByDesc('u.id'),
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
        return ['l.barcode', 'l.trucknumber', 'so.orderid', 'p.productname', 'p.productcode'];
    }

    public function detailQuery(string $key)
    {
        return $this->lineSelect($this->whereDetailCustomer($this->base(), $key))
            ->orderByDesc('l.dateofloading')->orderByDesc('l.id');
    }

    public function detailSubtitle(string $key): string
    {
        $row = $this->whereDetailCustomer($this->base(), $key)
            ->selectRaw('COUNT(DISTINCT l.barcode) as loads, COUNT(*) as linecount,
                         SUM(l.quantityloaded) as loaded,
                         SUM(CASE WHEN l.status IS NULL THEN l.quantityloaded ELSE 0 END) as intransit')
            ->first();


        if (! $row) {
            return '';
        }

        return $this->num($row->loads) . ' load(s) · ' . $this->num($row->linecount) . ' line(s) · '
            . $this->num($row->loaded) . ' bundles · '
            . $this->num($row->intransit) . ' still live';
    }
}
