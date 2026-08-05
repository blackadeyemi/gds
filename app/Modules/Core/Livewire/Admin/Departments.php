<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Company;
use Modules\Core\Models\Department;

#[Title('Department Management')]
class Departments extends DataGrid
{
    public string $name = '';
    public ?int $company_id = null;
    public string $status = 'active';

    public function pageKey(): string { return 'admin.departments'; }
    public function pageLabel(): string { return 'Department Management'; }
    public function pageSubtitle(): string { return 'Manage departments and the company each belongs to.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.department'; }
    public function defaultSort(): array { return ['name', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Name', 'name'],
                    ['Company', 'company.name', fn ($r) => e($r->company?->name ?? '—')],
                    ['Status', 'status', fn ($r) => '<span class="badge ' . ($r->status === 'active' ? 'badge-success' : 'badge-muted') . '">' . $r->status . '</span>'],
                    ['Divisions', 'divisions_count'],
                    ['Staff', 'staff_count'],
                    ['Users', 'users_count'],
                ],
                'query' => fn () => Department::query()->with('company')->withCount(['users', 'divisions', 'staff']),
                'searchable' => ['name'],
                'sortable' => ['name', 'status'],
            ],
            'by_company' => [
                'label' => 'Summary (by company)',
                'type' => 'summary',
                'columns' => [
                    ['Company', 'company_name'],
                    ['Departments', 'total'],
                ],
                'query' => fn () => Department::query()
                    ->leftJoin('companies', 'departments.company_id', '=', 'companies.id')
                    ->selectRaw("COALESCE(companies.name, '—') as company_name, COUNT(*) as total")
                    ->groupBy('company_name'),
            ],
        ];
    }

    #[Computed]
    public function companies()
    {
        return Company::orderBy('name')->get();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    protected function resetForm(): void
    {
        $this->name = '';
        $this->company_id = null;
        $this->status = 'active';
    }

    protected function fillForm(int $id): void
    {
        $d = Department::findOrFail($id);
        $this->name = $d->name;
        $this->company_id = $d->company_id;
        $this->status = $d->status;
    }

    /** A department can't be removed while anything still hangs off it. */
    public function deleteGuard($row): ?string
    {
        $parts = [];
        foreach (['users' => 'user', 'divisions' => 'division', 'staff' => 'staff member'] as $count => $noun) {
            if (($row->{$count . '_count'} ?? 0) > 0) {
                $parts[] = $row->{$count . '_count'} . ' ' . Str::plural($noun, $row->{$count . '_count'});
            }
        }

        return $parts ? 'In use by ' . implode(', ', $parts) . ' — cannot delete.' : null;
    }

    protected function findRow(int $id)
    {
        return Department::withCount(['users', 'divisions', 'staff'])->find($id);
    }

    protected function performDelete(int $id): void
    {
        Department::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();
        Department::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Department updated.' : 'Department added.');
    }
}
