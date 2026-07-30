<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Page;
use Modules\Core\Models\Permission;

#[Title('Permissions')]
class Permissions extends DataGrid
{
    public string $name = '';
    public ?string $description = null;
    /** @var array<int,int> page ids this permission grants access to */
    public array $selectedPages = [];

    public function pageKey(): string { return 'admin.permissions'; }
    public function pageLabel(): string { return 'Permissions'; }
    public function pageSubtitle(): string { return 'A permission grants access to a set of pages. Assign permissions to roles.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.permission'; }
    public function defaultSort(): array { return ['name', 'asc']; }
    public function modalSize(): string { return '640px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Permission Name', 'name'],
                    ['Description', 'description', fn ($r) => e($r->description ?? '—')],
                    ['Pages', 'pages_count', fn ($r) => '<span class="badge badge-muted">' . ($r->pages_count ?? 0) . '</span>'],
                    ['Roles', 'roles_count', fn ($r) => '<span class="badge badge-muted">' . ($r->roles_count ?? 0) . '</span>'],
                ],
                'query' => fn () => Permission::query()->withCount('pages')->withCount('roles'),
                'searchable' => ['name', 'description'],
                'sortable' => ['name'],
            ],
        ];
    }

    /** Pages grouped by module for the access checklist. */
    #[Computed]
    public function pagesByModule()
    {
        return Page::orderBy('sort_order')->get()->groupBy(fn ($p) => $p->module ?? 'Other');
    }

    /** Select/clear every page in a module group at once. */
    public function toggleModulePages(string $module, bool $on): void
    {
        $ids = Page::query()
            ->when($module === 'Other', fn ($q) => $q->whereNull('module'), fn ($q) => $q->where('module', $module))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        $current = array_map('intval', $this->selectedPages);
        $this->selectedPages = $on
            ? array_values(array_unique(array_merge($current, $ids)))
            : array_values(array_diff($current, $ids));
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:permissions,name' . ($this->editingId ? ',' . $this->editingId : '')],
            'description' => ['nullable', 'string', 'max:255'],
            'selectedPages' => ['array'],
            'selectedPages.*' => ['integer', 'exists:pages,id'],
        ];
    }

    protected function resetForm(): void
    {
        $this->name = '';
        $this->description = null;
        $this->selectedPages = [];
    }

    protected function fillForm(int $id): void
    {
        $p = Permission::with('pages')->findOrFail($id);
        $this->name = $p->name;
        $this->description = $p->description;
        $this->selectedPages = $p->pages->pluck('id')->map(fn ($i) => (int) $i)->all();
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
        $this->validate();

        $perm = Permission::updateOrCreate(
            ['id' => $this->editingId],
            ['name' => $this->name, 'description' => $this->description, 'guard_name' => 'web']
        );
        $perm->pages()->sync($this->selectedPages);

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Permission updated.' : 'Permission added.');
    }
}
