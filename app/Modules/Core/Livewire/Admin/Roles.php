<?php

namespace Modules\Core\Livewire\Admin;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;

#[Title('Roles')]
class Roles extends DataGrid
{
    public string $name = '';
    public ?string $description = null;
    public array $selectedPermissions = [];

    public function pageKey(): string { return 'admin.roles'; }
    public function pageLabel(): string { return 'Roles'; }
    public function pageSubtitle(): string { return 'Manage user roles and the permissions assigned to each.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.role'; }
    public function defaultSort(): array { return ['name', 'asc']; }
    public function modalSize(): string { return '660px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Role Name', 'name'],
                    ['Description', 'description', fn ($r) => e($r->description ?? '—')],
                    ['Permissions', 'permissions_count', fn ($r) => '<span class="badge badge-muted">' . $r->permissions_count . '</span>'],
                ],
                'query' => fn () => Role::query()->withCount('permissions'),
                'searchable' => ['name', 'description'],
                'sortable' => ['name'],
            ],
        ];
    }

    /** Permissions grouped by module name for the assignment checklist. */
    #[Computed]
    public function groupedPermissions()
    {
        return Permission::with('module')->orderBy('name')->get()
            ->groupBy(fn ($p) => $p->module?->name ?? 'Unassigned');
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name' . ($this->editingId ? ',' . $this->editingId : '')],
            'description' => ['nullable', 'string', 'max:255'],
            'selectedPermissions' => ['array'],
        ];
    }

    protected function resetForm(): void
    {
        $this->name = '';
        $this->description = null;
        $this->selectedPermissions = [];
    }

    protected function fillForm(int $id): void
    {
        $role = Role::with('permissions')->findOrFail($id);
        $this->name = $role->name;
        $this->description = $role->description;
        $this->selectedPermissions = $role->permissions->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    protected function performDelete(int $id): void
    {
        Role::whereKey($id)->delete();
    }

    public function save(): void
    {
        $this->validate();

        $role = Role::updateOrCreate(
            ['id' => $this->editingId],
            ['name' => $this->name, 'guard_name' => 'web', 'description' => $this->description]
        );

        $perms = Permission::whereIn('id', $this->selectedPermissions)->get();
        $role->syncPermissions($perms);

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Role updated.' : 'Role added.');
    }
}
