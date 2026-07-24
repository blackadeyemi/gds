<?php

namespace Modules\Bil\Livewire\RawMaterials\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * Reports → Damaged Goods. Raw material reported damaged/written off, over
 * `damagedgoods_rawmaterial`. Rebuilt from the legacy damaged-goods report. The
 * row carries product_id + weight directly. Read-only — the pending → approved
 * / rejected lifecycle is managed on the Damaged Goods operation page.
 */
#[Title('Damaged Goods Report')]
class DamagedGoods extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Damaged Goods Report';
    }

    public function printKey(): string
    {
        return 'damaged-goods';
    }

    public function subtitle(): string
    {
        return 'Raw material reported damaged or written off.';
    }

    public function readOnly(): bool
    {
        return true;
    }

    protected function options(): array
    {
        return $this->optCache ??= [
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
            'group' => ['label' => 'Group', 'options' => $o['groups']],
            'subgroup' => ['label' => 'Sub Group', 'options' => $o['subgroups']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
            'status' => ['label' => 'Status', 'options' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']],
        ];
    }

    protected function base()
    {
        $q = DB::connection('bil')->table('damagedgoods_rawmaterial as d')
            ->leftJoin('rawmaterials_products as p', 'd.product_id', '=', 'p.id')
            ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
            ->leftJoin('rawmaterials_subgroups as sg', 'p.subgroupid', '=', 'sg.id');

        $this->applyDate($q, 'd.entrance_date');
        $this->applyFilters($q, [
            'product' => 'd.product_id',
            'group' => 'p.groupid',
            'subgroup' => 'p.subgroupid',
            'status' => 'd.status',
        ]);

        return $q;
    }

    public function views(): array
    {
        $status = fn ($row) => $row->status
            ? '<span class="badge">' . e($row->status) . '</span>'
            : '<span class="badge">—</span>';

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Product', 'productname'],
                    ['Weight (kg)', 'weight'],
                    ['Date', 'entrance_date'],
                    ['By', 'user_name'],
                    ['Status', 'status', $status],
                ],
                'searchable' => ['d.barcode', 'p.productname', 'g.groupname', 'sg.subgroupname', 'd.user_name', 'd.status'],
                'query' => fn () => $this->base()
                    ->select('d.id', 'd.barcode', 'g.groupname', 'sg.subgroupname', 'p.productname',
                        DB::raw('ROUND(d.weight, 2) as weight'), 'd.entrance_date', 'd.user_name', 'd.status')
                    ->orderByDesc('d.id'),
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
                    ->selectRaw('p.productname, COUNT(*) as quantity, ROUND(SUM(d.weight), 2) as weight')
                    ->groupBy('p.productname')->orderByDesc(DB::raw('SUM(d.weight)')),
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
                    ->selectRaw('g.groupname, sg.subgroupname, COUNT(*) as quantity, ROUND(SUM(d.weight), 2) as weight')
                    ->groupBy('g.groupname', 'sg.subgroupname')
                    ->orderBy('g.groupname')->orderBy('sg.subgroupname'),
            ],
        ];
    }
}
