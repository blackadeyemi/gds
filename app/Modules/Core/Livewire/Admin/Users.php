<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

#[Title('User Management')]
class Users extends DataGrid
{
    public string $username = '';
    public ?string $fullname = null;
    public ?string $email = null;
    public ?int $role_id = null;
    public ?string $password = null;

    public function pageKey(): string { return 'admin.users'; }
    public function pageLabel(): string { return 'User Management'; }
    public function pageSubtitle(): string { return 'Manage users, assign roles, and control access.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.user'; }
    public function defaultSort(): array { return ['username', 'asc']; }
    public function modalSize(): string { return '520px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Username', 'username'],
                    ['Full Name', 'fullname', fn ($r) => e($r->fullname ?? '—')],
                    ['Email', 'email', fn ($r) => e($r->email ?? '—')],
                    ['Role', 'role', fn ($r) => e($r->roles->first()?->name ?? '—')],
                ],
                'query' => fn () => User::query()->with('roles'),
                'searchable' => ['username', 'fullname', 'email'],
                'sortable' => ['username', 'fullname', 'email'],
            ],
            'by_role' => [
                'label' => 'Summary (by role)',
                'type' => 'summary',
                'columns' => [
                    ['Role', 'role_name'],
                    ['Users', 'total'],
                ],
                'query' => fn () => User::query()
                    ->leftJoin('model_has_roles', function ($j) {
                        $j->on('user.userid', '=', 'model_has_roles.model_id')
                          ->where('model_has_roles.model_type', '=', User::class);
                    })
                    ->leftJoin('roles', 'model_has_roles.role_id', '=', 'roles.id')
                    ->selectRaw("COALESCE(roles.name, 'No role') as role_name, COUNT(*) as total")
                    ->groupBy('role_name'),
            ],
        ];
    }

    #[Computed]
    public function roles()
    {
        return Role::orderBy('name')->get();
    }

    protected function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255', 'unique:user,username,' . ($this->editingId ?? 'NULL') . ',userid'],
            'fullname' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:50'],
            'role_id' => ['required', 'exists:roles,id'],
            'password' => [$this->editingId ? 'nullable' : 'required', 'nullable', 'string', 'min:4'],
        ];
    }

    protected function resetForm(): void
    {
        $this->username = '';
        $this->fullname = null;
        $this->email = null;
        $this->role_id = null;
        $this->password = null;
    }

    protected function fillForm(int $id): void
    {
        $u = User::with('roles')->findOrFail($id);
        $this->username = $u->username;
        $this->fullname = $u->fullname;
        $this->email = $u->email;
        $this->role_id = optional(Role::where('name', $u->roles->first()?->name)->first())->id;
        $this->password = null;
    }

    protected function performDelete(int $id): void
    {
        User::whereKey($id)->delete();
    }

    public function save(): void
    {
        $this->validate();

        $role = Role::find($this->role_id);
        $u = $this->editingId ? User::findOrFail($this->editingId) : new User();
        $u->username = $this->username;
        $u->fullname = $this->fullname;
        $u->email = $this->email;
        if ($this->password) {
            $u->password = Hash::make($this->password);
        }
        // Keep the legacy NOT NULL columns valid: userlevel mirrors the role's
        // legacy level; new rows get a default landing page.
        $u->userlevel = $role?->legacy_level ?? 1;
        if (! $this->editingId) {
            $u->redirection_id = 1;
        }
        $u->save();

        $u->syncRoles($role ? [$role] : []);

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'User updated.' : 'User added.');
    }
}
