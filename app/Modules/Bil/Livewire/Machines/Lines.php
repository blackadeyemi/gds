<?php

namespace Modules\Bil\Livewire\Machines;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Factory;
use Modules\Core\Models\MachineLine;

/**
 * Lines and sub-lines in one grid. They are the same table (self-referencing
 * parent_id), so "add a sub-line" is just create-with-a-parent — no second
 * screen, and the tree view keeps each parent above its own children.
 */
#[Title('Machine Lines')]
class Lines extends DataGrid
{
    public ?int $factory_id = null;
    public ?int $parent_id = null;
    public string $name = '';
    public string $code = '';
    public bool $is_active = true;

    public function pageKey(): string { return 'bil.machines.lines'; }
    public function pageLabel(): string { return 'Lines'; }
    public function pageSubtitle(): string { return 'Production lines and their sub-lines, grouped by factory.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bil::livewire.forms.machine-line'; }

    /** Empty so the tree view's own ordering isn't overridden on mount. */
    public function defaultSort(): array { return []; }

    public function views(): array
    {
        return [
            'tree' => [
                'label' => 'Tree',
                'type' => 'table',
                'columns' => [
                    ['Line', 'name', fn ($r) => $this->nameCell($r)],
                    ['Code', 'code', fn ($r) => $r->code ? '<span class="badge badge-muted">' . e($r->code) . '</span>' : '—'],
                    ['Factory', 'factory.name', fn ($r) => e($r->factory?->name ?? 'Unassigned')],
                    ['Projects', 'projects_count'],
                    ['Status', 'is_active', fn ($r) => $this->statusCell($r)],
                ],
                'query' => fn () => MachineLine::query()->with('factory')->withCount('projects')->treeOrder(),
                'searchable' => ['name', 'code'],
            ],
            'flat' => [
                'label' => 'Flat list',
                'type' => 'table',
                'columns' => [
                    ['Line', 'name'],
                    ['Code', 'code', fn ($r) => $r->code ? '<span class="badge badge-muted">' . e($r->code) . '</span>' : '—'],
                    ['Parent', 'parent.name', fn ($r) => e($r->parent?->name ?? '—')],
                    ['Factory', 'factory.name', fn ($r) => e($r->factory?->name ?? 'Unassigned')],
                    ['Projects', 'projects_count'],
                    ['Status', 'is_active', fn ($r) => $this->statusCell($r)],
                ],
                'query' => fn () => MachineLine::query()->with(['factory', 'parent'])->withCount('projects')->orderBy('name'),
                'searchable' => ['name', 'code'],
                'sortable' => ['name', 'code', 'is_active'],
            ],
            'by_factory' => [
                'label' => 'Summary (by factory)',
                'type' => 'summary',
                'columns' => [
                    ['Factory', 'factory_name'],
                    ['Lines', 'total'],
                ],
                'query' => fn () => MachineLine::query()
                    ->leftJoin('factories', 'machine_lines.factory_id', '=', 'factories.id')
                    ->selectRaw("COALESCE(factories.name, 'Unassigned') as factory_name, COUNT(*) as total")
                    ->groupBy('factory_name'),
            ],
        ];
    }

    private function nameCell($r): string
    {
        return $r->parent_id
            ? '<span style="padding-left:1.5rem;opacity:.8">&#8627; ' . e($r->name) . '</span>'
            : '<strong>' . e($r->name) . '</strong>';
    }

    private function statusCell($r): string
    {
        return '<span class="badge ' . ($r->is_active ? 'badge-success' : 'badge-muted') . '">'
            . ($r->is_active ? 'active' : 'inactive') . '</span>';
    }

    #[Computed]
    public function factories()
    {
        return Factory::with('company')->orderBy('name')->get();
    }

    /** Candidate parents — only top-level lines, and never the row being edited. */
    #[Computed]
    public function parents()
    {
        return MachineLine::roots()
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->orderBy('name')
            ->get();
    }

    protected function rules(): array
    {
        $ignore = $this->editingId ? ',' . $this->editingId : '';

        return [
            'factory_id' => ['nullable', 'exists:factories,id'],
            'parent_id' => ['nullable', 'exists:machine_lines,id'],
            // Globally unique across lines AND sub-lines: the compatibility
            // views and every legacy name-join depend on it.
            'name' => ['required', 'string', 'max:255', 'unique:machine_lines,name' . $ignore],
            'code' => ['nullable', 'string', 'max:16'],
            'is_active' => ['boolean'],
        ];
    }

    protected function resetForm(): void
    {
        $this->factory_id = null;
        $this->parent_id = null;
        $this->name = '';
        $this->code = '';
        $this->is_active = true;
    }

    protected function fillForm(int $id): void
    {
        $l = MachineLine::findOrFail($id);
        $this->factory_id = $l->factory_id;
        $this->parent_id = $l->parent_id;
        $this->name = $l->name;
        $this->code = (string) $l->code;
        $this->is_active = (bool) $l->is_active;
    }

    /**
     * Blocked while anything hangs off it. History is NOT a reason to block —
     * these are soft deletes, so line_id on past rows still resolves.
     */
    public function deleteGuard($row): ?string
    {
        $parts = [];
        if (($row->children_count ?? 0) > 0) {
            $parts[] = $row->children_count . ' ' . Str::plural('sub-line', $row->children_count);
        }
        if (($row->projects_count ?? 0) > 0) {
            $parts[] = $row->projects_count . ' ' . Str::plural('project', $row->projects_count);
        }

        return $parts ? 'In use by ' . implode(' and ', $parts) . ' — cannot delete.' : null;
    }

    protected function findRow(int $id)
    {
        return MachineLine::withCount(['children', 'projects'])->find($id);
    }

    protected function performDelete(int $id): void
    {
        MachineLine::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();

        // A sub-line always belongs to whatever factory its parent belongs to;
        // deriving it here stops the two drifting apart.
        if ($data['parent_id']) {
            $data['factory_id'] = MachineLine::whereKey($data['parent_id'])->value('factory_id');
        }

        MachineLine::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Line updated.' : 'Line added.');
    }
}
