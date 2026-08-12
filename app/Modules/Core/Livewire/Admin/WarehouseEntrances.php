<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Warehouse;
use Modules\Core\Models\WarehouseEntrance;

/**
 * Admin → Warehouse Entrances. The gates goods are received through, each
 * belonging to one warehouse — that link is what tells a receipt whose stock to
 * move.
 *
 * The three legacy gates were imported with no warehouse, because there were
 * none to put them in. Until one is chosen the gate shows as "Unassigned" and
 * cannot be used to receive.
 *
 * Who may use a gate is granted per user, in the user editor.
 */
#[Title('Warehouse Entrances')]
class WarehouseEntrances extends DataGrid
{
    public ?int $warehouse_id = null;
    public string $name = '';
    public ?string $legacy_name = null;
    public int $sort_order = 0;
    public bool $is_active = true;

    public function pageKey(): string { return 'admin.warehouse_entrances'; }
    public function pageLabel(): string { return 'Warehouse Entrances'; }
    public function pageSubtitle(): string { return 'Gates goods are received through. Each belongs to a warehouse, and users are granted gates individually.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.warehouse-entrance'; }
    public function defaultSort(): array { return ['sort_order', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Entrance', 'name'],
                    ['Warehouse', 'warehouse.name', fn ($r) => $r->warehouse
                        ? e($r->warehouse->name)
                        : '<span class="badge badge-danger">Unassigned</span>'],
                    ['Users', 'users_count'],
                    ['Order', 'sort_order'],
                    ['Status', 'is_active', fn ($r) => '<span class="badge ' . ($r->is_active ? 'badge-success' : 'badge-muted') . '">' . ($r->is_active ? 'active' : 'inactive') . '</span>'],
                ],
                'query' => fn () => WarehouseEntrance::query()->with('warehouse')->withCount('users'),
                'searchable' => ['name', 'legacy_name'],
                'sortable' => ['name', 'sort_order', 'is_active'],
            ],
            'by_warehouse' => [
                'label' => 'Summary (by warehouse)',
                'type' => 'summary',
                'columns' => [
                    ['Warehouse', 'warehouse_name'],
                    ['Entrances', 'total'],
                ],
                'query' => fn () => WarehouseEntrance::query()
                    ->leftJoin('warehouses as w', 'warehouse_entrances.warehouse_id', '=', 'w.id')
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

    protected function rules(): array
    {
        $ignore = $this->editingId ? ',' . $this->editingId : '';

        return [
            // Nullable so an imported gate can be parked until its warehouse
            // exists — `usable()` excludes it, so it cannot receive meanwhile.
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'name' => ['required', 'string', 'max:255', 'unique:warehouse_entrances,name' . $ignore],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    protected function resetForm(): void
    {
        $this->warehouse_id = null;
        $this->name = '';
        $this->legacy_name = null;
        $this->sort_order = 0;
        $this->is_active = true;
    }

    protected function fillForm(int $id): void
    {
        $e = WarehouseEntrance::findOrFail($id);
        $this->warehouse_id = $e->warehouse_id;
        $this->name = $e->name;
        $this->legacy_name = $e->legacy_name;
        $this->sort_order = (int) $e->sort_order;
        $this->is_active = (bool) $e->is_active;
    }

    /**
     * A gate that has taken receipts stays: deleting it would orphan them, and
     * the receipts are what the stock totals derive from.
     */
    public function deleteGuard($row): ?string
    {
        $receipts = DB::connection('core')->table('finished_goods_warehouse_receipts')
            ->where('entrance_id', $row->id)->limit(1)->count();

        return $receipts > 0
            ? 'Has receipts against it — deactivate it instead.'
            : null;
    }

    protected function findRow(int $id)
    {
        return WarehouseEntrance::find($id);
    }

    protected function performDelete(int $id): void
    {
        DB::connection('core')->table('warehouse_entrance_user')->where('entrance_id', $id)->delete();
        WarehouseEntrance::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();
        // Set once on import and never edited — it is the link back to the
        // legacy `entrancelocation` string on the historic receipts.
        unset($data['legacy_name']);

        WarehouseEntrance::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Entrance updated.' : 'Entrance added.');
    }
}
