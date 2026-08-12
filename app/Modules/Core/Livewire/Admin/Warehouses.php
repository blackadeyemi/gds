<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Company;
use Modules\Core\Models\Warehouse;

/**
 * Admin → Warehouses. The storage sibling of Factories: a company owns
 * factories where goods are made and warehouses where they are stored.
 *
 * Nothing is seeded — the legacy data had no warehouse concept to derive them
 * from, only a hard-coded `warehousecode = '01'`. The imported entrances stay
 * unusable until each is attached to a warehouse created here.
 */
#[Title('Warehouses')]
class Warehouses extends DataGrid
{
    public ?int $company_id = null;
    public ?string $module = null;
    public string $name = '';
    public ?string $code = null;
    public int $sort_order = 0;
    public bool $is_active = true;

    public function pageKey(): string { return 'admin.warehouses'; }
    public function pageLabel(): string { return 'Warehouses'; }
    public function pageSubtitle(): string { return 'Sites belonging to each company where goods are stored. Entrances are created under a warehouse.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.warehouse'; }
    public function defaultSort(): array { return ['name', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Warehouse', 'name'],
                    ['Stores', 'module', fn ($r) => $r->module
                        ? '<span class="badge ' . ($r->moduleIsImplemented() ? 'badge-success' : 'badge-muted') . '">'
                            . e($r->moduleLabel()) . ($r->moduleIsImplemented() ? '' : ' (not built)') . '</span>'
                        : '<span class="badge badge-danger">Not set</span>'],
                    ['Code', 'code', fn ($r) => $r->code ? '<span class="badge badge-muted">' . e($r->code) . '</span>' : '—'],
                    ['Company', 'company.name', fn ($r) => e($r->company?->name ?? '—')],
                    ['Gates', 'gates_count'],
                    ['Status', 'is_active', fn ($r) => '<span class="badge ' . ($r->is_active ? 'badge-success' : 'badge-muted') . '">' . ($r->is_active ? 'active' : 'inactive') . '</span>'],
                ],
                'query' => fn () => Warehouse::query()->with('company')->withCount('gates'),
                'searchable' => ['name', 'code'],
                'sortable' => ['name', 'module', 'code', 'sort_order', 'is_active'],
            ],
            'by_company' => [
                'label' => 'Summary (by company)',
                'type' => 'summary',
                'columns' => [
                    ['Company', 'company_name'],
                    ['Warehouses', 'total'],
                ],
                'query' => fn () => Warehouse::query()
                    ->leftJoin('companies', 'warehouses.company_id', '=', 'companies.id')
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

    /**
     * What a warehouse can store. Modules with no stock table behind them are
     * still offered but flagged, so it is obvious a warehouse created against
     * one cannot receive yet rather than silently doing nothing.
     */
    #[Computed]
    public function modules()
    {
        $implemented = config('warehouses.implemented', []);

        return collect(config('warehouses.modules'))
            ->map(fn ($label, $key) => in_array($key, $implemented, true) ? $label : $label . ' (not built yet)')
            ->all();
    }

    protected function rules(): array
    {
        $ignore = $this->editingId ? ',' . $this->editingId : '';

        return [
            'company_id' => ['required', 'exists:companies,id'],
            // What the warehouse stores. Required, because it decides which
            // product master its stock refers to — see config/warehouses.php.
            'module' => ['required', 'in:' . implode(',', array_keys(config('warehouses.modules')))],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20', 'unique:warehouses,code' . $ignore],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    protected function resetForm(): void
    {
        $this->company_id = null;
        $this->module = null;
        $this->name = '';
        $this->code = null;
        $this->sort_order = 0;
        $this->is_active = true;
    }

    protected function fillForm(int $id): void
    {
        $w = Warehouse::findOrFail($id);
        $this->company_id = $w->company_id;
        $this->module = $w->module;
        $this->name = $w->name;
        $this->code = $w->code;
        $this->sort_order = (int) $w->sort_order;
        $this->is_active = (bool) $w->is_active;
    }

    /** A warehouse can't go while gates still sit under it. */
    public function deleteGuard($row): ?string
    {
        $c = $row->gates_count ?? 0;

        return $c > 0
            ? 'In use by ' . $c . ' ' . Str::plural('gate', $c) . ' — cannot delete.'
            : null;
    }

    protected function findRow(int $id)
    {
        return Warehouse::withCount('gates')->find($id);
    }

    protected function performDelete(int $id): void
    {
        Warehouse::whereKey($id)->delete();
    }

    public function save(): void
    {
        $this->code = $this->code ? strtoupper(trim($this->code)) : null;
        $data = $this->validate();

        // Name is unique per company rather than globally — two companies may
        // each have a "Main Store". Same rule the Factories editor enforces.
        $clash = Warehouse::where('company_id', $data['company_id'])
            ->where('name', $data['name'])
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();

        if ($clash) {
            $this->addError('name', 'That company already has a warehouse with this name.');

            return;
        }

        Warehouse::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Warehouse updated.' : 'Warehouse added.');
    }
}
