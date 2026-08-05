<?php

namespace Modules\Core\Livewire\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\ServiceType;

#[Title('Service Types')]
class ServiceTypes extends DataGrid
{
    public string $name = '';
    public ?int $sort_order = 0;
    public bool $is_active = true;

    public function pageKey(): string { return 'settings.service_types'; }
    public function pageLabel(): string { return 'Service Types'; }
    public function pageSubtitle(): string { return 'The kinds of work logged against a machine — Maintenance, Repair, and any others you need.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.service-type'; }
    public function defaultSort(): array { return ['sort_order', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Service type', 'name'],
                    ['Jobs', 'jobs', fn ($r) => number_format($this->jobCount($r->id))],
                    ['Order', 'sort_order'],
                    ['Status', 'is_active', fn ($r) => '<span class="badge ' . ($r->is_active ? 'badge-success' : 'badge-muted') . '">' . ($r->is_active ? 'active' : 'inactive') . '</span>'],
                ],
                'query' => fn () => ServiceType::query(),
                'searchable' => ['name'],
                'sortable' => ['name', 'sort_order', 'is_active'],
            ],
        ];
    }

    /** Service jobs already classified as this type (cross-database, so counted here). */
    private function jobCount(int $id): int
    {
        return DB::connection('bil')->table('factory_machine_maintenance')
            ->where('service_type_id', $id)->count();
    }

    protected function rules(): array
    {
        $ignore = $this->editingId ? ',' . $this->editingId : '';

        return [
            'name' => ['required', 'string', 'max:255', 'unique:service_types,name' . $ignore],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    protected function resetForm(): void
    {
        $this->name = '';
        $this->sort_order = 0;
        $this->is_active = true;
    }

    protected function fillForm(int $id): void
    {
        $t = ServiceType::findOrFail($id);
        $this->name = $t->name;
        $this->sort_order = $t->sort_order;
        $this->is_active = (bool) $t->is_active;
    }

    /** Blocked once jobs are classified as it — deactivate instead. */
    public function deleteGuard($row): ?string
    {
        $c = $this->jobCount($row->id);

        return $c > 0
            ? 'Used by ' . number_format($c) . ' service ' . Str::plural('job', $c) . ' — set inactive instead.'
            : null;
    }

    protected function findRow(int $id)
    {
        return ServiceType::find($id);
    }

    protected function performDelete(int $id): void
    {
        ServiceType::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();
        $data['sort_order'] = $data['sort_order'] ?? 0;
        ServiceType::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Service type updated.' : 'Service type added.');
    }
}
