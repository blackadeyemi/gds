<?php

namespace Modules\Bil\Livewire\RawMaterials\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Models\RawMaterialFactoryEntrance;
use Modules\Bil\Models\RawMaterialFactoryUsage;

/**
 * Reports → Consumption. Raw material consumed on the factory floor, over
 * `factory_usage_rawmaterials` (only is_deleted = 0). Rebuilt from the legacy
 * usage report (Report\Rawmaterials\Usage / Bil\Factory\Rawmaterials\Usage).
 *
 * The raw-material product/group/sub-group are resolved by joining the item's
 * barcode back to warehouse-entry (barcodes repeat, so the join picks the
 * earliest matching row to avoid double-counting).
 *
 * Delete soft-deletes the usage record (is_deleted = 1) and reverts the
 * factory-entrance row to on-floor (status → NULL) — the gds "un-consume".
 * There is NO stock change (gds moves stock once, at Warehouse Exit — the
 * legacy added it back here). Edit corrects the recorded weight. Both are
 * DISABLED once the item has a return in progress (return_approval pending/
 * approved, or the usage row already flipped to 'return').
 */
#[Title('Consumption Report')]
class Consumption extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Consumption Report';
    }

    public function printKey(): string
    {
        return 'consumption';
    }

    public function subtitle(): string
    {
        return 'Raw material consumed on the factory floor, per line and shift.';
    }

    protected function options(): array
    {
        return $this->optCache ??= [
            'factories' => DB::connection('bil')->table('factory_details')
                ->distinct()->orderBy('location')->pluck('location', 'location')->all(),
            'machines' => DB::connection('bil')->table('factory_details')
                ->whereNotNull('sublinename')->distinct()->orderBy('sublinename')
                ->pluck('sublinename', 'sublinename')->all(),
            'products' => DB::connection('bil')->table('rawmaterials_products')
                ->orderBy('productname')->pluck('productname', 'id')->all(),
            'subgroups' => DB::connection('bil')->table('rawmaterials_subgroups')
                ->orderBy('subgroupname')->pluck('subgroupname', 'id')->all(),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'factory' => ['label' => 'Factory', 'options' => $o['factories']],
            'machine' => ['label' => 'Machine', 'options' => $o['machines']],
            'shift' => ['label' => 'Shift', 'options' => ['Day' => 'Day', 'Night' => 'Night']],
            'subgroup' => ['label' => 'Sub Group', 'options' => $o['subgroups']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
        ];
    }

    protected function base()
    {
        $q = DB::connection('bil')->table('factory_usage_rawmaterials as f')
            // Resolve the raw material via the barcode. The barcode columns have
            // different charsets (usage=utf8mb3, warehouse-entry=latin1), so
            // CONVERT the driving value to latin1 or the index can't be used
            // (→ full scan). No dedup subquery — it was ~100x slower on wide
            // ranges and duplicate barcodes are negligible.
            ->leftJoin('rawmaterials_warehouse_entry as r', function ($j) {
                $j->whereRaw('r.barcode = CONVERT(f.barcode USING latin1)');
            })
            ->leftJoin('rawmaterials_products as p', 'r.productid', '=', 'p.id')
            ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
            ->leftJoin('rawmaterials_subgroups as sg', 'p.subgroupid', '=', 'sg.id')
            ->where('f.is_deleted', 0);

        $this->applyDate($q, 'f.dateofuse', slash: true);
        $this->applyFilters($q, [
            'factory' => 'f.location',
            'machine' => 'f.linename',
            'shift' => 'f.shift',
            'subgroup' => 'p.subgroupid',
            'product' => 'r.productid',
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
                    ['Factory', 'location'],
                    ['Machine', 'linename'],
                    ['Product On Line', 'project'],
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Raw Material', 'productname'],
                    ['Shift', 'shift'],
                    ['Weight (kg)', 'weight'],
                    ['Date', 'dateofuse'],
                ],
                'searchable' => ['f.barcode', 'f.location', 'f.linename', 'f.project', 'p.productname', 'g.groupname', 'sg.subgroupname', 'f.shift'],
                'query' => fn () => $this->base()
                    ->select('f.id', 'f.barcode', 'f.location', 'f.linename', 'f.project', 'f.shift', 'f.status',
                        DB::raw('ROUND(f.weight, 2) as weight'), 'f.dateofuse',
                        'g.groupname', 'sg.subgroupname', 'p.productname')
                    // id is chronological → recent-first via the PK (fast on any date range).
                    ->orderByDesc('f.id'),
            ],
            'by_machine' => [
                'label' => 'Summary (by machine)',
                'type' => 'summary',
                'columns' => [
                    ['Factory', 'location'],
                    ['Machine', 'linename'],
                    ['Product On Line', 'project'],
                    ['Raw Material', 'productname'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['f.location', 'f.linename', 'f.project', 'p.productname'],
                'query' => fn () => $this->base()
                    ->selectRaw('f.location, f.linename, f.project, p.productname, COUNT(*) as quantity, ROUND(SUM(f.weight), 2) as weight')
                    ->groupBy('f.location', 'f.linename', 'f.project', 'p.productname')
                    ->orderBy('f.location')->orderBy('f.linename'),
            ],
            'by_product' => [
                'label' => 'Summary (by raw material)',
                'type' => 'summary',
                'columns' => [
                    ['Raw Material', 'productname'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['p.productname'],
                'query' => fn () => $this->base()
                    ->selectRaw('p.productname, COUNT(*) as quantity, ROUND(SUM(f.weight), 2) as weight')
                    ->groupBy('p.productname')->orderByDesc(DB::raw('SUM(f.weight)')),
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
                    ->selectRaw('g.groupname, sg.subgroupname, COUNT(*) as quantity, ROUND(SUM(f.weight), 2) as weight')
                    ->groupBy('g.groupname', 'sg.subgroupname')
                    ->orderBy('g.groupname')->orderBy('sg.subgroupname'),
            ],
        ];
    }

    public function editFields(): array
    {
        return ['weight' => ['label' => 'Weight (kg)']];
    }

    /**
     * Barcodes with a return in progress, loaded once per render (return_approval
     * has no barcode index, so a per-row lookup during render was ~300ms × every
     * row ≈ 10s). Kept as a set for O(1) membership.
     */
    protected ?array $pendingReturnSet = null;

    protected function hasPendingReturn(string $barcode): bool
    {
        $this->pendingReturnSet ??= array_flip(
            DB::connection('bil')->table('return_approval')
                ->whereIn('status', ['pending', 'approved'])->pluck('barcode')->all()
        );

        return isset($this->pendingReturnSet[$barcode]);
    }

    /** Block edit/delete once the item has a return in progress. */
    protected function returnGuard($row): ?string
    {
        if (! $row) {
            return 'Row not found.';
        }
        if ($row->status === 'return') {
            return 'Item has been returned — cannot modify.';
        }

        return $this->hasPendingReturn((string) $row->barcode)
            ? 'Item has a return in progress — cannot modify.'
            : null;
    }

    public function editGuard($row): ?string
    {
        return $this->returnGuard($row);
    }

    public function deleteGuard($row): ?string
    {
        return $this->returnGuard($row);
    }

    protected function findRow(int $id)
    {
        return RawMaterialFactoryUsage::query()->where('is_deleted', 0)->find($id);
    }

    protected function fillEdit(int $id): void
    {
        $this->edit = ['weight' => (float) (RawMaterialFactoryUsage::whereKey($id)->value('weight') ?? 0)];
    }

    public function saveEdit(): void
    {
        RawMaterialFactoryUsage::whereKey($this->editingId)->update([
            'weight' => (float) ($this->edit['weight'] ?? 0),
        ]);
    }

    /** Soft-delete the usage record and put the item back on the floor. */
    protected function performDelete(int $id): void
    {
        $usage = RawMaterialFactoryUsage::find($id);
        if (! $usage) {
            return;
        }

        $usage->update(['is_deleted' => 1]);
        RawMaterialFactoryEntrance::where('barcode', $usage->barcode)->update(['status' => null]);
    }
}
