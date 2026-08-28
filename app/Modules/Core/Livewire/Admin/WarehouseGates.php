<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Warehouse;
use Modules\Core\Models\WarehouseGate;

/**
 * Admin → Warehouse Gates. Where goods enter and leave a warehouse.
 *
 * Direction lives on the gate rather than splitting entrances and exits into
 * two tables: a gate is a place, and `both` is a real case — one elevator or
 * roller door often serves either way.
 *
 * The three finished-goods gates were imported with no warehouse, because there
 * were none to put them in; until one is chosen they show as "Unassigned" and
 * cannot be used. Who may use a gate is granted per user, in the user editor.
 */
#[Title('Warehouse Gates')]
class WarehouseGates extends DataGrid
{
    public ?int $warehouse_id = null;
    public string $name = '';
    public string $direction = 'in';
    public ?string $legacy_name = null;
    public int $sort_order = 0;
    public bool $is_active = true;

    public function pageKey(): string { return 'admin.warehouse_gates'; }
    public function pageLabel(): string { return 'Warehouse Gates'; }
    public function pageSubtitle(): string { return 'Where goods enter and leave each warehouse. Users are granted gates individually.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.warehouse-gate'; }
    public function defaultSort(): array { return ['sort_order', 'asc']; }

    public function views(): array
    {
        $dirs = config('warehouses.directions');

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Gate', 'name'],
                    ['Warehouse', 'warehouse.name', fn ($r) => $r->warehouse
                        ? e($r->warehouse->name)
                        : '<span class="badge badge-danger">Unassigned</span>'],
                    ['Stores', 'module', fn ($r) => e($r->warehouse?->moduleLabel() ?? '—')],
                    ['Direction', 'direction', fn ($r) => '<span class="badge badge-muted">' . e($dirs[$r->direction] ?? $r->direction) . '</span>'],
                    ['Users', 'users_count'],
                    ['Status', 'is_active', fn ($r) => '<span class="badge ' . ($r->is_active ? 'badge-success' : 'badge-muted') . '">' . ($r->is_active ? 'active' : 'inactive') . '</span>'],
                ],
                'query' => fn () => WarehouseGate::query()->with('warehouse')->withCount('users'),
                'searchable' => ['name', 'legacy_name'],
                'sortable' => ['name', 'direction', 'sort_order', 'is_active'],
            ],
            'by_warehouse' => [
                'label' => 'Summary (by warehouse)',
                'type' => 'summary',
                'columns' => [
                    ['Warehouse', 'warehouse_name'],
                    ['Gates', 'total'],
                ],
                'query' => fn () => WarehouseGate::query()
                    ->leftJoin('warehouses as w', 'warehouse_gates.warehouse_id', '=', 'w.id')
                    ->selectRaw("COALESCE(w.name, 'Unassigned') as warehouse_name, COUNT(*) as total")
                    ->groupBy('warehouse_name'),
            ],
        ];
    }

    #[Computed]
    public function warehouses()
    {
        return Warehouse::ordered()->get();
    }

    #[Computed]
    public function directions()
    {
        return config('warehouses.directions');
    }

    protected function rules(): array
    {
        $ignore = $this->editingId ? ',' . $this->editingId : '';

        return [
            // Nullable so an imported gate can be parked until its warehouse
            // exists — `usable()` excludes it, so it cannot be used meanwhile.
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'name' => ['required', 'string', 'max:255', 'unique:warehouse_gates,name' . $ignore],
            'direction' => ['required', 'in:' . implode(',', array_keys(config('warehouses.directions')))],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    protected function resetForm(): void
    {
        $this->warehouse_id = null;
        $this->name = '';
        $this->direction = 'in';
        $this->legacy_name = null;
        $this->sort_order = 0;
        $this->is_active = true;
    }

    protected function fillForm(int $id): void
    {
        $g = WarehouseGate::findOrFail($id);
        $this->warehouse_id = $g->warehouse_id;
        $this->name = $g->name;
        $this->direction = $g->direction;
        $this->legacy_name = $g->legacy_name;
        $this->sort_order = (int) $g->sort_order;
        $this->is_active = (bool) $g->is_active;
    }

    /**
     * A gate goods have moved through stays: deleting it would orphan those
     * movements, and receipts are what stock derives from.
     */
    public function deleteGuard($row): ?string
    {
        $used = DB::connection('bil')->table('finished_goods_warehouse_receipts')
                ->where('entrance_id', $row->id)->limit(1)->count()
            + DB::connection('bil')->table('rawmaterials_warehouse_entry')
                ->where('gate_id', $row->id)->limit(1)->count()
            + DB::connection('bil')->table('rawmaterials_warehouse_exit')
                ->where('gate_id', $row->id)->limit(1)->count();

        return $used > 0 ? 'Goods have moved through this gate — deactivate it instead.' : null;
    }

    protected function findRow(int $id)
    {
        return WarehouseGate::find($id);
    }

    protected function performDelete(int $id): void
    {
        DB::connection('core')->table('warehouse_gate_user')->where('gate_id', $id)->delete();
        WarehouseGate::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();
        // Set once on import and never edited — the link back to the legacy
        // location string on the historic movements.
        unset($data['legacy_name']);

        WarehouseGate::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Gate updated.' : 'Gate added.');
    }
}
