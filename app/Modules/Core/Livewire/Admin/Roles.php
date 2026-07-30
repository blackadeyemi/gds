<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Page;
use Modules\Core\Models\Role;

#[Title('Roles')]
class Roles extends DataGrid
{
    public string $name = '';
    public ?string $description = null;
    /** @var array<int,string> granted "{page.key}:{ability}" permission names */
    public array $granted = [];

    public ?int $viewingRoleId = null;

    public function pageKey(): string { return 'admin.roles'; }
    public function pageLabel(): string { return 'Roles'; }
    public function pageSubtitle(): string { return 'A role grants abilities per page. Assign roles to users.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.role'; }
    public function extraView(): ?string { return 'core::livewire.role-permissions-modal'; }
    public function defaultSort(): array { return ['name', 'asc']; }
    public function modalSize(): string { return '920px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Role Name', 'name', fn ($r) => '<a href="#" wire:click.prevent="showPermissions(' . $r->id . ')" style="color:var(--brand);font-weight:600;text-decoration:none;cursor:pointer;">' . e($r->name) . '</a>'],
                    ['Description', 'description', fn ($r) => e($r->description ?? '—')],
                    ['Abilities', 'permissions_count', fn ($r) => '<span class="badge badge-muted">' . $r->permissions_count . '</span>'],
                    ['Users', 'users_count', fn ($r) => '<span class="badge badge-muted">' . ($r->users_count ?? 0) . '</span>'],
                ],
                'query' => fn () => Role::query()->withCount('permissions')->withCount('users'),
                'searchable' => ['name', 'description'],
                'sortable' => ['name'],
            ],
        ];
    }

    /** The page × ability grid: ability columns + pages grouped by module. */
    #[Computed]
    public function matrix(): array
    {
        return [
            'columns' => config('pages.abilities', []),
            'groups' => Page::orderBy('sort_order')->get()
                ->groupBy(fn ($p) => $p->module ?? 'Other')
                ->map(fn ($grp) => $grp->map(fn ($p) => [
                    'key' => $p->key,
                    'label' => $p->label,
                    'abilities' => $p->abilities ?? [],
                ])->values()),
        ];
    }

    /** Grant/clear every ability of one page at once. */
    public function togglePage(string $key, bool $on): void
    {
        $page = Page::where('key', $key)->first();
        if (! $page) {
            return;
        }
        $names = array_map(fn ($a) => "$key:$a", $page->abilities ?? []);
        $this->granted = $on
            ? array_values(array_unique(array_merge($this->granted, $names)))
            : array_values(array_diff($this->granted, $names));
    }

    public function showPermissions(int $id): void
    {
        $this->viewingRoleId = $id;
    }

    public function edit(int $id): void
    {
        $this->viewingRoleId = null;
        parent::edit($id);
    }

    /** Read-only view: abilities the role holds, grouped by module then page. */
    #[Computed]
    public function viewingRole()
    {
        if (! $this->viewingRoleId) {
            return null;
        }
        $role = Role::with('permissions')->find($this->viewingRoleId);
        if (! $role) {
            return null;
        }

        $held = $role->permissions->pluck('name')->all();
        $labels = config('pages.abilities', []);
        $groups = [];
        $count = 0;

        foreach (Page::orderBy('sort_order')->get() as $p) {
            $abilities = array_values(array_filter($p->abilities ?? [], fn ($a) => in_array("{$p->key}:$a", $held, true)));
            if ($abilities) {
                $count += count($abilities);
                $groups[$p->module ?? 'Other'][] = [
                    'label' => $p->label,
                    'abilities' => array_map(fn ($a) => $labels[$a] ?? $a, $abilities),
                ];
            }
        }

        return [
            'name' => $role->name,
            'description' => $role->description,
            'count' => $count,
            'groups' => $groups,
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name' . ($this->editingId ? ',' . $this->editingId : '')],
            'description' => ['nullable', 'string', 'max:255'],
            'granted' => ['array'],
            'granted.*' => ['string'],
        ];
    }

    protected function resetForm(): void
    {
        $this->name = '';
        $this->description = null;
        $this->granted = [];
    }

    protected function fillForm(int $id): void
    {
        $role = Role::with('permissions')->findOrFail($id);
        $this->name = $role->name;
        $this->description = $role->description;
        $this->granted = $role->permissions->pluck('name')->all();
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

        // Only sync valid page-ability permissions (ignore anything stale).
        $valid = \Modules\Core\Models\Permission::whereIn('name', $this->granted)->pluck('name')->all();
        $role->syncPermissions($valid);

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Role updated.' : 'Role added.');
    }
}
