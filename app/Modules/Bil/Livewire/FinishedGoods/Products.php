<?php

namespace Modules\Bil\Livewire\FinishedGoods;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;
use Modules\Bil\Models\FinishedGoodsProduct;
use Modules\Bil\Support\GradeType;
use Modules\Bil\Support\QcProductImages;
use Modules\Bil\Support\QcRevisionArchive;
use Modules\Bpl\Models\BplGrade;
use Modules\Core\Livewire\DataGrid;

/**
 * Finished Goods → Products. Rebuilt from the legacy Quality Control dashboard
 * (quality_control.php + js/quality_control.js + Bil\Product\Quality): the
 * finished-goods product master, where each product carries a full QC
 * specification sheet and a revision number.
 *
 * Revisions: saving an edit archives the spec as it stood into `qc_revision`
 * and bumps `revnumber`, so the products row is always the current revision and
 * the spec sheet can page back through every earlier one.
 */
#[Title('Finished Goods Products')]
class Products extends DataGrid
{
    use WithFileUploads;

    /** Product-group vocabulary, from the legacy dashboard. */
    public const GROUPS = [
        'Aluminium Foil', 'Diapers', 'Facial', 'Medical Roll', 'Napkin', 'Special Order',
        'Toilet', 'Toilet Jumbo', 'Towel', 'Unwrapped', 'Waste Bag',
    ];

    public const LAM_EDGE = ['N/A', 'Edge', 'Lam', 'Plain Lam', 'Coloured Lam'];

    /** Spec fields stored as text. */
    protected const TEXT = [
        'productname', 'productcode', 'productbundles', 'productgroup', 'basepaper', 'mach',
        'embossing', 'lamedge', 'hardrollsource', 'hardrollgsm', 'waste', 'bundlespertonne',
    ];

    /** Spec fields stored as numbers; a blank means 0, as in the legacy form. */
    protected const NUMERIC = [
        'productrolls', 'productpacks', 'gsm', 'logweight', 'sheetlength', 'clipweight',
        'actualrollweight', 'rolllength', 'coreweight', 'corediameter', 'diameter', 'perimeter',
        'pulls', 'netweight', 'ply', 'hardrollwidth', 'rollsperbundle', 'wrapperweight',
        'polybagweight', 'polybundleweight', 'sheetcounts', 'grossweight',
    ];

    /** Of those, the ones the table stores as integers. */
    protected const INTEGER = ['productrolls', 'productpacks', 'ply', 'rollsperbundle', 'sheetcounts'];

    /** Fields the form works out for you (the legacy form did this in JS). */
    protected const DERIVED = [
        'rollsperbundle', 'actualrollweight', 'netweight', 'grossweight', 'bundlespertonne', 'sheetcounts',
    ];

    /** The sheet-width triple, stored joined as "min:mid:max". */
    protected const SHEET_WIDTH = ['sheetwidthmin', 'sheetwidthmid', 'sheetwidthmax'];

    public array $form = [];

    /** One BPL grade type per ply, plus which plies share a hardroll. */
    public array $gradeTypes = ['', '', ''];
    public string $gradeGrouping = 'none';

    /** Pending product photo upload, and the filename already on the product. */
    public $image = null;
    public string $currentImage = '';

    /* ---------------- Spec sheet (read-only, with revision history) --------- */

    public bool $specsOpen = false;
    public ?int $specsId = null;

    /**
     * Which revision the sheet is showing, as a position in specsHistory. It is
     * an index rather than a revision number because revision numbers are not
     * reliably unique in the legacy archive (one product has two "rev 17").
     */
    public ?int $specsIndex = null;

    /* ---------------- Grid ---------------- */

