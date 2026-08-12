<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Factory;
use Modules\Core\Models\FactoryExitLocation;

/**
 * Admin → Exit Locations. The factory-side twin of Warehouse Entrances: the
 * gates goods leave a factory through.
 *
 * Replaces the legacy `factoryexit_details` name pair. These resolved their
 * factory on import, and one extra was recovered — Bil-2's gate, dropped from
 * that table but still named by 16 pallets from April 2017. It is present but
 * inactive.
 */
#[Title('Exit Locations')]
class FactoryExitLocations extends DataGrid
{
    public ?int $factory_id = null;
    public string $name = '';
    public int $sort_order = 0;
    public bool $is_active = true;

    public function pageKey(): string { return 'admin.factory_exit_locations'; }
    public function pageLabel(): string { return 'Exit Locations'; }
    public function pageSubtitle(): string { return 'Gates goods leave a factory through. Each belongs to a factory, and users are granted gates individually.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.factory-exit-location'; }
    public function defaultSort(): array { return ['sort_order', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Exit Location', 'name'],
                    ['Factory', 'factory.name', fn ($r) => $r->factory
                        ? e($r->factory->name)
                        : '<span class="badge badge-danger">Unassigned</span>'],
                    ['Users', 'users_count'],
                    ['Order', 'sort_order'],
                    ['Status', 'is_active', fn ($r) => '<span class="badge ' . ($r->is_active ? 'badge-success' : 'badge-muted') . '">' . ($r->is_active ? 'active' : 'inactive') . '</span>'],
                ],
                'query' => fn () => FactoryExitLocation::query()->with('factory')->withCount('users'),
                'searchable' => ['name', 'legacy_name'],
                'sortable' => ['name', 'sort_order', 'is_active'],
            ],
            'by_factory' => [
                'label' => 'Summary (by factory)',
                'type' => 'summary',
                'columns' => [
                    ['Factory', 'factory_name'],
                    ['Exit Locations', 'total'],
                ],
                'query' => fn () => FactoryExitLocation::query()
                    ->leftJoin('factories as f', 'factory_exit_locations.factory_id', '=', 'f.id')
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

    protected function rules(): array
    {
        $ignore = $this->editingId ? ',' . $this->editingId : '';

        return [
            'factory_id' => ['nullable', 'exists:factories,id'],
            'name' => ['required', 'string', 'max:255', 'unique:factory_exit_locations,name' . $ignore],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    protected function resetForm(): void
    {
        $this->factory_id = null;
        $this->name = '';
        $this->sort_order = 0;
        $this->is_active = true;
    }

    protected function fillForm(int $id): void
    {
        $l = FactoryExitLocation::findOrFail($id);
        $this->factory_id = $l->factory_id;
        $this->name = $l->name;
        $this->sort_order = (int) $l->sort_order;
        $this->is_active = (bool) $l->is_active;
    }

    /** A gate historic exits point at stays — deactivate it instead. */
    public function deleteGuard($row): ?string
    {
        $exits = DB::connection('bil')->table('factory_exit')
            ->where('exit_location_id', $row->id)->limit(1)->count();

        return $exits > 0
            ? 'Historic exits reference this gate — deactivate it instead.'
            : null;
    }

    protected function findRow(int $id)
    {
        return FactoryExitLocation::find($id);
    }

    protected function performDelete(int $id): void
    {
        DB::connection('core')->table('factory_exit_location_user')->where('exit_location_id', $id)->delete();
        FactoryExitLocation::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();

        FactoryExitLocation::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Exit location updated.' : 'Exit location added.');
    }
}
