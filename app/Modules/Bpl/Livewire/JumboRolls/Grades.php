<?php

namespace Modules\Bpl\Livewire\JumboRolls;

use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplGrade;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Bpl\Models\BplProductSoftroll;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Grades. Rebuilt from legacy bpl_grades.php: the grade master
 * (name, type, grade) that is parent to both hardroll and softroll products.
 */
#[Title('BPL Grades')]
class Grades extends DataGrid
{
    public string $gradename = '';
    public string $type = '';
    public string $grade = '';

    public function pageKey(): string { return 'bpl.jumbo-rolls.grades'; }
    public function pageLabel(): string { return 'BPL Grades'; }
    public function pageSubtitle(): string { return 'Grade master — parent to hardroll and softroll products.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.grade'; }
    public function defaultSort(): array { return ['type', 'asc']; }
    public function modalSize(): string { return '480px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Grade Name', 'gradename'],
                    ['Grade Type', 'type'],
                    ['Grade', 'grade'],
                ],
                'query' => fn () => BplGrade::query()
                    ->select('id', 'gradename', 'type', 'grade'),
                'searchable' => ['gradename', 'type', 'grade'],
                'sortable' => ['gradename', 'type', 'grade'],
            ],
        ];
    }

    protected function rules(): array
    {
        return [
            'gradename' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:15'],
            'grade' => ['nullable', 'string', 'max:20'],
        ];
    }

    protected function resetForm(): void
    {
        $this->gradename = '';
        $this->type = '';
        $this->grade = '';
    }

    protected function fillForm(int $id): void
    {
        $g = BplGrade::findOrFail($id);
        $this->gradename = (string) $g->gradename;
        $this->type = (string) $g->type;
        $this->grade = (string) $g->grade;
    }

    protected function findRow(int $id)
    {
        return BplGrade::find($id);
    }

    /**
     * Product counts keyed for the delete guard, loaded once per render:
     * hardroll references a grade by its `type` string, softroll by `grade_id`.
     * (Both models soft-delete, so only live products count.)
     */
    protected ?array $hardCountsByType = null;
    protected ?array $softCountsByGradeId = null;

    /** Block deleting a grade that still has hardroll or softroll products. */
    public function deleteGuard($row): ?string
    {
        $this->hardCountsByType ??= BplProductHardroll::query()
            ->selectRaw('gradetype, COUNT(*) as c')->groupBy('gradetype')
            ->pluck('c', 'gradetype')->all();
        $this->softCountsByGradeId ??= BplProductSoftroll::query()
            ->selectRaw('grade_id, COUNT(*) as c')->groupBy('grade_id')
            ->pluck('c', 'grade_id')->all();

        $hard = (int) ($this->hardCountsByType[$row->type] ?? 0);
        $soft = (int) ($this->softCountsByGradeId[$row->id] ?? 0);

        if ($hard + $soft === 0) {
            return null;
        }

        $parts = [];
        if ($hard) $parts[] = "$hard hardroll";
        if ($soft) $parts[] = "$soft softroll";

        return 'In use by ' . implode(' + ', $parts) . ' product' . ($hard + $soft > 1 ? 's' : '') . ' — cannot delete.';
    }

    protected function performDelete(int $id): void
    {
        BplGrade::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();
        BplGrade::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Grade updated.' : 'Grade added.');
    }
}
