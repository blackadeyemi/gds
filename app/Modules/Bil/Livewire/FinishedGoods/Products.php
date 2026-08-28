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
use Modules\Bil\Models\FinishedGoodsProductMachine;
use Modules\Bpl\Models\BplGrade;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Company;
use Modules\Core\Models\Factory;
use Modules\Core\Models\MachineLine;
use Modules\Core\Models\MachineProject;

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

    /** No "N/A" member: not applicable is an empty choice, stored as NULL. */
    public const LAM_EDGE = ['Edge', 'Lam', 'Plain Lam', 'Coloured Lam'];

    /**
     * Spec fields stored as text. `mach` and `hardrollsource` are absent: both
     * are summaries derived from structured references, not typed in.
     */
    protected const TEXT = [
        'productname', 'productcode', 'productbundles', 'productgroup', 'basepaper',
        'embossing', 'lamedge', 'hardrollgsm', 'waste', 'bundlespertonne',
    ];

    /**
     * Where the hardroll comes from: one of our companies (optionally narrowed
     * to a plant), or an outside mill named as free text.
     */
    protected const HARDROLL = ['hardroll_company_id', 'hardroll_factory_id', 'hardroll_source_text'];

    /** Sentinel for the "outside mill" choice in the source-company select. */
    public const HARDROLL_EXTERNAL = 'external';

    /** Columns that store "nothing" as NULL rather than an empty string. */
    protected const NULLABLE_TEXT = ['lamedge'];

    /** Upper bound on ply count — plies are added one at a time from the form. */
    public const MAX_PLIES = 12;

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

    /**
     * Machines the product is made on: one row per Factory → Line → Project
     * path. Several rows = several machines.
     */
    public array $machineRows = [];

    /**
     * Free-text `mach` carried over from the legacy app that matched no machine
     * in the hierarchy — shown so it isn't silently lost while unconverted.
     */
    public string $legacyMachine = '';


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
                    ['Product Code', 'productcode'],
                    ['Product Name', 'productname'],
                    ['Product Group', 'productgroup'],
                    ['Ply', 'ply'],
                    // The summary kept on the product; the spec sheet shows the
                    // full Factory → Line → Project path of each assignment.
                    ['Machine(s)', 'mach'],
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
                    ['Factory', 'factory'],
                    ['Machine', 'machine'],
                    ['Products', 'total'],
                ],
                // Counted from the assignments, so a product made on three
                // machines counts under each of them.
                'query' => function () {
                    $core = config('database.connections.core.database');

                    return FinishedGoodsProductMachine::query()
                        ->join('products as p', 'product_machines.product_id', '=', 'p.productid')
                        ->leftJoin("{$core}.machine_projects as mp", 'product_machines.project_id', '=', 'mp.id')
                        ->leftJoin("{$core}.machine_lines as ml", 'product_machines.line_id', '=', 'ml.id')
                        ->leftJoin("{$core}.factories as f", 'product_machines.factory_id', '=', 'f.id')
                        ->where('p.is_deleted', 0)
                        ->selectRaw("COALESCE(f.name, '—') as factory, COALESCE(mp.name, ml.name, '—') as machine, COUNT(DISTINCT p.productid) as total")
                        ->groupBy('factory', 'machine')
                        ->orderByRaw('COUNT(DISTINCT p.productid) DESC');
                },
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

    /**
     * The spec sheet opens from a row action rather than a link on the code,
     * so it sits with Edit and Delete. Not offered in Trash, which has its own
     * Restore column.
     */
    public function hasLeadingRowActions(): bool
    {
        return $this->view !== 'trash';
    }

    public function leadingRowActions($row): string
    {
        return '<button type="button" class="btn btn-ghost btn-icon btn-sm"'
            . ' wire:click="showSpecs(' . (int) $row->productid . ')" title="View full specification">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
            . '</svg></button>';
    }

    /** Single-product history check, for the server-side re-check on delete. */
    protected function historyOffPage(int $productid): array
    {
        $bil = DB::connection('bil');
        $bil = DB::connection('bil');
        $name = FinishedGoodsProduct::where('productid', $productid)->value('productname');

        $checks = [
            'conversion output' => fn () => $bil->table('factory_conversion')->where('productid', $productid)->exists(),
            'factory exits' => fn () => $bil->table('factory_exit')->where('productid', $productid)->exists(),
            'warehouse receipts' => fn () => $bil->table('finished_goods_warehouse_receipts')->where('productid', $productid)->exists(),
            'stock on hand' => fn () => $bil->table('finished_goods_warehouse_stock')->where('productid', $productid)->where('bundles', '<>', 0)->exists(),
            'sales orders' => fn () => $bil->table('sales_order_details')->where('productid', $productid)->exists(),
            'set up on a line' => fn () => $name && $bil->table('conversion_setup')->where('productname', $name)->exists(),
        ];

        $found = [];
        foreach ($checks as $label => $check) {
            if ($check()) {
                $found[] = $label;
            }
        }

        return $found;
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

    /* -- Machine hierarchy: Factory → Line → Project ------------------------ */

    /**
     * Factories a BIL product can be made in — this module's company only, so
     * the list never offers another company's plants (PM2/PM3 are Belpapyrus).
     */
    #[Computed]
    public function factories()
    {
        return Factory::query()->whereIn('id', $this->allowedFactoryIds())
            ->orderBy('name')->get()
            ->map(fn ($f) => ['id' => (string) $f->id, 'label' => $f->name]);
    }

    /** Ids of the factories this module may assign, by company code. */
    protected function allowedFactoryIds(): array
    {
        return $this->allowedFactoryIds ??= Factory::query()
            ->whereIn(
                'company_id',
                Company::query()->where('code', config('bil.company_code'))->pluck('id')
            )
            ->pluck('id')->all();
    }

    protected ?array $allowedFactoryIds = null;

    /**
     * Lines in a factory, parents and sub-lines together (sub-lines carry their
     * own factory_id), each parent immediately above its children.
     */
    public function linesFor($factoryId): array
    {
        $factoryId = (int) $factoryId;
        if (! $factoryId || ! in_array($factoryId, $this->allowedFactoryIds(), true)) {
            return [];
        }

        return $this->lineCache[$factoryId] ??= MachineLine::query()
            ->where('factory_id', $factoryId)->treeOrder()->get()
            ->map(fn ($l) => [
                'id' => (string) $l->id,
                // Indent sub-lines so the two levels read apart in the list.
                'label' => ($l->parent_id ? '— ' : '') . $l->name,
            ])->all();
    }

    /**
     * Projects on a line. Includes those hanging off its sub-lines, so picking
     * a parent line still offers everything under it.
     */
    public function projectsFor($lineId): array
    {
        $lineId = (int) $lineId;
        if (! $lineId) {
            return [];
        }

        return $this->projectCache[$lineId] ??= (function () use ($lineId) {
            $lineIds = MachineLine::query()
                ->where('id', $lineId)->orWhere('parent_id', $lineId)
                ->pluck('id')->all();

            return MachineProject::query()->whereIn('line_id', $lineIds)->treeOrder()->get()
                ->map(fn ($p) => [
                    'id' => (string) $p->id,
                    'label' => ($p->parent_id ? '— ' : '') . $p->name,
                ])->all();
        })();
    }

    /** Per-render memo so a form with several rows doesn't re-query per row. */
    protected array $lineCache = [];
    protected array $projectCache = [];

    public function addMachineRow(): void
    {
        $this->machineRows[] = ['factory_id' => '', 'line_id' => '', 'project_id' => ''];
    }

    public function removeMachineRow(int $index): void
    {
        unset($this->machineRows[$index]);
        $this->machineRows = array_values($this->machineRows);
    }

    /** Always show one row, so the form opens ready to fill in. */
    protected function ensureMachineRow(): void
    {
        if ($this->machineRows === []) {
            $this->addMachineRow();
        }
    }

    /**
     * Drop rows the user never filled in. The cascade means line and project
     * can't be set without a factory, so a factory-less row is simply blank —
     * an untouched row shouldn't block the save.
     */
    protected function pruneEmptyMachineRows(): void
    {
        $this->machineRows = array_values(array_filter(
            $this->machineRows,
            fn ($row) => trim((string) ($row['factory_id'] ?? '')) !== ''
        ));
    }

    /* -- Hardroll source: Company → Factory --------------------------------- */

    /**
     * Companies a hardroll can come from — every company EXCEPT this module's
     * own: BIL is where the hardroll is converted, never where it comes from.
     */
    #[Computed]
    public function companies()
    {
        return Company::query()
            ->where(fn ($q) => $q->where('code', '<>', config('bil.company_code'))->orWhereNull('code'))
            ->orderBy('name')->get()
            ->map(fn ($c) => ['id' => (string) $c->id, 'label' => $c->code ? "{$c->code} — {$c->name}" : $c->name]);
    }

    /** Whether the form currently names an outside mill. */
    public function hardrollIsExternal(): bool
    {
        return ($this->form['hardroll_company_id'] ?? '') === self::HARDROLL_EXTERNAL;
    }

    /** A company's plants, for narrowing the source (optional). */
    public function hardrollFactoriesFor($companyId): array
    {
        $companyId = (int) $companyId;
        if (! $companyId) {
            return [];
        }

        return $this->hardrollFactoryCache[$companyId] ??= Factory::query()
            ->where('company_id', $companyId)->orderBy('name')->get()
            ->map(fn ($f) => ['id' => (string) $f->id, 'label' => $f->name])->all();
    }

    protected array $hardrollFactoryCache = [];

    /**
     * The readable summary kept in `hardrollsource`: the company code, plus the
     * plant when one is named — "BPL", "BPL PM3".
     */
    protected function hardrollSummary(array $form): string
    {
        $company = Company::find($form['hardroll_company_id'] ?? null);
        if (! $company) {
            return '';
        }

        $factory = Factory::find($form['hardroll_factory_id'] ?? null);
        $name = $company->code ?: $company->name;

        return mb_substr($factory ? "{$name} {$factory->name}" : $name, 0, 50);
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
        $this->form = array_fill_keys([...self::TEXT, ...self::NUMERIC, ...self::SHEET_WIDTH, ...self::HARDROLL], '');
        $this->form['ply'] = '0';
        $this->form['lamedge'] = '';
        $this->gradeTypes = [];
        $this->gradeGrouping = 'none';
        $this->machineRows = [];
        $this->legacyMachine = '';
        $this->image = null;
        $this->currentImage = '';
        $this->ensureMachineRow();
    }

    protected function fillForm(int $id): void
    {
        $product = FinishedGoodsProduct::with('machines')->findOrFail($id);
        $this->resetForm();

        foreach ([...self::TEXT, ...self::NUMERIC, ...self::HARDROLL] as $field) {
            $this->form[$field] = (string) ($product->{$field} ?? '');
        }

        // No company id but text on record = an outside mill; show it in the
        // external branch so it can be edited rather than only preserved.
        if (! $product->hardroll_company_id && trim((string) $product->hardrollsource) !== '') {
            $this->form['hardroll_company_id'] = self::HARDROLL_EXTERNAL;
            $this->form['hardroll_source_text'] = (string) $product->hardrollsource;
        }

        $parts = array_pad(explode(':', (string) $product->sheetwidth), 3, '0');
        foreach (self::SHEET_WIDTH as $i => $field) {
            $this->form[$field] = $parts[$i] === '0' ? '' : $parts[$i];
        }

        $grade = GradeType::parse($product->basepaper);
        $plies = max((int) $product->ply, count($grade['types']));
        $this->gradeTypes = array_slice(array_pad($grade['types'], $plies, ''), 0, $plies);
        $this->gradeGrouping = $grade['grouping'];

        $this->machineRows = $product->machines->map(fn ($m) => [
            'factory_id' => (string) ($m->factory_id ?: ''),
            'line_id' => (string) ($m->line_id ?: ''),
            'project_id' => (string) ($m->project_id ?: ''),
        ])->all();

        // Only surface the legacy text when nothing structured replaced it yet.
        $this->legacyMachine = $product->machines->isEmpty() ? (string) $product->mach : '';

        $this->ensureMachineRow();

        $this->currentImage = (string) $product->imagepath;
    }

    /* -- Ply count ---------------------------------------------------------- */

    /** Add a ply (and its grade-type slot). Plies are added one at a time. */
    public function addPly(): void
    {
        $plies = (int) ($this->form['ply'] ?? 0);
        if ($plies >= self::MAX_PLIES) {
            return;
        }

        $this->form['ply'] = (string) ($plies + 1);
        $this->syncPlies();
    }

    /** Drop one ply, keeping the grade types of the plies around it. */
    public function removePly(int $index): void
    {
        unset($this->gradeTypes[$index]);
        $this->gradeTypes = array_values($this->gradeTypes);
        $this->form['ply'] = (string) count($this->gradeTypes);
        $this->syncPlies();
    }

    /** Keep the grade-type slots and the grouping consistent with the count. */
    protected function syncPlies(): void
    {
        $plies = max(0, min((int) ($this->form['ply'] ?? 0), self::MAX_PLIES));
        $this->form['ply'] = (string) $plies;

        $this->gradeTypes = array_slice(array_pad($this->gradeTypes, $plies, ''), 0, $plies);

        // Bracket groupings only mean anything up to three plies.
        if (! GradeType::groupable($plies)) {
            $this->gradeGrouping = 'none';
        }

        $this->syncGradeType();
        $this->recompute();
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
            'form.ply' => ['required', 'integer', 'min:0', 'max:' . self::MAX_PLIES],
            // Optional: a product with no lamination/edge finish stores NULL.
            'form.lamedge' => ['nullable', 'string', 'max:50', Rule::in(self::LAM_EDGE)],
            'form.embossing' => ['nullable', 'string', 'max:255'],
            // A machine row must at least name its factory; line and project
            // narrow it down and are optional.
            'machineRows' => ['array'],
            // Restricted to this module's company, not just any factory row —
            // the select is filtered, but the id still arrives from the client.
            'machineRows.*.factory_id' => ['required', 'integer', Rule::in($this->allowedFactoryIds())],
            'machineRows.*.line_id' => ['nullable', 'integer', 'exists:core.machine_lines,id'],
            'machineRows.*.project_id' => ['nullable', 'integer', 'exists:core.machine_projects,id'],
            'form.basepaper' => ['nullable', 'string', 'max:255'],
            // Either a company id or the "outside mill" sentinel.
            'form.hardroll_company_id' => [
                'nullable',
                Rule::in([self::HARDROLL_EXTERNAL, ...array_column($this->companies->all(), 'id')]),
            ],
            'form.hardroll_factory_id' => ['nullable', 'integer', 'exists:core.factories,id'],
            // Required only when an outside mill is chosen; 50 = the column width.
            'form.hardroll_source_text' => [
                Rule::requiredIf(fn () => $this->hardrollIsExternal()),
                'nullable', 'string', 'max:50',
            ],
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
            'form.basepaper' => 'hardroll grade type',
            'form.hardrollgsm' => 'hardroll GSM',
            'form.waste' => 'expected production waste',
            'form.lamedge' => 'lam / edge',
            'form.hardroll_company_id' => 'hardroll source company',
            'form.hardroll_factory_id' => 'hardroll source factory',
            'image' => 'product picture',
            'machineRows.*.factory_id' => 'factory',
            'machineRows.*.line_id' => 'line',
            'machineRows.*.project_id' => 'project',
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
                $this->syncPlies();
            }

            // Switching the source invalidates whichever branch is now unused.
            if ($field === 'hardroll_company_id') {
                $this->form['hardroll_factory_id'] = '';
                if (! $this->hardrollIsExternal()) {
                    $this->form['hardroll_source_text'] = '';
                }
            }

            return;
        }

        if ($name === 'gradeGrouping' || str_starts_with($name, 'gradeTypes')) {
            $this->syncGradeType();

            return;
        }

        // A machine row narrows Factory → Line → Project, so changing a level
        // clears the ones below it rather than leaving a mismatched path.
        if (preg_match('/^machineRows\.(\d+)\.(factory_id|line_id)$/', $name, $m)) {
            $index = (int) $m[1];

            if ($m[2] === 'factory_id') {
                $this->machineRows[$index]['line_id'] = '';
            }
            $this->machineRows[$index]['project_id'] = '';
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
        $this->pruneEmptyMachineRows();
        $validated = $this->validate();

        if (! $this->machinePathsAreConsistent()) {
            return;
        }
        $spec = $this->specPayload($validated['form']);
        $isNew = $this->editingId === null;

        // Move the upload before the transaction: a rolled-back save should not
        // leave a stray file, and a failed file write should not lose the spec.
        $picture = $this->image ? QcProductImages::store($this->image) : null;

        $rows = $this->machineRowsToSave();

        try {
            DB::connection('bil')->transaction(function () use ($spec, $isNew, $picture, $rows) {
                if ($isNew) {
                    $spec['revnumber'] = 0;
                    $spec['imagepath'] = $picture ?? '';
                    $spec['mach'] = $this->machSummary($rows);
                    $product = FinishedGoodsProduct::create($spec);
                    $this->saveMachines((int) $product->productid, $rows);
                    return;
                }

                $product = FinishedGoodsProduct::query()->lockForUpdate()->findOrFail($this->editingId);

                // Archive the spec exactly as stored, not as the client last saw
                // it, then move the product on to its next revision. Machines
                // ride along so an old revision still says what it ran on.
                QcRevisionArchive::archive(
                    (int) $product->productid,
                    $product->getAttributes() + ['machines' => $this->machineLabels((int) $product->productid)]
                );

                $spec['revnumber'] = (int) $product->revnumber + 1;
                $spec['imagepath'] = $picture ?? (string) $product->imagepath;
                // With no rows the legacy free-text machine is kept rather than
                // blanked — 19 products still carry one that matched no machine.
                $spec['mach'] = $rows === [] ? (string) $product->mach : $this->machSummary($rows);

                $product->update($spec);
                $this->saveMachines((int) $product->productid, $rows);
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

    /* -- Machine assignments ------------------------------------------------ */

    /**
     * Check each row is a real path down the hierarchy: the line belongs to the
     * chosen factory and the project to the chosen line. The cascade keeps this
     * true in normal use, so a mismatch means a stale or hand-crafted request —
     * refuse it rather than storing a path that doesn't exist.
     */
    protected function machinePathsAreConsistent(): bool
    {
        $ok = true;

        // Same rule for the hardroll source: the plant must be the company's.
        // An outside mill has no plant to check.
        $sourceFactory = (string) ($this->form['hardroll_factory_id'] ?? '');
        if (! $this->hardrollIsExternal() && $sourceFactory !== '' && ! in_array(
            $sourceFactory,
            array_column($this->hardrollFactoriesFor($this->form['hardroll_company_id'] ?? ''), 'id'),
            true
        )) {
            $this->addError('form.hardroll_factory_id', 'That factory does not belong to the selected company.');
            $ok = false;
        }

        foreach ($this->machineRows as $i => $row) {
            $line = (string) ($row['line_id'] ?? '');
            $project = (string) ($row['project_id'] ?? '');

            if ($line !== '' && ! in_array($line, array_column($this->linesFor($row['factory_id'] ?? ''), 'id'), true)) {
                $this->addError("machineRows.{$i}.line_id", 'That line is not in the selected factory.');
                $ok = false;
                continue;
            }

            if ($project !== '' && ! in_array($project, array_column($this->projectsFor($line), 'id'), true)) {
                $this->addError("machineRows.{$i}.project_id", 'That project is not on the selected line.');
                $ok = false;
            }
        }

        return $ok;
    }

    /** The form's machine rows, cleaned up and de-duplicated, ready to store. */
    protected function machineRowsToSave(): array
    {
        $rows = [];
        $seen = [];

        foreach ($this->machineRows as $row) {
            $factory = (int) ($row['factory_id'] ?? 0);
            if (! $factory) {
                continue;
            }

            $key = $factory . ':' . (int) ($row['line_id'] ?? 0) . ':' . (int) ($row['project_id'] ?? 0);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'factory_id' => $factory,
                'line_id' => ((int) ($row['line_id'] ?? 0)) ?: null,
                'project_id' => ((int) ($row['project_id'] ?? 0)) ?: null,
                'sort_order' => count($rows),
            ];
        }

        return $rows;
    }

    /** Replace a product's assignments with the given rows. */
    protected function saveMachines(int $productId, array $rows): void
    {
        FinishedGoodsProductMachine::where('product_id', $productId)->delete();

        foreach ($rows as $row) {
            FinishedGoodsProductMachine::create($row + ['product_id' => $productId]);
        }
    }

    /**
     * The readable summary kept in `products.mach` for the legacy QC screens:
     * the most specific machine of each assignment, comma separated and clipped
     * to the column width.
     */
    protected function machSummary(array $rows): string
    {
        $names = [];

        foreach ($rows as $row) {
            $name = $row['project_id']
                ? MachineProject::find($row['project_id'])?->name
                : ($row['line_id']
                    ? MachineLine::find($row['line_id'])?->name
                    : Factory::find($row['factory_id'])?->name);

            if ($name) {
                $names[] = $name;
            }
        }

        return mb_substr(implode(', ', array_unique($names)), 0, 255);
    }

    /** A product's stored assignments as display strings, for the archive. */
    protected function machineLabels(int $productId): array
    {
        return FinishedGoodsProductMachine::with(['factory', 'line', 'project'])
            ->where('product_id', $productId)
            ->orderBy('sort_order')->orderBy('id')
            ->get()->map(fn ($m) => $m->label())->all();
    }

    /** Map the validated form onto the products table's columns. */
    protected function specPayload(array $form): array
    {
        $spec = [];

        foreach (self::TEXT as $field) {
            $value = trim((string) ($form[$field] ?? ''));
            // Columns where "nothing" is NULL, not an empty string.
            $spec[$field] = ($value === '' && in_array($field, self::NULLABLE_TEXT, true))
                ? null
                : $value;
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

        // Hardroll source. One of our companies (ids are the record and
        // `hardrollsource` a generated summary), or an outside mill (no ids,
        // the typed name IS the record).
        $external = ($form['hardroll_company_id'] ?? '') === self::HARDROLL_EXTERNAL;
        $companyId = $external ? null : (((int) ($form['hardroll_company_id'] ?? 0)) ?: null);
        $factoryId = ((int) ($form['hardroll_factory_id'] ?? 0)) ?: null;

        $spec['hardroll_company_id'] = $companyId;
        // A plant without a company is meaningless; drop it rather than store it.
        $spec['hardroll_factory_id'] = $companyId ? $factoryId : null;
        $spec['hardrollsource'] = $external
            ? mb_substr(trim((string) ($form['hardroll_source_text'] ?? '')), 0, 50)
            : ($companyId ? $this->hardrollSummary($form) : '');

        $spec['revdate'] = now()->format('Y/m/d');
        $spec['timestamp'] = now()->getTimestamp();
        $spec['is_deleted'] = 0;

        return $spec;
    }

    /** Delete is soft — the product moves to the Trash view. */
    /* ---------------- Delete guard ---------------- */

    /** productid => list of what references it. Built once per render. */
    protected ?array $historyCache = null;

    /**
     * What history a product carries, for the rows on the current page.
     *
     * Checked per page rather than per row: `deleteGuard()` runs for every row,
     * and `factory_conversion` alone is 1.2M rows — a query each would be 25
     * scans per render. Every lookup below is on an indexed product column.
     */
    protected function history(int $productid): array
    {
        if ($this->historyCache === null) {
            $ids = collect($this->currentPageRows())->pluck('productid')
                ->filter()->map('intval')->unique()->values()->all();

            $this->historyCache = [];
            if ($ids !== []) {
                $bil = DB::connection('bil');
                $bil = DB::connection('bil');

                $sources = [
                    'conversion output' => $bil->table('factory_conversion')
                        ->whereIn('productid', $ids)->distinct()->pluck('productid'),
                    'factory exits' => $bil->table('factory_exit')
                        ->whereIn('productid', $ids)->distinct()->pluck('productid'),
                    'warehouse receipts' => $bil->table('finished_goods_warehouse_receipts')
                        ->whereIn('productid', $ids)->distinct()->pluck('productid'),
                    'stock on hand' => $bil->table('finished_goods_warehouse_stock')
                        ->whereIn('productid', $ids)->where('bundles', '<>', 0)
                        ->distinct()->pluck('productid'),
                    'sales orders' => $bil->table('sales_order_details')
                        ->whereIn('productid', $ids)->distinct()->pluck('productid'),
                ];

                foreach ($sources as $label => $hits) {
                    foreach ($hits as $id) {
                        $this->historyCache[(int) $id][] = $label;
                    }
                }

                // A line currently set up to run it — matched by NAME, which is
                // what conversion_setup stores.
                $names = FinishedGoodsProduct::whereIn('productid', $ids)
                    ->pluck('productname', 'productid');
                $running = $bil->table('conversion_setup')
                    ->whereIn('productname', $names->values()->all())
                    ->pluck('productname')->flip();
                foreach ($names as $id => $name) {
                    if (isset($running[$name])) {
                        $this->historyCache[(int) $id][] = 'set up on a line';
                    }
                }
            }
        }

        return $this->historyCache[$productid] ?? [];
    }

    /** Product ids on the page being rendered. */
    protected function currentPageRows(): array
    {
        $view = $this->currentView();

        return $this->buildQuery($view)->limit($this->perPage)->get(['products.productid'])->all();
    }

    /**
     * A product that has been made, moved or sold stays.
     *
     * Deleting is a soft delete, so nothing is lost — but the product would
     * vanish from every picker while its pallets, receipts and orders still
     * point at it, and the Trash view is not where anyone looks for that.
     */
    public function deleteGuard($row): ?string
    {
        $history = $this->history((int) ($row->productid ?? 0));

        if ($history === []) {
            return null;
        }

        return 'Has ' . implode(', ', array_unique($history)) . ' — cannot delete.';
    }

    protected function performDelete(int $id): void
    {
        // Re-check off-page: deleteGuard() only prefetched this page, and the
        // confirm reloads the row fresh. A cache miss must not read as "no
        // history" and let the delete through.
        if ($this->history($id) === [] && $this->historyOffPage($id) !== []) {
            session()->flash('err', 'That product has history — cannot delete.');

            return;
        }

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

        // The current revision's machines come from the assignment table;
        // earlier ones carry the labels captured when they were archived.
        return QcRevisionArchive::history(
            $this->specsId,
            $product->getAttributes() + ['machines' => $this->machineLabels($this->specsId)]
        );
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
