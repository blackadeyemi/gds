<?php

namespace Modules\Core\Livewire\Admin;

use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Company;

#[Title('Company Management')]
class Companies extends DataGrid
{
    public string $name = '';

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
                    ['Departments', 'departments_count'],
                ],
                'query' => fn () => Company::query()->withCount('departments'),
                'searchable' => ['name'],
                'sortable' => ['name'],
            ],
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:companies,name' . ($this->editingId ? ',' . $this->editingId : '')],
        ];
    }

    protected function resetForm(): void { $this->name = ''; }

    protected function fillForm(int $id): void
    {
        $this->name = Company::findOrFail($id)->name;
    }

    protected function performDelete(int $id): void
    {
        Company::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();
        Company::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Company updated.' : 'Company added.');
    }
}
