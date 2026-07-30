<?php

namespace Modules\Bpl\Livewire\Products;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplGrade;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Products → Hardroll. Rebuilt from legacy bpl_products.php.
 * A hardroll product = grade type + gsm, ply, width, diameter, slice, brightness;
 * productname is auto-built from those (matches the legacy JS formatter).
 */
#[Title('BPL Hardroll Products')]
class Hardroll extends DataGrid
{
    public string $gradetype = '';
    public ?float $gsm = null;
    public ?int $ply = null;
    public ?float $width = null;
    public ?float $brightness = null;
    public ?float $diameter = null;
    public ?int $slice = null;

    public function pageKey(): string { return 'bpl.products.hardroll'; }
    public function pageLabel(): string { return 'BPL Hardroll Products'; }
    public function pageSubtitle(): string { return 'Hardroll product master — grade type, gsm, ply, width, diameter, slice.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.product-hardroll'; }
    public function headerView(): ?string { return 'bpl::partials.product-tabs'; }
    public function defaultSort(): array { return ['productname', 'asc']; }
    public function modalSize(): string { return '640px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Product Name', 'productname'],
                    ['Grade Type', 'gradetype'],
                    ['GSM', 'gsm'],
                    ['Ply', 'ply'],
                    ['Width', 'width'],
                    ['Diameter', 'diameter'],
                    ['Slice', 'slice'],
                    ['Brightness', 'brightness'],
                ],
                'query' => fn () => BplProductHardroll::query()
                    ->select('id', 'productname', 'gradetype', 'gsm', 'ply', 'width', 'diameter', 'slice', 'brightness'),
                'searchable' => ['productname', 'gradetype'],
                'sortable' => ['productname', 'gradetype', 'gsm', 'ply', 'width', 'diameter', 'slice', 'brightness'],
            ],
        ];
    }

    /** Grade types (the legacy form picks the grade by its `type` string). */
    #[Computed]
    public function gradeTypes()
    {
        return BplGrade::query()->select('type')->distinct()->orderBy('type')->pluck('type');
    }

    protected function rules(): array
    {
        return [
            'gradetype' => ['required', 'string', 'max:20'],
            'gsm' => ['required', 'numeric', 'min:0'],
            'ply' => ['required', 'integer', 'min:1'],
            'width' => ['required', 'numeric', 'min:0'],
            'brightness' => ['nullable', 'numeric', 'min:0'],
            'diameter' => ['required', 'numeric', 'min:0'],
            'slice' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function resetForm(): void
    {
        $this->gradetype = '';
        $this->gsm = null;
        $this->ply = null;
        $this->width = null;
        $this->brightness = null;
        $this->diameter = null;
        $this->slice = null;
    }

    protected function fillForm(int $id): void
    {
        $p = BplProductHardroll::findOrFail($id);
        $this->gradetype = (string) $p->gradetype;
        $this->gsm = $p->gsm;
        $this->ply = $p->ply;
        $this->width = $p->width;
        $this->brightness = $p->brightness;
        $this->diameter = $p->diameter;
        $this->slice = $p->slice;
    }

    protected function findRow(int $id)
    {
        return BplProductHardroll::find($id);
    }

    protected function performDelete(int $id): void
    {
        BplProductHardroll::whereKey($id)->delete();
    }

    /** Mirror the legacy productname formatter: "TYPE 20.0gsm 2p 200w 90d 4s".
     *  gsm is fixed to 1 decimal (legacy .toFixed(1)); width/diameter show as
     *  integers when whole (200.0 -> "200"), else keep their decimals. */
    protected function buildName(): string
    {
        $num = fn ($v) => (float) $v == (int) $v ? (string) (int) $v : (string) (float) $v;

        return sprintf(
            '%s %sgsm %dp %sw %sd %ds',
            $this->gradetype,
            number_format((float) $this->gsm, 1),
            (int) $this->ply,
            $num($this->width),
            $num($this->diameter),
            (int) $this->slice,
        );
    }

    public function save(): void
    {
        $data = $this->validate();
        $data['productname'] = $this->buildName();

        // productname is unique. Surface a clean validation error instead of a
        // raw duplicate-key exception when the same spec already exists.
        $exists = BplProductHardroll::where('productname', $data['productname'])
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();
        if ($exists) {
            $this->addError('gsm', 'A hardroll product with this exact specification already exists.');
            return;
        }

        BplProductHardroll::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Hardroll product updated.' : 'Hardroll product added.');
    }
}
