<?php

namespace Modules\Bil\Livewire\RawMaterials\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * Reports → Warehouse Exit. Raw material issued out of the store, over
 * `rawmaterials_warehouse_exit`. Rebuilt from the legacy store-exit report
 * (Report\Rawmaterials\Storeexit). Read-only — this is a historical log; the
 * exit lifecycle is driven by the Warehouse Exit / Factory Returns pages.
 *
 * Product/group/sub-group + weight are resolved by joining the barcode back to
 * warehouse-entry (both barcode columns are latin1, so no CONVERT needed).
 */
#[Title('Warehouse Exit Report')]
class WarehouseExit extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Warehouse Exit Report';
    }

    public function printKey(): string
    {
        return 'warehouse-exit';
    }

    public function subtitle(): string
    {
        return 'Raw material issued out of the store.';
    }

    public function readOnly(): bool
    {
        return true;
    }

    protected function options(): array
    {
        return $this->optCache ??= [
            'locations' => DB::connection('bil')->table('rawmaterial_store_location')
                ->orderBy('id')->pluck('location', 'id')->all(),
            'products' => DB::connection('bil')->table('rawmaterials_products')
                ->orderBy('productname')->pluck('productname', 'id')->all(),
            'groups' => DB::connection('bil')->table('rawmaterials_groups')
                ->orderBy('groupname')->pluck('groupname', 'id')->all(),
            'subgroups' => DB::connection('bil')->table('rawmaterials_subgroups')
                ->orderBy('subgroupname')->pluck('subgroupname', 'id')->all(),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'location' => ['label' => 'Location', 'options' => $o['locations']],
            'group' => ['label' => 'Group', 'options' => $o['groups']],
            'subgroup' => ['label' => 'Sub Group', 'options' => $o['subgroups']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
        ];
    }

    protected function base()
    {
        $q = DB::connection('bil')->table('rawmaterials_warehouse_exit as we')
            // Plain indexed barcode join (both latin1). No dedup subquery — it was
            // ~100x slower on wide ranges (correlated MIN per row) and buys almost
            // nothing (recent exits have no duplicate barcodes).
            ->leftJoin('rawmaterials_warehouse_entry as r', 'we.barcode', '=', 'r.barcode')
            ->leftJoin('rawmaterials_products as p', 'r.productid', '=', 'p.id')
            ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
            ->leftJoin('rawmaterials_subgroups as sg', 'p.subgroupid', '=', 'sg.id');
            // NB: no store-location join — `we.location_id` is varchar but
            // store_location.id is int, and that type-mismatched join defeats the
            // ordered-index LIMIT (→ full scan + filesort of 310k, ~70s). The
            // location name is resolved in PHP instead (locationName()).

        $this->applyDate($q, 'we.dateofcreation');
        $this->applyFilters($q, [
            'location' => 'we.location_id',
            'product' => 'r.productid',
            'group' => 'p.groupid',
            'subgroup' => 'p.subgroupid',
        ]);

        return $q;
    }

    /** Resolve a store location name from its id (varchar) without a SQL join. */
    protected ?array $locationMap = null;

    protected function locationName($id): string
    {
        $this->locationMap ??= DB::connection('bil')->table('rawmaterial_store_location')
            ->pluck('location', 'id')->all();

        return $this->locationMap[(int) $id] ?? '—';
    }

    public function views(): array
    {
        $location = fn ($row) => e($this->locationName($row->location_id));

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Location', 'location_id', $location],
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Product', 'productname'],
                    ['Weight (kg)', 'weight'],
                    ['Date', 'dateofcreation'],
                ],
                'searchable' => ['we.barcode', 'p.productname', 'g.groupname', 'sg.subgroupname'],
                'query' => fn () => $this->base()
                    ->select('we.id', 'we.barcode', 'we.location_id', 'g.groupname', 'sg.subgroupname',
                        'p.productname', DB::raw('ROUND(r.weight, 2) as weight'), 'we.dateofcreation')
                    // id is chronological → recent-first via the PK (fast on any date range).
                    ->orderByDesc('we.id'),
            ],
            'by_subgroup' => [
                'label' => 'Summary (by sub group)',
                'type' => 'summary',
                'columns' => [
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['g.groupname', 'sg.subgroupname'],
                'query' => fn () => $this->base()
                    ->selectRaw('g.groupname, sg.subgroupname, COUNT(*) as quantity, ROUND(SUM(r.weight), 2) as weight')
                    ->groupBy('g.groupname', 'sg.subgroupname')
                    ->orderBy('g.groupname')->orderBy('sg.subgroupname'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Product', 'productname'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['p.productname'],
                'query' => fn () => $this->base()
                    ->selectRaw('p.productname, COUNT(*) as quantity, ROUND(SUM(r.weight), 2) as weight')
                    ->groupBy('p.productname')->orderByDesc(DB::raw('SUM(r.weight)')),
            ],
        ];
    }
}
