<?php

namespace Modules\Bpl\Livewire\JumboRolls\Production;

use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplProductSoftroll;
use Modules\Bpl\Models\BplSoftrollProduction;
use Modules\Bpl\Support\RollBarcode;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Jumbo Rolls → Production → Softroll.
 *
 * Rebuilt from the legacy `softroll_prod.php` screen (js/bpl/softwave_prod/*,
 * Bil\Bpl\softroll_prod), with two deliberate changes:
 *
 * 1. The operator picks a PRODUCT from `bpl_products_softroll` instead of
 *    re-typing grammage and diameter as free text. That free text is what gave
 *    the old data '17' / '17.0' / '240 ' / '3676' for the same physical roll.
 *    The two columns are still written, denormalised, because the flat PHP
 *    list, form and label print-out read them.
 * 2. The barcode's machine segment is `S{n}`, not `M{n}` — see
 *    Modules\Bpl\Support\RollBarcode for the collision that forced it.
 *
 * `brightness` stays per-roll: it is measured off the reel, not a property of
 * the product.
 */
#[Title('BPL Softroll Production')]
class Softroll extends DataGrid
{
    /** Same 12-month listing window as the Hardroll tab — see that constant. */
    public const MONTHS_LISTED = 12;

    public string $dateofmanufacture = '';
    public ?int $product_id = null;
    public string $papermachine = '';
    public string $brightness = '';
    public string $weight = '';

    public function pageKey(): string { return 'bpl.jumbo-rolls.production.softroll'; }
    public function pageLabel(): string { return 'BPL Softroll Production'; }
    public function pageSubtitle(): string
    {
        return 'Softrolls coming off the paper machines, with their barcodes and labels. The default view shows the last '
            . self::MONTHS_LISTED . ' months; On the floor shows everything still standing.';
    }

    public static function listedFrom(): string
    {
        return now()->subMonths(self::MONTHS_LISTED)->format('Y/m/d');
    }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.production-softroll'; }
    public function headerView(): ?string { return 'bpl::partials.production-tabs'; }
    public function defaultSort(): array { return ['id', 'desc']; }
    public function modalSize(): string { return '560px'; }

    public function mount(): void
    {
        parent::mount();
        $this->resetForm();
    }

    public function views(): array
    {
        $base = fn () => BplSoftrollProduction::query()
            ->leftJoin('bpl_grades as g', 'bpl_softroll_production.grade_id', '=', 'g.id')
            ->leftJoin('bpl_products_softroll as p', 'bpl_softroll_production.product_id', '=', 'p.id')
            ->select(
                'bpl_softroll_production.id',
                'bpl_softroll_production.dateofmanufacture',
                'bpl_softroll_production.papermachine',
                'bpl_softroll_production.softrollnumber',
                'bpl_softroll_production.barcode',
                'bpl_softroll_production.grammage',
                'bpl_softroll_production.diameter',
                'bpl_softroll_production.brightness',
                'bpl_softroll_production.weight',
                'bpl_softroll_production.status',
                'g.type as gradetype',
                'p.productname',
            );

        $unjoined = fn () => BplSoftrollProduction::query();

        $searchable = ['bpl_softroll_production.barcode', 'bpl_softroll_production.softrollnumber', 'p.productname', 'g.type'];
        $sortable = ['id', 'dateofmanufacture', 'papermachine', 'softrollnumber', 'barcode', 'weight', 'productname', 'gradetype'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Date', 'dateofmanufacture'],
                    ['Product', 'productname'],
                    ['Machine', 'papermachine', fn ($r) => 'PM' . e((string) $r->papermachine)],
                    ['Softroll No', 'softrollnumber'],
                    ['Barcode', 'barcode'],
                    ['Brightness', 'brightness'],
                    ['Weight (kg)', 'weight'],
                    ['Status', 'status', fn ($r) => $this->statusBadge($r)],
                ],
                'query' => fn () => $base()->where('bpl_softroll_production.dateofmanufacture', '>=', self::listedFrom()),
                'count' => fn () => $unjoined()->where('dateofmanufacture', '>=', self::listedFrom()),
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
            'on_floor' => [
                'label' => 'On the floor',
                'type' => 'table',
                'columns' => [
                    ['Date', 'dateofmanufacture'],
                    ['Product', 'productname'],
                    ['Machine', 'papermachine', fn ($r) => 'PM' . e((string) $r->papermachine)],
                    ['Barcode', 'barcode'],
                    ['Brightness', 'brightness'],
                    ['Weight (kg)', 'weight'],
                ],
                // Not windowed — see the Hardroll tab: 31 softrolls on the
                // floor predate the window, the oldest since 2024/02/11.
                'query' => fn () => $base()->whereNull('bpl_softroll_production.status'),
                'count' => fn () => $unjoined()->whereNull('status'),
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
        ];
    }

    private function statusBadge($row): string
    {
        $label = $row->status ?: 'On floor';
        $class = $row->status ? 'badge-muted' : 'badge-success';

        return '<span class="badge ' . $class . '">' . e($label) . '</span>';
    }

    /* ---------------- Options ---------------- */

    #[Computed]
    public function products()
    {
        return BplProductSoftroll::query()->select('id', 'productname')->orderBy('productname')->get();
    }

    /** The softroll table stores the machine NUMBER, not the 'PM2' name. */
    public function machines(): array { return ['2' => 'PM2', '3' => 'PM3']; }

    public function canBackdate(): bool
    {
        return $this->mayDo('backdate');
    }

    /* ---------------- Form ---------------- */

    protected function rules(): array
    {
        return [
            'dateofmanufacture' => ['required', 'date', 'before_or_equal:today'],
            'product_id' => ['required', 'integer', 'exists:bpl.bpl_products_softroll,id'],
            'papermachine' => ['required', 'in:2,3'],
            'brightness' => ['required', 'numeric', 'min:0'],
            'weight' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function resetForm(): void
    {
        $this->dateofmanufacture = now()->format('Y-m-d');
        $this->product_id = null;
        $this->papermachine = '';
        $this->brightness = '';
        $this->weight = '';
    }

    protected function fillForm(int $id): void
    {
        $r = BplSoftrollProduction::findOrFail($id);

        $this->dateofmanufacture = $this->toIso($r->dateofmanufacture);
        $this->product_id = $r->product_id;
        $this->papermachine = (string) $r->papermachine;
        $this->brightness = (string) $r->brightness;
        $this->weight = (string) $r->weight;
    }

    protected function findRow(int $id)
    {
        return BplSoftrollProduction::find($id);
    }

    public function deleteGuard($row): ?string
    {
        return $row->status
            ? 'This softroll has left the factory — it cannot be deleted here.'
            : null;
    }

    protected function performDelete(int $id): void
    {
        BplSoftrollProduction::whereKey($id)->delete();
    }

    /* ---------------- Save ---------------- */

    public function save(): void
    {
        $data = $this->validate();
        $product = BplProductSoftroll::findOrFail($data['product_id']);

        $row = [
            'product_id' => $product->id,
            'grade_id' => $product->grade_id,
            // Denormalised from the product so the legacy screens and the
            // label keep reading what they always read.
            'grammage' => (string) $product->grammage,
            'diameter' => (string) $product->diameter,
            'brightness' => (float) $data['brightness'],
            'weight' => (float) $data['weight'],
        ];

        if ($this->editingId) {
            $existing = BplSoftrollProduction::findOrFail($this->editingId);
            if ($existing->status) {
                session()->flash('err', 'This softroll has left the factory — it can no longer be edited here.');
                return;
            }

            $existing->update($row);
            $this->showModal = false;
            session()->flash('ok', 'Softroll ' . $existing->barcode . ' updated.');

            return;
        }

        $date = $this->legacyDate($data['dateofmanufacture']);

        $row['dateofmanufacture'] = $date;
        $row['papermachine'] = (int) $data['papermachine'];
        $row['username'] = auth()->user()?->username ?? auth()->user()?->name;
        $row['status'] = null;
        $row['barcode'] = RollBarcode::softroll($date, $data['papermachine']);
        $row['softrollnumber'] = RollBarcode::softrollNumber($date, $data['papermachine']);

        $created = BplSoftrollProduction::create($row);

        $this->showModal = false;
        session()->flash('ok', 'Softroll ' . $created->barcode . ' recorded.');
        session()->flash('bpl_label_url', route('bpl.jumbo-rolls.production.softroll.label', $created->id));
    }

    private function legacyDate(string $iso): string
    {
        $date = Carbon::parse($iso);
        if (! $this->canBackdate()) {
            $date = now();
        }

        return $date->format('Y/m/d');
    }

    private function toIso(?string $legacy): string
    {
        return $legacy ? Carbon::parse(str_replace('/', '-', $legacy))->format('Y-m-d') : now()->format('Y-m-d');
    }

    /* ---------------- Row actions ---------------- */

    public function hasLeadingRowActions(): bool
    {
        return true;
    }

    public function leadingRowActions($row): string
    {
        $url = route('bpl.jumbo-rolls.production.softroll.label', $row->id);

        return '<a href="' . e($url) . '" target="_blank" rel="noopener"'
            . ' class="btn btn-ghost btn-icon btn-sm" title="Reprint label">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>'
            . '<path d="M6 14h12v8H6z"/></svg></a>';
    }
}
