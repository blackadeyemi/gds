<?php

namespace Modules\Bpl\Livewire\JumboRolls\Products;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplGrade;
use Modules\Bpl\Models\BplProductSoftroll;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Products → Softroll. New in the BPL rebuild.
 * A softroll product = grade + grammage + diameter (brightness stays per-roll
 * on production); productname is auto-built as "TYPE {grammage}gsm {diameter}d".
 */
#[Title('BPL Softroll Products')]
class Softroll extends DataGrid
{
    public ?int $grade_id = null;
    public string $grammage = '';
    public string $diameter = '';

    public function pageKey(): string { return 'bpl.jumbo-rolls.products.softroll'; }
    public function pageLabel(): string { return 'BPL Softroll Products'; }
    public function pageSubtitle(): string { return 'Softroll product master — grade, grammage and diameter.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.product-softroll'; }
    public function headerView(): ?string { return 'bpl::partials.product-tabs'; }
    public function defaultSort(): array { return ['productname', 'asc']; }
    public function modalSize(): string { return '520px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Product Name', 'productname'],
                    ['Grade', 'gradetype'],
                    ['Grammage', 'grammage'],
                    ['Diameter', 'diameter'],
                ],
                // Join the grade so the grade type is a real, sortable/searchable
                // column (aliases resolve in WHERE/ORDER BY too).
                'query' => fn () => BplProductSoftroll::query()
                    ->leftJoin('bpl_grades as g', 'bpl_products_softroll.grade_id', '=', 'g.id')
                    ->select(
                        'bpl_products_softroll.id',
                        'bpl_products_softroll.productname',
                        'bpl_products_softroll.grammage',
                        'bpl_products_softroll.diameter',
                        'g.type as gradetype',
                    ),
                'searchable' => ['productname', 'grammage', 'diameter', 'gradetype'],
                'sortable' => ['productname', 'gradetype', 'grammage', 'diameter'],
            ],
        ];
    }

    #[Computed]
    public function grades()
    {
        return BplGrade::query()->select('id', 'type', 'gradename')->orderBy('type')->get();
    }

    protected function rules(): array
    {
        return [
            'grade_id' => ['required', 'integer', 'exists:bpl.bpl_grades,id'],
            'grammage' => ['required', 'string', 'max:255'],
            'diameter' => ['required', 'string', 'max:255'],
        ];
    }

    protected function resetForm(): void
    {
        $this->grade_id = null;
        $this->grammage = '';
        $this->diameter = '';
    }

    protected function fillForm(int $id): void
    {
        $p = BplProductSoftroll::findOrFail($id);
        $this->grade_id = $p->grade_id;
        $this->grammage = (string) $p->grammage;
        $this->diameter = (string) $p->diameter;
    }

    protected function findRow(int $id)
    {
        return BplProductSoftroll::find($id);
    }

    protected function performDelete(int $id): void
    {
        BplProductSoftroll::whereKey($id)->delete();
    }

    /** "TYPE {grammage}gsm {diameter}d" — matches the cleanup-seed formatter. */
    protected function buildName(): string
    {
        $type = BplGrade::whereKey($this->grade_id)->value('type');
        return trim(sprintf('%s %sgsm %sd', $type, trim($this->grammage), trim($this->diameter)));
    }

    public function save(): void
    {
        $data = $this->validate();
        $data['grammage'] = trim($data['grammage']);
        $data['diameter'] = trim($data['diameter']);
        $data['productname'] = $this->buildName();

        // productname is unique (grade+grammage+diameter). Surface a clean
        // validation error instead of a raw duplicate-key exception.
        $exists = BplProductSoftroll::where('productname', $data['productname'])
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();
        if ($exists) {
            $this->addError('grammage', 'A softroll product with this grade, grammage and diameter already exists.');
            return;
        }

        BplProductSoftroll::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Softroll product updated.' : 'Softroll product added.');
    }
}
