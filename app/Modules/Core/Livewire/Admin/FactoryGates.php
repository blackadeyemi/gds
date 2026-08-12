<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Factory;
use Modules\Core\Models\FactoryGate;

/**
 * Admin → Factory Gates. Where goods enter and leave a factory — the
 * factory-side twin of Warehouse Gates.
 *
 * Replaces two legacy name-pair tables: `factoryexit_details` (finished goods
 * leaving) and `factoryentrance_details` (raw material arriving).
 *
 * Two imported gates are INACTIVE so that history resolves without offering
 * them: Bil-2's exit, dropped from the legacy table but named by 16 pallets
 * from April 2017, and "Oregun Store", which the legacy entrance table listed
 * but which is a store rather than a factory.
 */
#[Title('Factory Gates')]
class FactoryGates extends DataGrid
{
    public ?int $factory_id = null;
    public string $name = '';
    public string $direction = 'out';
    public ?string $legacy_name = null;
    public int $sort_order = 0;
    public bool $is_active = true;

    public function pageKey(): string { return 'admin.factory_gates'; }
    public function pageLabel(): string { return 'Factory Gates'; }
    public function pageSubtitle(): string { return 'Where goods enter and leave each factory. Users are granted gates individually.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.factory-gate'; }
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
                    ['Factory', 'factory.name', fn ($r) => $r->factory
                        ? e($r->factory->name)
                        : '<span class="badge badge-danger">Unassigned</span>'],
                    ['Direction', 'direction', fn ($r) => '<span class="badge badge-muted">' . e($dirs[$r->direction] ?? $r->direction) . '</span>'],
                    ['Users', 'users_count'],
                    ['Status', 'is_active', fn ($r) => '<span class="badge ' . ($r->is_active ? 'badge-success' : 'badge-muted') . '">' . ($r->is_active ? 'active' : 'inactive') . '</span>'],
                ],
                'query' => fn () => FactoryGate::query()->with('factory')->withCount('users'),
                'searchable' => ['name', 'legacy_name'],
                'sortable' => ['name', 'direction', 'sort_order', 'is_active'],
            ],
            'by_factory' => [
                'label' => 'Summary (by factory)',
                'type' => 'summary',
                'columns' => [
                    ['Factory', 'factory_name'],
                    ['Gates', 'total'],
                ],
                'query' => fn () => FactoryGate::query()
                    ->leftJoin('factories as f', 'factory_gates.factory_id', '=', 'f.id')
                    ->selectRaw("COALESCE(f.name, 'Unassigned') as factory_name, COUNT(*) as total")
                    ->groupBy('factory_name'),
            ],
        ];
    }

    #[Computed]
    public function factories()
    {
        return Factory::orderBy('name')->get();
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
            'factory_id' => ['nullable', 'exists:factories,id'],
            'name' => ['required', 'string', 'max:255', 'unique:factory_gates,name' . $ignore],
            'direction' => ['required', 'in:' . implode(',', array_keys(config('warehouses.directions')))],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    protected function resetForm(): void
    {
        $this->factory_id = null;
        $this->name = '';
        $this->direction = 'out';
        $this->legacy_name = null;
        $this->sort_order = 0;
        $this->is_active = true;
    }

    protected function fillForm(int $id): void
    {
        $g = FactoryGate::findOrFail($id);
        $this->factory_id = $g->factory_id;
        $this->name = $g->name;
        $this->direction = $g->direction;
        $this->legacy_name = $g->legacy_name;
        $this->sort_order = (int) $g->sort_order;
        $this->is_active = (bool) $g->is_active;
    }

    /** A gate historic movements point at stays — deactivate it instead. */
    public function deleteGuard($row): ?string
    {
        $used = DB::connection('bil')->table('factory_exit')
                ->where('exit_location_id', $row->id)->limit(1)->count()
            + DB::connection('bil')->table('factory_entrance_rawmaterials')
                ->where('gate_id', $row->id)->limit(1)->count();

        return $used > 0 ? 'Goods have moved through this gate — deactivate it instead.' : null;
    }

    protected function findRow(int $id)
    {
        return FactoryGate::find($id);
    }

    protected function performDelete(int $id): void
    {
        DB::connection('core')->table('factory_gate_user')->where('gate_id', $id)->delete();
        FactoryGate::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();
        unset($data['legacy_name']);

        FactoryGate::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Gate updated.' : 'Gate added.');
    }
}