    public function pageKey(): string { return 'bil.finished-goods.products'; }
    public function pageLabel(): string { return 'Finished Goods Products'; }
    public function pageSubtitle(): string { return 'Finished-goods product master — full QC specification, revision-controlled.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bil::livewire.forms.finished-goods-product'; }
    public function extraView(): ?string { return 'bil::partials.fg-product-specs'; }
    public function defaultSort(): array { return ['productname', 'asc']; }
    public function modalSize(): string { return '1080px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Finished Goods Product List',
                'type' => 'table',
                'columns' => [
                    // The code opens the read-only spec sheet; it still exports
                    // as plain text because the column has a real field.
                    ['Product Code', 'productcode', fn ($r) => $this->specsLink($r)],
                    ['Product Name', 'productname'],
                    ['Product Group', 'productgroup'],
                    ['Ply', 'ply'],
                    ['Machine', 'mach'],
                    ['Hardroll Grade Type', 'basepaper'],
                    ['Revision', 'revnumber'],
                ],
                'query' => fn () => FinishedGoodsProduct::query()->active()
                    ->select('products.*', 'products.productid as id'),
                'searchable' => ['productcode', 'productname', 'productgroup', 'mach', 'basepaper', 'embossing'],
                'sortable' => ['productcode', 'productname', 'productgroup', 'ply', 'mach', 'basepaper', 'revnumber'],
            ],
            'by_group' => [
                'label' => 'Summary (by product group)',
                'type' => 'summary',
                'columns' => [
                    ['Product Group', 'productgroup'],
                    ['Products', 'total'],
                ],
                'query' => fn () => FinishedGoodsProduct::query()->active()
                    ->selectRaw("COALESCE(NULLIF(products.productgroup, ''), '—') as productgroup, COUNT(*) as total")
                    ->groupBy('products.productgroup')
                    ->orderByRaw('COUNT(*) DESC'),
            ],
            'by_machine' => [
                'label' => 'Summary (by machine)',
                'type' => 'summary',
                'columns' => [
                    ['Machine', 'mach'],
                    ['Products', 'total'],
                ],
                'query' => fn () => FinishedGoodsProduct::query()->active()
                    ->selectRaw("COALESCE(NULLIF(products.mach, ''), '—') as mach, COUNT(*) as total")
                    ->groupBy('products.mach')
                    ->orderByRaw('COUNT(*) DESC'),
            ],
            'trash' => [
                'label' => 'Trash',
                'type' => 'table',
                'columns' => [
                    ['Product Code', 'productcode'],
                    ['Product Name', 'productname'],
                    ['Product Group', 'productgroup'],
                    ['Revision', 'revnumber'],
                    // Null field = a control, not a value: kept out of exports.
                    ['Actions', null, fn ($r) => $this->restoreButton($r)],
                ],
                'query' => fn () => FinishedGoodsProduct::query()->trashed()
                    ->select('products.*', 'products.productid as id'),
                'searchable' => ['productcode', 'productname', 'productgroup'],
                'sortable' => ['productcode', 'productname', 'productgroup', 'revnumber'],
            ],
        ];
    }

    /**
     * In the Trash view a row's only action is Restore — a trashed product is
     * not editable, and deleting it again is meaningless.
     */
    public function mayDo(string $ability): bool
    {
        if ($this->view === 'trash' && in_array($ability, ['create', 'edit', 'delete'], true)) {
            return false;
        }

        return parent::mayDo($ability);
    }

    protected function specsLink($row): string
    {
        return '<button type="button" wire:click="showSpecs(' . (int) $row->productid . ')"'
            . ' title="View full specification"'
            . ' style="background:none;border:0;padding:0;font:inherit;color:var(--brand);text-decoration:underline;cursor:pointer;">'
            . e((string) $row->productcode) . '</button>';
    }

    protected function restoreButton($row): string
    {
        // mayDo() hides edit/delete in this view, so ask the base check.
        if (! parent::mayDo('delete')) {
            return '<span class="text-muted">—</span>';
        }

        return '<button type="button" class="btn btn-ghost btn-sm" wire:click="restore(' . (int) $row->productid . ')">Restore</button>';
    }

    /* ---------------- Lookups ---------------- */

    /** BPL grades, the vocabulary for a product's per-ply hardroll grade type. */
    #[Computed]
    public function grades()
    {
        return BplGrade::query()->orderBy('gradename')->get()
            ->map(fn ($g) => ['type' => $g->type, 'label' => $g->type . ' — ' . $g->gradename]);
    }

    /**
     * grade type => grade (Economy/Premium/…), used to show the product grade
     * on the spec sheet. Includes retired grades so historic specs still read.
     */
    #[Computed]
    public function gradeMap(): array
    {
        return BplGrade::withTrashed()->pluck('grade', 'type')->all();
    }

    /** The machine names already in use on finished-goods products. */
    #[Computed]
    public function machines(): array
    {
        return FinishedGoodsProduct::query()
            ->whereNotNull('mach')->where('mach', '<>', '')
            ->distinct()->orderBy('mach')->pluck('mach')->all();
    }

    /** The hardroll sources already in use (BPL, Imported, …). */
    #[Computed]
    public function hardrollSources(): array
    {
        return FinishedGoodsProduct::query()
            ->whereNotNull('hardrollsource')->where('hardrollsource', '<>', '')
            ->distinct()->orderBy('hardrollsource')->pluck('hardrollsource')->all();
    }

    /** The human grade names behind a basepaper string, e.g. "Premium-Premium". */
    public function gradeNameFor(?string $basepaper): string
    {
        $types = GradeType::types($basepaper);
        if ($types === []) {
            return '';
        }

        $map = $this->gradeMap;

        return implode('-', array_map(fn ($t) => $map[$t] ?? $t, $types));
    }

    /* ---------------- Form ---------------- */

    protected function resetForm(): void
    {
        $this->form = array_fill_keys([...self::TEXT, ...self::NUMERIC, ...self::SHEET_WIDTH], '');
        $this->form['ply'] = '0';
        $this->form['lamedge'] = 'N/A';
        $this->gradeTypes = ['', '', ''];
        $this->gradeGrouping = 'none';
        $this->image = null;
        $this->currentImage = '';
    }

    protected function fillForm(int $id): void
    {
        $product = FinishedGoodsProduct::findOrFail($id);
        $this->resetForm();

        foreach ([...self::TEXT, ...self::NUMERIC] as $field) {
            $this->form[$field] = (string) ($product->{$field} ?? '');
        }

        $parts = array_pad(explode(':', (string) $product->sheetwidth), 3, '0');
        foreach (self::SHEET_WIDTH as $i => $field) {
            $this->form[$field] = $parts[$i] === '0' ? '' : $parts[$i];
        }

        $grade = GradeType::parse($product->basepaper);
        $this->gradeTypes = array_slice(array_pad($grade['types'], 3, ''), 0, 3);
        $this->gradeGrouping = $grade['grouping'];

        $this->currentImage = (string) $product->imagepath;
    }

    /** The picture already on the product being edited, for the form preview. */
    #[Computed]
    public function currentImageUri(): ?string
    {
        return QcProductImages::dataUri($this->currentImage);
    }

    protected function findRow(int $id)
    {
        return FinishedGoodsProduct::find($id);
    }

    protected function rules(): array
    {
        $rules = [
            'form.productcode' => [
                'required', 'string', 'max:255',
                Rule::unique('bil.products', 'productcode')->ignore($this->editingId, 'productid'),
            ],
            'form.productname' => [
                'required', 'string', 'max:255',
                Rule::unique('bil.products', 'productname')->ignore($this->editingId, 'productid'),
            ],
            'form.productgroup' => ['required', 'string', 'max:50'],
            'form.ply' => ['required', 'integer', 'min:0', 'max:3'],
            'form.lamedge' => ['nullable', 'string', 'max:50'],
            'form.mach' => ['nullable', 'string', 'max:255'],
            'form.embossing' => ['nullable', 'string', 'max:255'],
            'form.basepaper' => ['nullable', 'string', 'max:255'],
            'form.hardrollsource' => ['nullable', 'string', 'max:50'],
            // Narrow legacy columns — validate to the width so a save can't be
            // rejected by strict mode with a raw SQL error.
            'form.hardrollgsm' => ['nullable', 'string', 'max:5'],
            'form.waste' => ['nullable', 'numeric', 'min:0', 'max:99', 'decimal:0,1'],
            'form.productbundles' => ['nullable', 'numeric', 'min:0'],
            'form.bundlespertonne' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'max:2048'],
        ];

        foreach (self::TEXT as $field) {
            $rules['form.' . $field] ??= ['nullable', 'string', 'max:255'];
        }

        foreach ([...self::NUMERIC, ...self::SHEET_WIDTH] as $field) {
            $rules['form.' . $field] ??= ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        $labels = [
            'form.productcode' => 'product code',
            'form.productname' => 'product name',
            'form.productgroup' => 'product group',
            'form.ply' => 'number of ply',
            'form.mach' => 'production machine',
            'form.basepaper' => 'hardroll grade type',
            'form.hardrollgsm' => 'hardroll GSM',
            'form.waste' => 'production waste',
            'image' => 'product picture',
        ];

        foreach ([...self::TEXT, ...self::NUMERIC, ...self::SHEET_WIDTH] as $field) {
            $labels['form.' . $field] ??= str_replace('_', ' ', $field);
        }

        return $labels;
    }

    /**
     * Livewire's generic update hook: keep the worked-out figures and the
     * composed grade type in step as the user fills the form in.
     */
    public function updated(string $name, $value = null): void
    {
        if (str_starts_with($name, 'form.')) {
            $field = substr($name, strlen('form.'));

            // Editing a derived field by hand shouldn't immediately re-derive
            // it from its (possibly still blank) inputs.
            if (! in_array($field, self::DERIVED, true)) {
                $this->recompute();
            }

            if ($field === 'ply') {
                $this->syncGradeType();
            }

            return;
        }

        if ($name === 'gradeGrouping' || str_starts_with($name, 'gradeTypes')) {
            $this->syncGradeType();
        }
    }

    /**
     * Fill in the figures that follow from the others. Mirrors the legacy JS,
     * which only wrote a result when it came out positive — so a half-filled
     * form never wipes a figure that was entered by hand.
     */
    protected function recompute(): void
    {
        $n = fn (string $key) => (float) ($this->form[$key] ?? 0);
        $set = function (string $key, float $value) {
            if ($value > 0) {
                $this->form[$key] = $this->num($value);
            }
        };

        $set('rollsperbundle', $n('productrolls') * $n('productpacks'));
        $set('actualrollweight', $n('clipweight') - $n('coreweight'));
        $set('netweight', $n('actualrollweight') * $n('rollsperbundle'));
        $set('grossweight',
            $n('netweight')
            + $n('wrapperweight') * $n('rollsperbundle')
            + $n('productpacks') * $n('polybagweight')
            + $n('polybundleweight')
        );
        $set('sheetcounts', $n('pulls') * $n('ply'));

        // A tonne's worth of bundles, allowing for production waste.
        $perBundle = $n('netweight') * (1 + $n('waste') / 100);
        if ($perBundle > 0) {
            $this->form['bundlespertonne'] = (string) round(1000000 / $perBundle);
        }
    }

    /** Format a computed float tidily (no trailing zeros). */
    protected function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /** Keep basepaper in step with the per-ply grade pickers. */
    protected function syncGradeType(): void
    {
        $plies = (int) ($this->form['ply'] ?? 0);

        if ($plies < 1) {
            // No ply count means no hardroll spec (legacy dropped the field).
            $this->form['basepaper'] = '';
            return;
        }

        $this->form['basepaper'] = GradeType::compose(
            array_slice($this->gradeTypes, 0, $plies),
            $this->gradeGrouping
        );
    }

    /* ---------------- Save / delete / restore ---------------- */

    public function save(): void
    {
        $validated = $this->validate();
        $spec = $this->specPayload($validated['form']);
        $isNew = $this->editingId === null;

        // Move the upload before the transaction: a rolled-back save should not
        // leave a stray file, and a failed file write should not lose the spec.
        $picture = $this->image ? QcProductImages::store($this->image) : null;

        try {
            DB::connection('bil')->transaction(function () use ($spec, $isNew, $picture) {
                if ($isNew) {
                    $spec['revnumber'] = 0;
                    $spec['imagepath'] = $picture ?? '';
                    FinishedGoodsProduct::create($spec);
                    return;
                }

                $product = FinishedGoodsProduct::query()->lockForUpdate()->findOrFail($this->editingId);

                // Archive the spec exactly as stored, not as the client last saw
                // it, then move the product on to its next revision.
                QcRevisionArchive::archive((int) $product->productid, $product->getAttributes());

                $spec['revnumber'] = (int) $product->revnumber + 1;
                $spec['imagepath'] = $picture ?? (string) $product->imagepath;
                $product->update($spec);
            });
        } catch (QueryException $e) {
            // Product code and name are both UNIQUE. The Rule::unique checks
            // above catch this normally; this covers a concurrent insert.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $this->addError('form.productcode', 'That product code or name is already taken.');
                return;
            }
            throw $e;
        }

        $this->image = null;
        $this->showModal = false;
        session()->flash('ok', $isNew ? 'Product added.' : 'Product updated — previous revision archived.');
    }

    /** Map the validated form onto the products table's columns. */
    protected function specPayload(array $form): array
    {
        $spec = [];

        foreach (self::TEXT as $field) {
            $spec[$field] = trim((string) ($form[$field] ?? ''));
        }

        foreach (self::NUMERIC as $field) {
            $value = (float) ($form[$field] ?? 0);
            $spec[$field] = in_array($field, self::INTEGER, true) ? (int) $value : $value;
        }

        // Stored as one "min:mid:max" string; blanks become 0, as in legacy.
        $spec['sheetwidth'] = implode(':', array_map(
            fn ($field) => (string) round((float) ($form[$field] ?? 0), 2),
            self::SHEET_WIDTH
        ));

        $spec['revdate'] = now()->format('Y/m/d');
        $spec['timestamp'] = now()->getTimestamp();
        $spec['is_deleted'] = 0;

        return $spec;
    }

    /** Delete is soft — the product moves to the Trash view. */
    protected function performDelete(int $id): void
    {
        FinishedGoodsProduct::whereKey($id)->update(['is_deleted' => 1]);
    }

    public function restore(int $id): void
    {
        // Restoring undoes a delete, so it takes the same right (mayDo() hides
        // delete in this view, so ask the base check directly).
        if (! parent::mayDo('delete')) {
            return;
        }

        FinishedGoodsProduct::whereKey($id)->update(['is_deleted' => 0]);
        session()->flash('ok', 'Product restored.');
    }

    /* ---------------- Spec sheet ---------------- */

    public function showSpecs(int $id): void
    {
        if (! FinishedGoodsProduct::whereKey($id)->exists()) {
            return;
        }

        $this->specsId = $id;
        unset($this->specsHistory, $this->specsRow, $this->specsImage);

        // Open on the current revision, which sorts last.
        $this->specsIndex = max(count($this->specsHistory) - 1, 0);
        $this->specsOpen = true;
    }

    public function closeSpecs(): void
    {
        $this->specsOpen = false;
        $this->specsId = null;
        $this->specsIndex = null;
    }

    /** Every revision of the open product, oldest first. */
    #[Computed]
    public function specsHistory(): array
    {
        if (! $this->specsId) {
            return [];
        }

        $product = FinishedGoodsProduct::find($this->specsId);
        if (! $product) {
            return [];
        }

        return QcRevisionArchive::history($this->specsId, $product->getAttributes());
    }

    /** The revision currently selected in the spec sheet. */
    #[Computed]
    public function specsRow(): ?array
    {
        $history = $this->specsHistory;
        if ($history === []) {
            return null;
        }

        return $history[$this->specsIndex] ?? $history[count($history) - 1];
    }

    /** The picture for the selected revision, as an inline data: URI. */
    #[Computed]
    public function specsImage(): ?string
    {
        return QcProductImages::dataUri($this->specsRow['imagepath'] ?? null);
    }
}
