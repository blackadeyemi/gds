<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Company;
use Modules\Core\Models\Factory;

#[Title('Factories')]
class Factories extends DataGrid
{
    public ?int $company_id = null;
    public string $name = '';
    public string $code = '';
    public bool $is_active = true;

    public function pageKey(): string { return 'admin.factories'; }
    public function pageLabel(): string { return 'Factories'; }
    public function pageSubtitle(): string { return 'Sites belonging to each company. Machines are created under a factory.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.factory'; }
    public function defaultSort(): array { return ['name', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Factory', 'name'],
                    ['Code', 'code', fn ($r) => $r->code ? '<span class="badge badge-muted">' . e($r->code) . '</span>' : '—'],
                    ['Company', 'company.name', fn ($r) => e($r->company?->name ?? '—')],
                    ['Lines', 'lines_count'],
                    ['Status', 'is_active', fn ($r) => '<span class="badge ' . ($r->is_active ? 'badge-success' : 'badge-muted') . '">' . ($r->is_active ? 'active' : 'inactive') . '</span>'],
                ],
                'query' => fn () => Factory::query()->with('company')->withCount('lines'),
                'searchable' => ['name', 'code'],
                'sortable' => ['name', 'code', 'is_active'],
            ],
            'by_company' => [
                'label' => 'Summary (by company)',
                'type' => 'summary',
                'columns' => [
                    ['Company', 'company_name'],
                    ['Factories', 'total'],
                ],
                'query' => fn () => Factory::query()
                    ->leftJoin('companies', 'factories.company_id', '=', 'companies.id')
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
        $ignore = $this->editingId ? ',' . $this->editingId : '';

        return [
            'company_id' => ['required', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            // The legacy data matches factories on this string, so it has to
            // stay unique and stable even when the display name changes.
            'code' => ['required', 'string', 'max:16', 'unique:factories,code' . $ignore],
            'is_active' => ['boolean'],
        ];
    }

    protected function resetForm(): void
    {
        $this->company_id = null;
        $this->name = '';
        $this->code = '';
        $this->is_active = true;
    }

    protected function fillForm(int $id): void
    {
        $f = Factory::findOrFail($id);
        $this->company_id = $f->company_id;
        $this->name = $f->name;
        $this->code = (string) $f->code;
        $this->is_active = (bool) $f->is_active;
    }

    /** A factory can't be removed while machines still sit under it. */
    public function deleteGuard($row): ?string
    {
        $c = $row->lines_count ?? 0;

        return $c > 0
            ? 'In use by ' . $c . ' ' . Str::plural('line', $c) . ' — cannot delete.'
            : null;
    }

    protected function findRow(int $id)
    {
        return Factory::withCount('lines')->find($id);
    }

    protected function performDelete(int $id): void
    {
        Factory::whereKey($id)->delete();
    }

    public function save(): void
    {
        $this->code = strtoupper(trim($this->code));
        $data = $this->validate();

        // Name is unique per company rather than globally — two companies may
        // each have a "Plant 1".
        $clash = Factory::where('company_id', $data['company_id'])
            ->where('name', $data['name'])
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();

        if ($clash) {
            $this->addError('name', 'That company already has a factory with this name.');

            return;
        }

        Factory::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Factory updated.' : 'Factory added.');
    }
}
