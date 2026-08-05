<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Company;

#[Title('Company Management')]
class Companies extends DataGrid
{
    public string $name = '';
    public string $code = '';

    public function pageKey(): string { return 'admin.companies'; }
    public function pageLabel(): string { return 'Company Management'; }
    public function pageSubtitle(): string { return 'Companies that departments and users belong to.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.company'; }
    public function defaultSort(): array { return ['name', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Name', 'name'],
                    ['Code', 'code', fn ($r) => $r->code ? '<span class="badge badge-muted">' . e($r->code) . '</span>' : '—'],
                    ['Departments', 'departments_count'],
                    ['Users', 'users_count'],
                ],
                'query' => fn () => Company::query()->withCount(['departments', 'users']),
                'searchable' => ['name', 'code'],
                'sortable' => ['name', 'code'],
            ],
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:companies,name' . ($this->editingId ? ',' . $this->editingId : '')],
            'code' => ['required', 'string', 'max:8', 'unique:companies,code' . ($this->editingId ? ',' . $this->editingId : '')],
        ];
    }

    protected function resetForm(): void
    {
        $this->name = '';
        $this->code = '';
    }

    protected function fillForm(int $id): void
    {
        $c = Company::findOrFail($id);
        $this->name = $c->name;
        $this->code = (string) $c->code;
    }

    /** A company can't be removed while departments or users still belong to it. */
    public function deleteGuard($row): ?string
    {
        $parts = [];
        if (($row->departments_count ?? 0) > 0) {
            $parts[] = $row->departments_count . ' ' . Str::plural('department', $row->departments_count);
        }
        if (($row->users_count ?? 0) > 0) {
            $parts[] = $row->users_count . ' ' . Str::plural('user', $row->users_count);
        }

        return $parts ? 'In use by ' . implode(' and ', $parts) . ' — cannot delete.' : null;
    }

    protected function findRow(int $id)
    {
        return Company::withCount(['departments', 'users'])->find($id);
    }

    protected function performDelete(int $id): void
    {
        Company::whereKey($id)->delete();
    }

    public function save(): void
    {
        // Codes are an identifier, not prose — normalise before validating so the
        // uniqueness check can't be sidestepped by casing ("bil" vs "BIL").
        $this->code = strtoupper(trim($this->code));

        $data = $this->validate();
        Company::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Company updated.' : 'Company added.');
    }
}
