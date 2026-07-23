<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\ApplicationModule;
use Modules\Core\Models\Permission;

#[Title('Permissions')]
class Permissions extends DataGrid
{
    public string $name = '';
    public ?string $description = null;
    public ?int $module_id = null;

    public function pageKey(): string { return 'admin.permissions'; }
    public function pageLabel(): string { return 'Permissions'; }
    public function pageSubtitle(): string { return 'Manage access permissions for the application.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.permission'; }
    public function defaultSort(): array { return ['name', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Permission Name', 'name'],
                    ['Description', 'description', fn ($r) => e($r->description ?? '—')],
                    ['Module', 'module.name', fn ($r) => e($r->module?->name ?? '—')],
                    ['Roles', 'roles_count', fn ($r) => '<span class="badge badge-muted">' . ($r->roles_count ?? 0) . '</span>'],
                ],
                'query' => fn () => Permission::query()->with('module')->withCount('roles'),
                'searchable' => ['name', 'description'],
                'sortable' => ['name'],
            ],
            'by_module' => [
                'label' => 'Summary (by module)',
                'type' => 'summary',
                'columns' => [
                    ['Module', 'module_name'],
                    ['Permissions', 'total'],
                ],
                'query' => fn () => Permission::query()
                    ->leftJoin('application_modules', 'permissions.module_id', '=', 'application_modules.id')
                    ->selectRaw("COALESCE(application_modules.name, 'Unassigned') as module_name, COUNT(*) as total")
                    ->groupBy('module_name'),
            ],
        ];
    }

    #[Computed]
    public function modules()
    {
        return ApplicationModule::orderBy('name')->get();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:permissions,name' . ($this->editingId ? ',' . $this->editingId : '')],
            'description' => ['nullable', 'string', 'max:255'],
            'module_id' => ['nullable', 'exists:application_modules,id'],
        ];
    }

    protected function resetForm(): void
    {
        $this->name = '';
        $this->description = null;
        $this->module_id = null;
    }

    protected function fillForm(int $id): void
    {
        $p = Permission::findOrFail($id);
        $this->name = $p->name;
        $this->description = $p->description;
        $this->module_id = $p->module_id;
    }

    /** A permission can't be removed while any role still holds it. */
    public function deleteGuard($row): ?string
    {
        $c = $row->roles_count ?? 0;

        return $c > 0
            ? 'Assigned to ' . $c . ' ' . Str::plural('role', $c) . ' — cannot delete.'
            : null;
    }

    protected function findRow(int $id)
    {
        return Permission::withCount('roles')->find($id);
    }

    protected function performDelete(int $id): void
    {
        Permission::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();
        $data['guard_name'] = 'web';
        Permission::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Permission updated.' : 'Permission added.');
    }
}
