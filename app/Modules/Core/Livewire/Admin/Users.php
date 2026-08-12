<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Company;
use Modules\Core\Models\Department;
use Modules\Core\Models\Division;
use Modules\Core\Models\Role;
use Modules\Core\Models\FactoryGate;
use Modules\Core\Models\User;
use Modules\Core\Models\WarehouseGate;

#[Title('User Management')]
class Users extends DataGrid
{
    public string $username = '';
    public ?string $fullname = null;
    public ?string $email = null;
    public ?int $role_id = null;
    public ?int $company_id = null;
    public ?int $department_id = null;
    public ?int $division_id = null;
    public ?string $password = null;

    /**
     * Which finished-goods gates this user may pick from, as arrays of ids.
     *
     * This is NOT access control — `page:` middleware already decides who may
     * open the receiving and exit screens. It decides which gates appear in the
     * dropdown once they are there, replacing the legacy `switch` on user level
     * that hard-coded "level 16 sees Store FB" and friends.
     */
    public array $entrance_ids = [];
    public array $exit_location_ids = [];

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
                    ['Company', 'company.name', fn ($r) => e($r->company?->name ?? '—')],
                    ['Department', 'department.name', fn ($r) => e($r->department?->name ?? '—')],
                    ['Division', 'division.name', fn ($r) => e($r->division?->name ?? '—')],
                ],
                'query' => fn () => User::query()->with('roles', 'company', 'department', 'division'),
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

    #[Computed]
    public function companies()
    {
        return Company::orderBy('name')->get();
    }

    /** Departments within the chosen company — drives the dependent select. */
    #[Computed]
    public function departmentsForCompany()
    {
        return $this->company_id
            ? Department::where('company_id', $this->company_id)->orderBy('name')->get()
            : collect();
    }

    /** Divisions within the chosen department — the third cascade step. */
    #[Computed]
    public function divisionsForDepartment()
    {
        return $this->department_id
            ? Division::where('department_id', $this->department_id)->orderBy('name')->get()
            : collect();
    }

    /** Changing company invalidates the department, and with it the division. */
    public function updatedCompanyId(): void
    {
        $this->department_id = null;
        $this->division_id = null;
    }

    /** Changing department invalidates the previously-picked division. */
    public function updatedDepartmentId(): void
    {
        $this->division_id = null;
    }

    /** Admin users span all companies, so company/department don't apply. */
    #[Computed]
    public function isAdminRole(): bool
    {
        return (int) optional(Role::find($this->role_id))->legacy_level === 1;
    }

    /**
     * Warehouse gates offered in the editor, grouped by warehouse.
     *
     * Unassigned gates are shown but flagged: they can be ticked, they simply
     * cannot be used to receive until they belong to a warehouse.
     */
    #[Computed]
    public function entranceOptions()
    {
        return WarehouseGate::with('warehouse')->ordered()->get()
            ->groupBy(fn ($e) => ($e->warehouse?->name ?? 'Unassigned') . ' — ' . $e->directionLabel());
    }

    /** Factory exit gates offered in the editor, grouped by factory. */
    #[Computed]
    public function exitLocationOptions()
    {
        return FactoryGate::with('factory')->ordered()->get()
            ->groupBy(fn ($l) => ($l->factory?->name ?? 'Unassigned') . ' — ' . $l->directionLabel());
    }

    protected function rules(): array
    {
        $scoped = ! $this->isAdminRole();

        return [
            'username' => ['required', 'string', 'max:255', 'unique:user,username,' . ($this->editingId ?? 'NULL') . ',userid'],
            'fullname' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:50'],
            'role_id' => ['required', 'exists:roles,id'],
            'company_id' => [$scoped ? 'required' : 'nullable', 'nullable', 'exists:companies,id'],
            // Department must belong to the chosen company (keeps the hierarchy intact).
            'department_id' => [
                $scoped ? 'required' : 'nullable',
                'nullable',
                Rule::exists('departments', 'id')->where('company_id', $this->company_id),
            ],
            // Optional third level; must sit under the chosen department.
            'division_id' => [
                'nullable',
                Rule::exists('divisions', 'id')->where('department_id', $this->department_id),
            ],
            'password' => [$this->editingId ? 'nullable' : 'required', 'nullable', 'string', 'min:4'],
            'entrance_ids' => ['array'],
            'entrance_ids.*' => ['integer', 'exists:warehouse_gates,id'],
            'exit_location_ids' => ['array'],
            'exit_location_ids.*' => ['integer', 'exists:factory_gates,id'],
        ];
    }

    protected function resetForm(): void
    {
        $this->username = '';
        $this->fullname = null;
        $this->email = null;
        $this->role_id = null;
        $this->company_id = null;
        $this->department_id = null;
        $this->division_id = null;
        $this->password = null;
        $this->entrance_ids = [];
        $this->exit_location_ids = [];
    }

    protected function fillForm(int $id): void
    {
        $u = User::with('roles')->findOrFail($id);
        $this->username = $u->username;
        $this->fullname = $u->fullname;
        $this->email = $u->email;
        $this->role_id = optional(Role::where('name', $u->roles->first()?->name)->first())->id;
        $this->company_id = $u->company_id;
        $this->department_id = $u->department_id;
        $this->division_id = $u->division_id;
        $this->password = null;

        // Checkbox state wants strings — Livewire compares loosely on render but
        // strictly in `in_array` checks in the blade.
        $this->entrance_ids = DB::connection('core')->table('warehouse_gate_user')
            ->where('user_id', $id)->pluck('gate_id')->map('intval')->all();
        $this->exit_location_ids = DB::connection('core')->table('factory_gate_user')
            ->where('user_id', $id)->pluck('gate_id')->map('intval')->all();
    }

    protected function performDelete(int $id): void
    {
        DB::connection('core')->table('warehouse_gate_user')
            ->where('user_id', $id)->delete();
        DB::connection('core')->table('factory_gate_user')
            ->where('user_id', $id)->delete();
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
        // Admins span everything; everyone else is scoped to a company and a
        // department within it.
        $scoped = ! $this->isAdminRole();
        $u->company_id = $scoped ? $this->company_id : null;
        $u->department_id = $scoped ? $this->department_id : null;
        $u->division_id = $scoped ? $this->division_id : null;
        // Keep the legacy NOT NULL columns valid: userlevel mirrors the role's
        // legacy level; new rows get a default landing page.
        $u->userlevel = $role?->legacy_level ?? 1;
        if (! $this->editingId) {
            $u->redirection_id = 1;
        }
        $u->save();

        $u->syncRoles($role ? [$role] : []);
        $this->syncGates((int) $u->userid);

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'User updated.' : 'User added.');
    }

    /**
     * Replace this user's gate grants with what was ticked.
     *
     * Delete-then-insert rather than a diff: the sets are tiny (single digits),
     * and the whole thing runs in one transaction so a user is never briefly
     * left with no gates.
     */
    protected function syncGates(int $userId): void
    {
        $core = DB::connection('core');

        $core->transaction(function () use ($core, $userId) {
            $core->table('warehouse_gate_user')->where('user_id', $userId)->delete();
            if ($this->entrance_ids !== []) {
                $core->table('warehouse_gate_user')->insert(
                    array_map(fn ($id) => ['gate_id' => (int) $id, 'user_id' => $userId],
                        array_unique($this->entrance_ids))
                );
            }

            $core->table('factory_gate_user')->where('user_id', $userId)->delete();
            if ($this->exit_location_ids !== []) {
                $core->table('factory_gate_user')->insert(
                    array_map(fn ($id) => ['gate_id' => (int) $id, 'user_id' => $userId],
                        array_unique($this->exit_location_ids))
                );
            }
        });
    }
}
