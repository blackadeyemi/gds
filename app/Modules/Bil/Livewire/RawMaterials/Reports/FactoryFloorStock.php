<?php

namespace Modules\Bil\Livewire\RawMaterials\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * Reports → Factory Floor Stock. Raw material currently sitting on the factory
 * floor — `factory_entrance_rawmaterials` rows with status NULL (entered, not
 * yet consumed or returned). The factory-side parallel to the Warehouse Stock
 * report. A live snapshot, so there is no date range and no row actions
 * (corrections are made on the Factory Entrance report).
 *
 * This is a fast paginated worklist (single Default view). It deliberately has
 * no by-group summary rollups: the on-floor set is large (~50k legacy rows) so
 * aggregating it on every view-switch would be slow — aggregate stock rollups
 * live in the Warehouse Stock report instead.
 */
#[Title('Factory Floor Stock Report')]
class FactoryFloorStock extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Factory Floor Stock Report';
    }

    public function printKey(): string
    {
        return 'factory-floor-stock';
    }

    public function subtitle(): string
    {
        return 'Raw material currently on the factory floor (entered, not yet consumed).';
    }

    public function hasDateRange(): bool
    {
        return false; // live snapshot of the floor
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function usesSimplePagination(): bool
    {
        return true; // ~50k on-floor rows — skip the expensive COUNT(*)
    }

    protected function options(): array
    {
        return $this->optCache ??= [
            'factories' => DB::connection('bil')->table('factoryentrance_details')
                ->orderBy('id')->pluck('factoryname', 'id')->all(),
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
            'factory' => ['label' => 'Factory', 'options' => $o['factories']],
            'group' => ['label' => 'Group', 'options' => $o['groups']],
            'subgroup' => ['label' => 'Sub Group', 'options' => $o['subgroups']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
        ];
    }

    protected function base()
    {
        $q = DB::connection('bil')->table('factory_entrance_rawmaterials as f')
            ->leftJoin('rawmaterials_products as p', 'f.product_id', '=', 'p.id')
            ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
            ->leftJoin('rawmaterials_subgroups as sg', 'p.subgroupid', '=', 'sg.id')
            ->leftJoin('factoryentrance_details as fd', 'f.location_id', '=', 'fd.id')
            ->whereNull('f.status'); // still on the floor

        $this->applyFilters($q, [
            'factory' => 'f.location_id',
            'product' => 'f.product_id',
            'group' => 'p.groupid',
            'subgroup' => 'p.subgroupid',
        ]);

        return $q;
    }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Factory', 'factoryname'],
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Product', 'productname'],
                    ['Weight (kg)', 'weight'],
                    ['Date', 'entrance_date'],
                ],
                'searchable' => ['f.barcode', 'fd.factoryname', 'p.productname', 'g.groupname', 'sg.subgroupname'],
                'query' => fn () => $this->base()
                    ->select('f.id', 'f.barcode', 'fd.factoryname', 'g.groupname', 'sg.subgroupname',
                        'p.productname', DB::raw('ROUND(f.weight, 2) as weight'), 'f.entrance_date')
                    // order by the base table (joined columns can't use an index → filesort over ~50k rows)
                    ->orderByDesc('f.id'),
            ],
        ];
    }
}
