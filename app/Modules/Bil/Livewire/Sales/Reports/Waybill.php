<?php

namespace Modules\Bil\Livewire\Sales\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * BIL → Sales → Reports → Waybill. Rebuild of the legacy report_waybill.php —
 * Report\Sales\Waybill::option1, which listed customer, waybill number, receipt
 * number, truck, date and transport cost with the cost totalled at the foot.
 *
 * This is the haulage bill: what each delivery cost to move, and who moved it.
 * Only a delivery carried by a hired truck gets one — a customer collecting in
 * their own vehicle has no haulier to pay — so the waybill count is always well
 * short of the delivery count and that is not a gap in the data.
 *
 * The chain to the customer is four hops (waybill → delivery → loading → order),
 * and a load has one row per product, so the LOADING SIDE FANS OUT. Everything
 * here is therefore grouped by the waybill itself: the cost is the waybill's and
 * must never be summed once per product line on the truck, which would multiply
 * a ₦120,000 bill by however many products it carried.
 */
#[Title('Sales Waybill Report')]
class Waybill extends SalesReport
{
    public function title(): string
    {
        return 'Sales Waybill Report';
    }

    public function printKey(): string
    {
        return 'waybill';
    }

    public function subtitle(): string
    {
        return 'Transport costs per delivery — by customer, transporter and day.';
    }

    public function filterDefs(): array
    {
        $o = $this->salesOptions();

        return [
            'warehouse' => ['label' => 'Depot', 'options' => $o['warehouses']],
            'customer' => ['label' => 'Customer', 'options' => $o['customers']],
            'transporter' => ['label' => 'Transporter', 'options' => $o['transporters']],
            'receipt' => ['label' => 'Receipt', 'options' => [
                'yes' => 'Receipt number recorded', 'no' => 'No receipt number',
            ]],
        ];
    }

    /**
     * Waybills in the date range, out to the customer that was delivered to.
     *
     * `sales_waybill.deliverynumber` is a VARCHAR against an INT on
     * `sales_delivery`, so the comparison is cast — on the waybill side, which
     * is the outer value, leaving `sd_date_number_idx` usable for the lookup.
     * Casting the other way round would have made the index dead.
     *
     * Then delivery → loading on (date, load number), the pair that identifies a
     * load, using `sl_status_loadnumber_idx`. Without that index this report was
     * 39.5 SECONDS for a year; with it, 577ms.
     *
     * Every join is LEFT: a waybill whose delivery has since been undone still
     * cost money and still belongs on the bill.
     */
    protected function base()
    {
        $f = $this->filters;

        $q = DB::connection('bil')->table('sales_waybill as w')
            ->leftJoin('sales_delivery as d', function ($j) {
                $j->on('d.dateofdelivery', '=', 'w.dateofwaybill')
                  ->whereRaw('d.deliverynumber = CAST(w.deliverynumber AS UNSIGNED)');
            })
            ->leftJoin('sales_loading as l', function ($j) {
                $j->on('l.status', '=', 'd.dateofdelivery')
                  ->on('l.loadnumber', '=', 'd.loadnumber');
            })
            ->leftJoin('sales_order_details as sod', 'sod.id', '=', 'l.sod_id')
            ->leftJoin('sales_order as so', 'so.orderid', '=', 'sod.orderid')
            ->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid')
            ->leftJoin('sales_transporters as t', 't.id', '=', 'l.transporterid');

        $q = $this->applyDate($q, 'w.dateofwaybill', slash: true)
            ->when(($f['warehouse'] ?? '') !== '', fn ($q) => $q->where('so.warehousecode', $f['warehouse']))
            ->when(($f['customer'] ?? '') !== '', fn ($q) => $q->where('so.customerid', $f['customer']))
            ->when(($f['transporter'] ?? '') !== '', fn ($q) => $q->where('l.transporterid', $f['transporter']));

        if (($f['receipt'] ?? '') === 'yes') {
            $q->whereNotNull('w.receiptnumber');
        } elseif (($f['receipt'] ?? '') === 'no') {
            $q->whereNull('w.receiptnumber');
        }

        return $q->when($this->search !== '', fn ($q) => $q->where(function ($w) {
            $term = '%' . $this->search . '%';
            $w->where('w.barcode', 'like', $term)
              ->orWhere('w.receiptnumber', 'like', $term)
              ->orWhere('d.barcode', 'like', $term)
              ->orWhere('l.trucknumber', 'like', $term)
              ->orWhere('c.customername', 'like', $term)
              ->orWhere('t.transportername', 'like', $term);
        }));
    }

