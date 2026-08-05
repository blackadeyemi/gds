<?php

namespace Modules\Bil\Livewire\Machines;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\MachineLine;
use Modules\Core\Models\MachineProject;

/**
 * Projects and sub-projects in one grid — same self-referencing shape as
 * Machines > Lines. @see \Modules\Bil\Livewire\Machines\Lines
 */
#[Title('Machine Projects')]
class Projects extends DataGrid
{
    public ?int $line_id = null;
    public ?int $parent_id = null;
    public string $name = '';
    public string $code = '';
    public bool $is_active = true;

    public function pageKey(): string { return 'bil.machines.projects'; }
    public function pageLabel(): string { return 'Projects'; }
    public function pageSubtitle(): string { return 'Projects and sub-projects, and the line each runs on.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bil::livewire.forms.machine-project'; }
    public function defaultSort(): array { return []; }

    public function views(): array
    {
        return [
            'tree' => [
                'label' => 'Tree',
                'type' => 'table',
                'columns' => [
                    ['Project', 'name', fn ($r) => $this->nameCell($r)],
                    ['Code', 'code', fn ($r) => $r->code ? '<span class="badge badge-muted">' . e($r->code) . '</span>' : '—'],
                    ['Line', 'line.name', fn ($r) => e($r->line?->name ?? '—')],
                    ['Factory', 'line.factory.name', fn ($r) => e($r->line?->factory?->name ?? 'Unassigned')],
                    ['Status', 'is_active', fn ($r) => $this->statusCell($r)],
                ],
                'query' => fn () => MachineProject::query()->with('line.factory')->treeOrder(),
                'searchable' => ['name', 'code'],
            ],
            'flat' => [
                'label' => 'Flat list',
                'type' => 'table',
                'columns' => [
                    ['Project', 'name'],
                    ['Code', 'code', fn ($r) => $r->code ? '<span class="badge badge-muted">' . e($r->code) . '</span>' : '—'],
                    ['Parent', 'parent.name', fn ($r) => e($r->parent?->name ?? '—')],
                    ['Line', 'line.name', fn ($r) => e($r->line?->name ?? '—')],
                    ['Factory', 'line.factory.name', fn ($r) => e($r->line?->factory?->name ?? 'Unassigned')],
                    ['Status', 'is_active', fn ($r) => $this->statusCell($r)],
                ],
                'query' => fn () => MachineProject::query()->with(['line.factory', 'parent'])->orderBy('name'),
                'searchable' => ['name', 'code'],
                'sortable' => ['name', 'code', 'is_active'],
            ],
            'by_line' => [
                'label' => 'Summary (by line)',
                'type' => 'summary',
                'columns' => [
                    ['Line', 'line_name'],
                    ['Projects', 'total'],
                ],
                'query' => fn () => MachineProject::query()
                    ->leftJoin('machine_lines', 'machine_projects.line_id', '=', 'machine_lines.id')
                    ->selectRaw("COALESCE(machine_lines.name, '—') as line_name, COUNT(*) as total")
                    ->groupBy('line_name'),
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

    /** Every line node — a project may sit on a sub-line or on a line directly. */
    #[Computed]
    public function lines()
    {
        return MachineLine::with('factory')->treeOrder()->get();
    }

    #[Computed]
    public function parents()
    {
        return MachineProject::roots()
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->orderBy('name')
            ->get();
    }

    protected function rules(): array
    {
        $ignore = $this->editingId ? ',' . $this->editingId : '';

        return [
            // Only required at top level: a sub-project takes its parent's line,
            // and the form hides the Line field once a parent is chosen.
            'line_id' => [$this->parent_id ? 'nullable' : 'required', 'exists:machine_lines,id'],
            'parent_id' => ['nullable', 'exists:machine_projects,id'],
            'name' => ['required', 'string', 'max:255', 'unique:machine_projects,name' . $ignore],
            'code' => ['nullable', 'string', 'max:32'],
            'is_active' => ['boolean'],
        ];
    }

    protected function resetForm(): void
    {
        $this->line_id = null;
        $this->parent_id = null;
        $this->name = '';
        $this->code = '';
        $this->is_active = true;
    }

    protected function fillForm(int $id): void
    {
        $p = MachineProject::findOrFail($id);
        $this->line_id = $p->line_id;
        $this->parent_id = $p->parent_id;
        $this->name = $p->name;
        $this->code = (string) $p->code;
        $this->is_active = (bool) $p->is_active;
    }

    public function deleteGuard($row): ?string
    {
        $c = $row->children_count ?? 0;

        return $c > 0
            ? 'In use by ' . $c . ' ' . Str::plural('sub-project', $c) . ' — cannot delete.'
            : null;
    }

    protected function findRow(int $id)
    {
        return MachineProject::withCount('children')->find($id);
    }

    protected function performDelete(int $id): void
    {
        MachineProject::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();

        // A sub-project runs on the same line as its parent.
        if ($data['parent_id']) {
            $data['line_id'] = MachineProject::whereKey($data['parent_id'])->value('line_id');
        }

        MachineProject::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Project updated.' : 'Project added.');
    }
}
