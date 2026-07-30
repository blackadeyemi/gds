<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Str;
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

    public ?int $viewingRoleId = null;

    public function pageKey(): string { return 'admin.roles'; }
    public function pageLabel(): string { return 'Roles'; }
    public function pageSubtitle(): string { return 'Manage user roles and the permissions assigned to each.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.role'; }
    public function extraView(): ?string { return 'core::livewire.role-permissions-modal'; }
    public function defaultSort(): array { return ['name', 'asc']; }
    public function modalSize(): string { return '660px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Role Name', 'name', fn ($r) => '<a href="#" wire:click.prevent="showPermissions(' . $r->id . ')" style="color:var(--brand);font-weight:600;text-decoration:none;cursor:pointer;">' . e($r->name) . '</a>'],
                    ['Description', 'description', fn ($r) => e($r->description ?? '—')],
                    ['Permissions', 'permissions_count', fn ($r) => '<span class="badge badge-muted">' . $r->permissions_count . '</span>'],
                    ['Users', 'users_count', fn ($r) => '<span class="badge badge-muted">' . ($r->users_count ?? 0) . '</span>'],
                ],
                'query' => fn () => Role::query()->withCount('permissions')->withCount('users'),
                'searchable' => ['name', 'description'],
                'sortable' => ['name'],
            ],
        ];
    }

    /** Open the read-only permissions modal for a role. */
    public function showPermissions(int $id): void
    {
        $this->viewingRoleId = $id;
    }

    /** Editing from the view modal should replace it with the editor. */
    public function edit(int $id): void
    {
        $this->viewingRoleId = null;
        parent::edit($id);
    }

    /** The role being viewed, with its permissions grouped by module. */
    #[Computed]
    public function viewingRole()
    {
        if (! $this->viewingRoleId) {
            return null;
        }

        $role = Role::with('permissions.module')->find($this->viewingRoleId);
        if (! $role) {
            return null;
        }

        return [
            'name' => $role->name,
            'description' => $role->description,
            'count' => $role->permissions->count(),
            'grouped' => $role->permissions
                ->sortBy('name')
                ->groupBy(fn ($p) => $p->module?->label() ?? 'Unassigned'),
        ];
    }

    /** Permissions grouped by module name for the assignment checklist. */
    #[Computed]
    public function groupedPermissions()
    {
        return Permission::with('module')->orderBy('name')->get()
            ->groupBy(fn ($p) => $p->module?->label() ?? 'Unassigned');
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

    /** A role can't be removed while any user still holds it. */
    public function deleteGuard($row): ?string
    {
        $c = $row->users_count ?? 0;

        return $c > 0
            ? 'In use by ' . $c . ' ' . Str::plural('user', $c) . ' — cannot delete.'
            : null;
    }

    protected function findRow(int $id)
    {
        return Role::withCount('users')->find($id);
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
