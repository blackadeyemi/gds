<?php

namespace Modules\Bil\Livewire\RawMaterials\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\FactoryGate;
use Livewire\Attributes\Title;
use Modules\Bil\Models\RawMaterialFactoryEntrance;

/**
 * Reports → Factory Entrance. Raw material scanned onto the factory floor, over
 * `factory_entrance_rawmaterials`. Rebuilt from the legacy factory-entrance
 * report. The row carries product_id + weight directly, so product/group/
 * sub-group join straight off product_id; the factory name comes from
 * `factoryentrance_details`.
 *
 * Edit corrects the weight; Delete removes the entrance record — both DISABLED
 * once the item has progressed (status not NULL, i.e. consumed/returned). There
 * is NO stock effect (gds moves stock at Warehouse Exit, not here).
 */
#[Title('Factory Entrance Report')]
class FactoryEntrance extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Factory Entrance Report';
    }

    public function printKey(): string
    {
        return 'factory-entrance';
    }

    public function subtitle(): string
    {
        return 'Raw material scanned onto the factory floor.';
    }


    /**
     * Gates, for the filter.
     *
     * Only gds movements carry a `gate_id` — the legacy app never recorded which
     * gate was used, and the historic rows were deliberately not backfilled with
     * a guess. So this filter narrows to movements booked through gds; the
     * Location filter is still the one that covers all of history.
     */
    protected function options(): array
    {
        return $this->optCache ??= [
            'gates' => FactoryGate::ordered()->pluck('name', 'id')->all(),
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
            'gate' => ['label' => 'Factory Gate', 'options' => $o['gates']],
            'status' => ['label' => 'Status', 'options' => ['consumed' => 'Consumed', 'return' => 'Returned']],
        ];
    }

    /** Only group/sub-group come off the joined products table; the rest are base columns. */
    protected function joinedFilterKeys(): array
    {
        return ['group', 'subgroup'];
    }

    /**
     * Fast total: the entrance table alone, with the date range and its
     * base-column filters (factory, product, status). Same number as the joined
     * count — 2ms against 4.9s at all-time.
     */
    protected function countQuery()
    {
        $q = DB::connection('bil')->table('factory_entrance_rawmaterials as f');
        $this->applyDate($q, 'f.entrance_date');
        $this->applyFilters($q, [
            'factory' => 'f.location_id',
            'gate' => 'f.gate_id',
            'product' => 'f.product_id',
            'status' => 'f.status',
        ]);

        return $q;
    }

    protected function base()
    {
        $q = DB::connection('bil')->table('factory_entrance_rawmaterials as f')
            ->leftJoin('rawmaterials_products as p', 'f.product_id', '=', 'p.id')
            ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
            ->leftJoin('rawmaterials_subgroups as sg', 'p.subgroupid', '=', 'sg.id')
            ->leftJoin('factoryentrance_details as fd', 'f.location_id', '=', 'fd.id');

        $this->applyDate($q, 'f.entrance_date');
        $this->applyFilters($q, [
            'factory' => 'f.location_id',
            'gate' => 'f.gate_id',
            'product' => 'f.product_id',
            'group' => 'p.groupid',
            'subgroup' => 'p.subgroupid',
            'status' => 'f.status',
        ]);

        return $q;
    }

    public function views(): array
    {
        $status = fn ($row) => $row->status
            ? '<span class="badge">' . e($row->status) . '</span>'
            : '<span class="badge badge-success">On floor</span>';

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
                    $this->dateCol('Date', 'entrance_date'),
                    ['Status', 'status', $status],
                ],
                'searchable' => ['f.barcode', 'fd.factoryname', 'p.productname', 'g.groupname', 'sg.subgroupname', 'f.status'],
                'query' => fn () => $this->base()
                    ->select('f.id', 'f.barcode', 'fd.factoryname', 'g.groupname', 'sg.subgroupname',
                        'p.productname', DB::raw('ROUND(f.weight, 2) as weight'), 'f.entrance_date', 'f.status')
                    // id is chronological → recent-first via the PK (fast on any date range;
                    // ordering by the joined/date columns forces a filesort over the whole range).
                    ->orderByDesc('f.id'),
            ],
            'by_factory_subgroup' => [
                'label' => 'Summary (by factory, sub group)',
                'type' => 'summary',
                'columns' => [
                    ['Factory', 'factoryname'],
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['fd.factoryname', 'g.groupname', 'sg.subgroupname'],
                'query' => fn () => $this->base()
                    ->selectRaw('fd.factoryname, g.groupname, sg.subgroupname, COUNT(*) as quantity, ROUND(SUM(f.weight), 2) as weight')
                    ->groupBy('fd.factoryname', 'g.groupname', 'sg.subgroupname')
                    ->orderBy('fd.factoryname')->orderBy('sg.subgroupname'),
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
                    ->selectRaw('p.productname, COUNT(*) as quantity, ROUND(SUM(f.weight), 2) as weight')
                    ->groupBy('p.productname')->orderByDesc(DB::raw('SUM(f.weight)')),
            ],
        ];
    }

    public function editFields(): array
    {
        return ['weight' => ['label' => 'Weight (kg)']];
    }

    /** Only un-progressed entrances (status NULL) may be edited/deleted. */
    protected function progressedGuard($row): ?string
    {
        if ($row && $row->status !== null) {
            return 'Item has already been ' . $row->status . ' — cannot modify.';
        }

        return null;
    }

    public function editGuard($row): ?string
    {
        return $this->progressedGuard($row);
    }

    public function deleteGuard($row): ?string
    {
        return $this->progressedGuard($row);
    }

    protected function findRow(int $id)
    {
        return RawMaterialFactoryEntrance::query()->find($id);
    }

    protected function fillEdit(int $id): void
    {
        $this->edit = ['weight' => (float) (RawMaterialFactoryEntrance::whereKey($id)->value('weight') ?? 0)];
    }

    public function saveEdit(): void
    {
        $fe = RawMaterialFactoryEntrance::find($this->editingId);
        if (! $fe || $fe->status !== null) {
            return;
        }
        $fe->update(['weight' => (float) ($this->edit['weight'] ?? 0)]);
    }

    protected function performDelete(int $id): void
    {
        $fe = RawMaterialFactoryEntrance::find($id);
        if ($fe && $fe->status === null) {
            $fe->delete();
        }
    }
}
