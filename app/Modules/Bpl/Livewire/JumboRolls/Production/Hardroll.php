<?php

namespace Modules\Bpl\Livewire\JumboRolls\Production;

use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplCustomer;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Bpl\Models\BplProduction;
use Modules\Bpl\Support\RollBarcode;
use Modules\Bpl\Support\WeightAllowance;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Jumbo Rolls → Production → Hardroll.
 *
 * Rebuilt from the legacy `bpl_production.php` screen (js/bpl/production/*,
 * Bil\Bpl\production). Recording a reel here mints its `hardrollnumber` and
 * its scannable `barcode`, then prints the label.
 *
 * The barcode's machine segment is `M{n}`; softrolls now print `S{n}`. See
 * Modules\Bpl\Support\RollBarcode for why that distinction had to exist.
 */
#[Title('BPL Hardroll Production')]
class Hardroll extends DataGrid
{
    /**
     * How far back the listing reaches. The table holds 279k reels going back
     * years; this screen is where today's production is entered and checked, so
     * it shows the last 12 months and the reports own the archive. Said out
     * loud in the subtitle — a window nobody mentions is a window that makes
     * people think rows are missing.
     */
    public const MONTHS_LISTED = 12;

    /** Cart letters offered on a new reel; feeds the hardroll number suffix. */
    private const CARTS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];

    private const COMMENTS = [
        'Loose winding at start',
        'With partial break',
        'Suspected ember inside',
    ];

    public string $dateofmanufacture = '';
    public ?int $customer_id = null;
    public ?int $product_id = null;
    public string $papermachine = '';
    /** Narrows the product picker; not stored — the product carries the grade. */
    public string $form_gradetype = '';
    public string $cart = 'A';
    public string $corediameter = '';
    public string $joints = '0';
    public string $weight = '';
    public bool $hold = false;
    public array $comments = [];

    public function pageKey(): string { return 'bpl.jumbo-rolls.production.hardroll'; }
    public function pageLabel(): string { return 'BPL Hardroll Production'; }
    public function pageSubtitle(): string
    {
        return 'Reels coming off PM2 and PM3, with their barcodes and labels. The default view shows the last '
            . self::MONTHS_LISTED . ' months; On the floor shows everything still standing.';
    }

    /**
     * `dateofmanufacture` is legacy 'Y/m/d' text. Zero-padded, so a string
     * comparison is a date comparison, and the index added by migration
     * 2026_09_09_120000 serves the range.
     */
    public static function listedFrom(): string
    {
        return now()->subMonths(self::MONTHS_LISTED)->format('Y/m/d');
    }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.production-hardroll'; }
    public function headerView(): ?string { return 'bpl::partials.production-tabs'; }
    public function defaultSort(): array { return ['id', 'desc']; }
    public function modalSize(): string { return '640px'; }

    public function mount(): void
    {
        parent::mount();
        $this->resetForm();
    }

    public function views(): array
    {
        $base = fn () => BplProduction::query()
            ->leftJoin('bpl_products_hardroll as p', 'bpl_production.product_id', '=', 'p.id')
            ->leftJoin('bpl_customers as c', 'bpl_production.customer_id', '=', 'c.id')
            ->select(
                'bpl_production.id',
                'bpl_production.dateofmanufacture',
                'bpl_production.papermachine',
                'bpl_production.hardrollnumber',
                'bpl_production.barcode',
                'bpl_production.weight',
                'bpl_production.joints',
                'bpl_production.hold',
                'bpl_production.status',
                'p.productname',
                'p.gradetype',
                'c.customername',
            );

        // The same rows without the display joins, for the row count — see
        // DataGrid::paginateView(). The joins are eq_ref probes the count never
        // reads, and at 27k rows they were most of the render.
        $unjoined = fn () => BplProduction::query();

        $searchable = ['bpl_production.barcode', 'bpl_production.hardrollnumber', 'p.productname', 'c.customername'];
        $sortable = ['id', 'dateofmanufacture', 'papermachine', 'hardrollnumber', 'barcode', 'weight', 'productname', 'customername'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Date', 'dateofmanufacture'],
                    ['Customer', 'customername'],
                    ['Product', 'productname'],
                    ['Machine', 'papermachine'],
                    ['Hardroll No', 'hardrollnumber'],
                    ['Barcode', 'barcode'],
                    ['Weight (kg)', 'weight'],
                    ['Status', 'status', fn ($r) => $this->statusBadge($r)],
                ],
                // Windowed: this is the day-to-day listing, and the archive
                // belongs to the reports.
                'query' => fn () => $base()->where('bpl_production.dateofmanufacture', '>=', self::listedFrom()),
                'count' => fn () => $unjoined()->where('dateofmanufacture', '>=', self::listedFrom()),
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
            'on_floor' => [
                'label' => 'On the floor',
                'type' => 'table',
                'columns' => [
                    ['Date', 'dateofmanufacture'],
                    ['Customer', 'customername'],
                    ['Product', 'productname'],
                    ['Machine', 'papermachine'],
                    ['Barcode', 'barcode'],
                    ['Weight (kg)', 'weight'],
                    ['Joints', 'joints'],
                ],
                // Deliberately NOT windowed. This is a stock position, not a
                // listing — 24 reels on the floor are older than the window
                // (one since 2022/02/15), and a reel standing that long is the
                // single most interesting row on the page. Hiding it would be
                // the opposite of what the view is for.
                'query' => fn () => $base()->whereNull('bpl_production.status'),
                'count' => fn () => $unjoined()->whereNull('status'),
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
        ];
    }

    /** NULL means still on the factory floor; 'Exited' means Factory Exit has it. */
    private function statusBadge($row): string
    {
        $held = $row->hold === 'hold';
        $label = $row->status ?: 'On floor';
        $class = $row->status ? 'badge-muted' : 'badge-success';

        return '<span class="badge ' . $class . '">' . e($label) . '</span>'
            . ($held ? ' <span class="badge badge-warning">Hold</span>' : '');
    }

    /* ---------------- Options ---------------- */

    #[Computed]
    public function customers()
    {
        return BplCustomer::query()->select('id', 'customername')->orderBy('customername')->get();
    }

    /**
     * Grade types, for the first step of the product picker.
     *
     * DISTINCT in SQL rather than plucking 4,387 rows and uniquing them in PHP.
     */
    #[Computed]
    public function gradeTypes()
    {
        return BplProductHardroll::query()
            ->whereNotNull('gradetype')->where('gradetype', '<>', '')
            ->distinct()->orderBy('gradetype')->pluck('gradetype');
    }

    /**
     * Products in the chosen grade.
     *
     * The catalog holds 4,387 hardroll products and the searchable-select
     * partial snapshots its whole option list as JSON, so offering all of them
     * put 405 KB into every render of the page. Picking the grade first is both
     * the fix and the way the floor already thinks about a reel — the largest
     * grade has 917 products and the average 142.
     *
     * Nothing is chosen until a grade is: an empty list is honest about needing
     * that first, where a full one would just be slow again.
     */
    #[Computed]
    public function products()
    {
        if ($this->form_gradetype === '') {
            return collect();
        }

        return BplProductHardroll::query()
            ->where('gradetype', $this->form_gradetype)
            ->select('id', 'productname')->orderBy('productname')->get();
    }

    /** Changing the grade invalidates the product chosen under the old one. */
    public function updatedFormGradetype(): void
    {
        $this->product_id = null;
        unset($this->products);
    }

    public function carts(): array { return self::CARTS; }
    public function commentOptions(): array { return self::COMMENTS; }
    public function machines(): array { return ['PM2', 'PM3']; }

    /** Without `backdate` the date is fixed to today, whatever the form posts. */
    public function canBackdate(): bool
    {
        return $this->mayDo('backdate');
    }

    /* ---------------- Form ---------------- */

    protected function rules(): array
    {
        return [
            'dateofmanufacture' => ['required', 'date', 'before_or_equal:today'],
            'customer_id' => ['required', 'integer', 'exists:bpl.bpl_customers,id'],
            'form_gradetype' => ['required', 'string'],
            'product_id' => ['required', 'integer', 'exists:bpl.bpl_products_hardroll,id'],
            'papermachine' => ['required', 'in:PM2,PM3'],
            'cart' => ['required', 'in:' . implode(',', self::CARTS)],
            'corediameter' => ['required', 'numeric', 'min:0'],
            'joints' => ['required', 'integer', 'min:0'],
            'weight' => ['required', 'numeric', 'min:0'],
            'comments' => ['array'],
            'comments.*' => ['in:' . implode(',', self::COMMENTS)],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['form_gradetype' => 'grade type', 'product_id' => 'product', 'customer_id' => 'customer'];
    }

    protected function resetForm(): void
    {
        $this->dateofmanufacture = now()->format('Y-m-d');
        $this->customer_id = null;
        $this->product_id = null;
        $this->papermachine = '';
        $this->form_gradetype = '';
        $this->cart = 'A';
        $this->corediameter = '';
        $this->joints = '0';
        $this->weight = '';
        $this->hold = false;
        $this->comments = [];
    }

    protected function fillForm(int $id): void
    {
        $r = BplProduction::findOrFail($id);

        $this->dateofmanufacture = $this->toIso($r->dateofmanufacture);
        $this->customer_id = $r->customer_id;
        $this->product_id = $r->product_id;
        // Seed the grade from the reel's own product so the picker opens on the
        // right list and the current choice is actually in it.
        $this->form_gradetype = (string) BplProductHardroll::whereKey($r->product_id)->value('gradetype');
        $this->papermachine = (string) $r->papermachine;
        $this->cart = 'A';
        $this->corediameter = (string) $r->corediameter;
        $this->joints = (string) $r->joints;
        $this->weight = (string) $r->weight;
        $this->hold = $r->hold === 'hold';
        $this->comments = array_values(array_filter(explode(',', (string) $r->comments)));
    }

    protected function findRow(int $id)
    {
        return BplProduction::find($id);
    }

    /**
     * A reel that has left the factory is owned by the movement tables from
     * then on — Factory Exit set `status`, and deleting the production row
     * would orphan its exit, its BIL entrance and everything downstream.
     */
    public function deleteGuard($row): ?string
    {
        return $row->status
            ? 'This reel has left the factory — it cannot be deleted here.'
            : null;
    }

    protected function performDelete(int $id): void
    {
        BplProduction::whereKey($id)->delete();
    }

    /* ---------------- Save ---------------- */

    public function save(): void
    {
        $data = $this->validate();
        $product = BplProductHardroll::findOrFail($data['product_id']);

        $date = $this->legacyDate($data['dateofmanufacture']);
        $gross = (float) $data['weight'];
        $deduction = $this->weightDeduction((int) $data['customer_id'], $product);

        $row = [
            'customer_id' => (int) $data['customer_id'],
            'product_id' => (int) $data['product_id'],
            'corediameter' => (float) $data['corediameter'],
            'joints' => (int) $data['joints'],
            'hold' => $this->hold ? 'hold' : null,
            'comments' => implode(',', $data['comments'] ?? []),
            // Brightness is a property of the product, not of the reel — the
            // legacy form copied it across on every save and reports read it
            // from the production row.
            'brightness' => (float) $product->brightness,
        ];

        if ($this->editingId) {
            $existing = BplProduction::findOrFail($this->editingId);
            if ($existing->status) {
                session()->flash('err', 'This reel has left the factory — it can no longer be edited here.');
                return;
            }

            // The stored weight is already net of any allowance. Re-deducting
            // on every save would shave it again, so the allowance is only
            // re-derived when the inputs to the rule (customer, product) or the
            // weight itself have moved: gross = stored weight + stored
            // allowance, then the current rule is applied to that.
            $storedGross = (float) $existing->weight + (float) $existing->net_weight;
            $newGross = abs($gross - (float) $existing->weight) < 0.001 ? $storedGross : $gross;

            $row['weight'] = $newGross - $deduction;
            $row['net_weight'] = $deduction;
            $row['paperweight'] = $this->paperweight((float) $data['corediameter'], $product, $row['weight']);

            $existing->update($row);
            $this->showModal = false;
            session()->flash('ok', 'Reel ' . $existing->barcode . ' updated.');

            return;
        }

        $row['dateofmanufacture'] = $date;
        $row['papermachine'] = $data['papermachine'];
        $row['username'] = auth()->user()?->username ?? auth()->user()?->name;
        $row['status'] = null;
        $row['weight'] = $gross - $deduction;
        $row['net_weight'] = $deduction;
        $row['paperweight'] = $this->paperweight((float) $data['corediameter'], $product, $row['weight']);
        $row['barcode'] = RollBarcode::hardroll($date, $data['papermachine']);
        $row['hardrollnumber'] = RollBarcode::hardrollNumber($date, $data['papermachine'], $data['cart']);

        $created = BplProduction::create($row);

        $this->showModal = false;
        // The label is the point of the screen — hand it straight over rather
        // than making the operator find the row again.
        session()->flash('ok', 'Reel ' . $created->barcode . ' recorded.');
        session()->flash('bpl_label_url', route('bpl.jumbo-rolls.production.hardroll.label', $created->id));
    }

    /**
     * Core/wrapper allowance for this reel, by customer, grade type and ply.
     *
     * Maintained on BPL → Jumbo Rolls → Weight Allowances, not hard-coded: the
     * live data shows combinations the legacy `merge()` never encoded, so the
     * rule has to be correctable without a deploy — and auditable.
     */
    private function weightDeduction(int $customerId, BplProductHardroll $product): float
    {
        return WeightAllowance::for($customerId, $product->gradetype, (int) $product->ply);
    }

    /**
     * Density of the wound paper, kg/m³ — the legacy
     * Bil\Bpl\production::paperweight() formula, unchanged: the reel is an
     * annulus of outer diameter `product.diameter` (cm) and bore
     * `corediameter` (mm), `product.width` cm wide.
     */
    private function paperweight(float $coreDiameter, BplProductHardroll $product, float $weight): float
    {
        $diameter = (float) $product->diameter / 100;
        $core = $coreDiameter / 1000;
        $width = (float) $product->width / 100;

        $denominator = (pow($diameter, 2) - pow($core, 2)) * 3.1412 * ($width / 4);

        return $denominator == 0.0 ? 0.0 : round($weight / $denominator, 2);
    }

    /** Legacy rows store 'Y/m/d' text. */
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
        $url = route('bpl.jumbo-rolls.production.hardroll.label', $row->id);

        return '<a href="' . e($url) . '" target="_blank" rel="noopener"'
            . ' class="btn btn-ghost btn-icon btn-sm" title="Reprint label">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>'
            . '<path d="M6 14h12v8H6z"/></svg></a>';
    }
}