    /**
     * The waybills themselves, one row each, with the fanned-out loading side
     * collapsed. Truck, transporter and customer are the same on every line of
     * a load, so MAX() picks the value rather than an arbitrary one; bundles is
     * the only figure that genuinely sums across the lines.
     */
    protected function billSelect($q)
    {
        return $q->selectRaw('w.id, w.barcode, w.dateofwaybill, w.receiptnumber, w.transportcost,
                              w.deliverynumber,
                              MAX(d.barcode) as delivery_barcode,
                              MAX(so.customerid) as customerid,
                              MAX(c.customername) as customername,
                              MAX(t.transportername) as transportername,
                              MAX(l.trucknumber) as trucknumber,
                              MAX(l.truckdriver) as truckdriver,
                              SUM(l.quantityloaded) as bundles')
            ->groupBy('w.id', 'w.barcode', 'w.dateofwaybill', 'w.receiptnumber',
                'w.transportcost', 'w.deliverynumber');
    }

    /**
     * How many waybills the filters match, counted on `sales_waybill` alone.
     *
     * Right for both the row list (which is one row per waybill) and cheap: the
     * date range is served by `sw_date_idx` and nothing else is touched. Falls
     * back to the accurate joined count as soon as a filter or the search
     * reaches past the waybill — see joinedFilterKeys() below.
     */
    protected function countQuery()
    {
        $f = $this->filters;

        $q = $this->applyDate(
            DB::connection('bil')->table('sales_waybill as w'), 'w.dateofwaybill', slash: true
        );

        if (($f['receipt'] ?? '') === 'yes') {
            $q->whereNotNull('w.receiptnumber');
        } elseif (($f['receipt'] ?? '') === 'no') {
            $q->whereNull('w.receiptnumber');
        }

        return $q;
    }

    /** Everything except the receipt filter reaches through the delivery chain. */
    protected function joinedFilterKeys(): array
    {
        return ['warehouse', 'customer', 'transporter'];
    }

    protected function billColumns(bool $withCustomer): array
    {
        $columns = [
            ['Waybill', 'barcode'],
            $this->dateCol('Date of Waybill', 'dateofwaybill'),
        ];

        if ($withCustomer) {
            $columns[] = ['Customer', 'customername', fn ($r) => e($r->customername ?: '—')];
        }

        return array_merge($columns, [
            ['Delivery', 'delivery_barcode', fn ($r) => e($r->delivery_barcode ?: '—')],
            ['Truck', 'trucknumber', fn ($r) => e($r->trucknumber ?: '—')],
            ['Transporter', 'transportername', fn ($r) => e($r->transportername ?: '—')],
            ['Bundles', 'bundles', fn ($r) => $this->qty($r->bundles)],
            ['Receipt No.', 'receiptnumber', fn ($r) => e(
                $r->receiptnumber === null || $r->receiptnumber === '' ? '—' : (string) $r->receiptnumber
            )],
            ['Transport Cost (₦)', 'transportcost', fn ($r) => $this->money($r->transportcost)],
        ]);
    }

    public function views(): array
    {
        return [
            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customername', fn ($r) => e($r->customername ?: '— unmatched delivery —')],
                    ['Waybills', 'waybills', fn ($r) => $this->num($r->waybills)],
                    ['Bundles', 'bundles', fn ($r) => $this->qty($r->bundles)],
                    ['Transport Cost (₦)', 'transportcost', fn ($r) => $this->money($r->transportcost)],
                    ['Average (₦)', 'average', fn ($r) => $this->money($r->average)],
                ],
                'sortable' => ['customername', 'waybills', 'bundles', 'transportcost', 'average'],
                // Summed off the per-waybill rows, not off the join: the cost
                // belongs to the waybill and appears once per product line in
                // the raw join. See billSelect().
                'query' => fn () => $this->fromBills(
                    'b.customerid, b.customername, COUNT(*) as waybills, SUM(b.bundles) as bundles,
                     SUM(b.transportcost) as transportcost, AVG(b.transportcost) as average',
                    ['b.customerid', 'b.customername'], 'transportcost'
                ),
            ],
            'waybills' => [
                'label' => 'Waybills',
                'type' => 'table',
                'columns' => $this->billColumns(withCustomer: true),
                'sortable' => ['barcode', 'dateofwaybill', 'customername', 'delivery_barcode',
                    'trucknumber', 'transportername', 'bundles', 'receiptnumber', 'transportcost'],
                'query' => fn () => $this->billSelect($this->base())
                    ->orderByDesc('w.dateofwaybill')->orderByDesc('w.id'),
            ],
            'by_transporter' => [
                'label' => 'Summary (by transporter)',
                'type' => 'summary',
                'columns' => [
                    ['Transporter', 'transportername', fn ($r) => e($r->transportername ?: '— none recorded —')],
                    ['Customers', 'customers', fn ($r) => $this->num($r->customers)],
                    ['Waybills', 'waybills', fn ($r) => $this->num($r->waybills)],
                    ['Bundles', 'bundles', fn ($r) => $this->qty($r->bundles)],
                    ['Transport Cost (₦)', 'transportcost', fn ($r) => $this->money($r->transportcost)],
                ],
                'query' => fn () => $this->fromBills(
                    'b.transportername, COUNT(DISTINCT b.customerid) as customers, COUNT(*) as waybills,
                     SUM(b.bundles) as bundles, SUM(b.transportcost) as transportcost',
                    ['b.transportername'], 'transportcost'
                ),
            ],
            'daily' => [
                'label' => 'Summary (daily)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Date of Waybill', 'dateofwaybill'),
                    ['Waybills', 'waybills', fn ($r) => $this->num($r->waybills)],
                    ['Customers', 'customers', fn ($r) => $this->num($r->customers)],
                    ['Bundles', 'bundles', fn ($r) => $this->qty($r->bundles)],
                    ['Transport Cost (₦)', 'transportcost', fn ($r) => $this->money($r->transportcost)],
                ],
                'query' => fn () => $this->fromBills(
                    'b.dateofwaybill, COUNT(*) as waybills, COUNT(DISTINCT b.customerid) as customers,
                     SUM(b.bundles) as bundles, SUM(b.transportcost) as transportcost',
                    ['b.dateofwaybill'], 'b.dateofwaybill'
                ),
            ],
        ];
    }

    /**
     * A summary over the per-waybill rows: billSelect() as a derived table, then
     * grouped again. Two passes rather than one because the cost has to be
     * collapsed to one row per waybill BEFORE it is totalled — group the raw
     * join directly and a bill is counted once for every product on the truck.
     */
    protected function fromBills(string $select, array $groupBy, string $orderByDesc)
    {
        return DB::connection('bil')->query()
            ->fromSub($this->billSelect($this->base()), 'b')
            ->selectRaw($select)
            ->groupBy($groupBy)
            ->orderByDesc($orderByDesc);
    }

    /* ---------------- Drill-down ---------------- */

    public function detailColumns(): array
    {
        return $this->billColumns(withCustomer: false);
    }

    public function detailSearchable(): array
    {
        return ['w.barcode', 'w.receiptnumber', 'd.barcode', 'l.trucknumber', 't.transportername'];
    }

    /**
     * The bills behind one customer row.
     *
     * `customerid` reaches the waybill only through the loading side, which is
     * the side that fans out — so the filter goes on the raw join before the
     * grouping, and a NULL id (a waybill whose delivery no longer resolves) is
     * matched as such rather than dropped.
     */
    public function detailQuery(string $key)
    {
        $id = $this->detailCustomerId($key);

        $q = $this->base();
        $q = $id === null || $this->detailKeyParts($key)[0] === ''
            ? $q->whereNull('so.customerid')
            : $q->where('so.customerid', $id);

        return $this->billSelect($q)
            ->orderByDesc('w.dateofwaybill')->orderByDesc('w.id');
    }

    public function detailSubtitle(string $key): string
    {
        $q = $this->detailQuery($key);

        if (! $q) {
            return '';
        }

        $row = DB::connection('bil')->query()->fromSub($q, 'b')
            ->selectRaw('COUNT(*) as waybills, SUM(b.bundles) as bundles,
                         SUM(b.transportcost) as cost')
            ->first();

        if (! $row) {
            return '';
        }

        return $this->num($row->waybills) . ' waybill(s) · '
            . $this->num($row->bundles) . ' bundles · ₦' . $this->money($row->cost);
    }
}
