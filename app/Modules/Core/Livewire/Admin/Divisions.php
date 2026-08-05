<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Department;
use Modules\Core\Models\Division;

#[Title('Division Management')]
class Divisions extends DataGrid
{
    public ?int $department_id = null;
    public string $name = '';
    public string $legacy_name = '';
    public bool $is_active = true;

    public function pageKey(): string { return 'admin.divisions'; }
    public function pageLabel(): string { return 'Division Management'; }
    public function pageSubtitle(): string { return 'Divisions within a department. Staff belong to a department, and optionally to a division within it.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.division'; }
    public function defaultSort(): array { return ['name', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Division', 'name'],
                    ['Department', 'department.name', fn ($r) => e($r->department?->name ?? '—')],
                    ['Staff', 'staff_count'],
                    ['Users', 'users_count'],
                    ['Status', 'is_active', fn ($r) => '<span class="badge ' . ($r->is_active ? 'badge-success' : 'badge-muted') . '">' . ($r->is_active ? 'active' : 'inactive') . '</span>'],
                ],
                'query' => fn () => Division::query()->with('department')->withCount(['staff', 'users']),
                'searchable' => ['name', 'legacy_name'],
                'sortable' => ['name', 'is_active'],
            ],
            'by_department' => [
                'label' => 'Summary (by department)',
                'type' => 'summary',
                'columns' => [
                    ['Department', 'department_name'],
                    ['Divisions', 'total'],
                ],
                'query' => fn () => Division::query()
                    ->leftJoin('departments', 'divisions.department_id', '=', 'departments.id')
                    ->selectRaw("COALESCE(departments.name, '—') as department_name, COUNT(*) as total")
                    ->groupBy('department_name'),
            ],
        ];
    }

    #[Computed]
    public function departments()
    {
        return Department::orderBy('name')->get();
    }

    protected function rules(): array
    {
        return [
            'department_id' => ['required', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    protected function resetForm(): void
    {
        $this->department_id = null;
        $this->name = '';
        $this->legacy_name = '';
        $this->is_active = true;
    }

    protected function fillForm(int $id): void
    {
        $d = Division::findOrFail($id);
        $this->department_id = $d->department_id;
        $this->name = $d->name;
        $this->legacy_name = (string) $d->legacy_name;
        $this->is_active = (bool) $d->is_active;
    }

    /** Blocked while staff or users still sit in it. */
    public function deleteGuard($row): ?string
    {
        $parts = [];
        if (($row->staff_count ?? 0) > 0) {
            $parts[] = $row->staff_count . ' ' . Str::plural('staff member', $row->staff_count);
        }
        if (($row->users_count ?? 0) > 0) {
            $parts[] = $row->users_count . ' ' . Str::plural('user', $row->users_count);
        }

        return $parts ? 'In use by ' . implode(' and ', $parts) . ' — cannot delete.' : null;
    }

    protected function findRow(int $id)
    {
        return Division::withCount(['staff', 'users'])->find($id);
    }

    protected function performDelete(int $id): void
    {
        Division::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();

        // Unique per department, not globally — two departments may each have a
        // division with the same name.
        $clash = Division::where('department_id', $data['department_id'])
            ->where('name', $data['name'])
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();

        if ($clash) {
            $this->addError('name', 'That department already has a division with this name.');

            return;
        }

        // legacy_name is migration-owned: it is what the legacy tables call this
        // division, so it is shown read-only in the form and never rewritten here.
        Division::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Division updated.' : 'Division added.');
    }
}
