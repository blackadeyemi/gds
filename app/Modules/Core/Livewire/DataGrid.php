<?php

namespace Modules\Core\Livewire;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Modules\Core\Concerns\EnforcesShift;
use Modules\Core\Models\DataPage;
use Modules\Core\Support\GridExporter;

/**
 * Reusable data grid. Concrete pages declare their switchable views in
 * code (columns + query + type); this base owns search, sort, pagination,
 * the page-size selector, the view dropdown (limited to admin-enabled
 * views), New/Edit/Delete (when editable), and the 3-dots export/print.
 * Which views are available, the default view, and the default page size
 * come from the admin-managed data_pages/data_views config.
 */
#[Layout('core::layouts.admin')]
abstract class DataGrid extends Component
{
    use WithPagination;
    use EnforcesShift;

    public string $view = '';
    public string $search = '';

    /** Dropdown filter values, keyed by filter name. See filterDefs(). */
    public array $filters = [];
    public string $sortField = '';
    public string $sortDir = 'asc';
    public int $perPage = 10;

    public bool $showModal = false;
    public ?int $editingId = null;
    public ?int $confirmingDelete = null;

    public array $perPageOptions = [10, 25, 50, 100];

    /* ---------------- Child contract ---------------- */

    abstract public function pageKey(): string;
    abstract public function pageLabel(): string;

    public function pageSubtitle(): string { return ''; }

    /**
     * key => [
     *   'label'      => string,
     *   'type'       => 'table'|'summary',
     *   'columns'    => [[label, field, ?fn($row) => html], …],
     *   'query'      => fn() => Builder,     // base query, no search/sort/paginate
     *   'searchable' => [col, …],            // optional
     *   'sortable'   => [col, …],            // optional (table only)
     *   'count'      => fn() => Builder,     // optional, see paginateView()
     *   'totals'     => [field, …],          // optional footer, see totalsFor()
     * ]
     *
     * A column may supply a closure to render its cell as HTML. A column whose
     * field is null renders a control rather than a value (e.g. a Restore
     * button) and is left out of exports and print — see exportColumns().
     */
    abstract public function views(): array;

    /**
     * Dropdown filters above the grid: name => ['label' => string,
     * 'options' => [value => label], 'width' => ?int].
     *
     * Same contract and the same control as the BIL reports
     * (`core::partials.filter-select`), so a grid and a report filter alike.
     * The chosen value is applied to a column by the grid's own queries —
     * applyFilters() is the helper for that.
     *
     * Options should come from the DATA, not from a master table: offering a
     * value that matches nothing is the commonest way a filter wastes someone's
     * time.
     */
    public function filterDefs(): array { return []; }

    public function editable(): bool { return false; }
    public function formView(): ?string { return null; }

    /**
     * Whether the form's markup must be present on every render, rather than
     * only while the modal is open.
     *
     * The default is false, so a form's option lists are built and shipped only
     * when someone actually opens it. That matters once a picker gets large:
     * BPL Hardroll Production offers 4,387 products, which the searchable-select
     * partial snapshots as JSON — 405 KB of it, previously re-sent on every
     * page load, sort, page change and search keystroke, for a modal that was
     * hidden the whole time.
     *
     * A form that `@push`es scripts or styles cannot be built lazily: those land
     * in the layout's stack, which is only assembled on a full page render, so a
     * form first included during a Livewire update pushes into a stack that has
     * already been output and the asset never loads.
     *
     * PREFER moving the push to a page-level partial returned by extraView() —
     * that renders with the page, keeps the form lazy, and is what
     * Bpl\Livewire\Sales\Customers does with its address autocomplete. Reach
     * for this override only when the markup genuinely cannot be lifted out.
     */
    public function formPushesAssets(): bool { return false; }
    public function defaultSort(): array { return []; }   // [field, dir]
    public function modalSize(): string { return '480px'; }

    /** Optional extra Blade (e.g. a page-specific modal) appended after the grid. */
    public function extraView(): ?string { return null; }

    /** Optional Blade rendered between the page heading and the grid card
     *  (e.g. a tab bar switching between sibling grids). Null = nothing. */
    public function headerView(): ?string { return null; }

    /**
     * Referential-integrity guard. Return null when a row may be deleted, or a
     * human-readable reason (e.g. "In use by 3 users — cannot delete.") when it
     * has active downline references. The reason disables the row's delete
     * button (as a tooltip) and is enforced again server-side. Override per grid
     * to declare what "in use" means; base grids are always deletable.
     */
    public function deleteGuard($row): ?string { return null; }

    /** Reload a row (with any counts deleteGuard needs) for the server-side re-check. */
    protected function findRow(int $id) { return null; }

    // Editable children override these:
    protected function resetForm(): void {}
    protected function fillForm(int $id): void {}
    protected function performDelete(int $id): void {}
    public function save(): void {}

    /* ---------------- Lifecycle ---------------- */

    public function mount(): void
    {
        $cfg = $this->config();
        $this->perPage = $cfg['per_page'];
        $enabled = $this->enabledViewKeys();
        $default = $cfg['default_view'] && in_array($cfg['default_view'], $enabled)
            ? $cfg['default_view']
            : ($enabled[0] ?? array_key_first($this->views()));
        $this->view = $default;

        [$f, $d] = $this->defaultSort() + [null, 'asc'];
        $this->sortField = $f ?? '';
        $this->sortDir = $d ?? 'asc';

        foreach (array_keys($this->filterDefs()) as $filter) {
            $this->filters[$filter] ??= '';
        }
    }

    protected function config(): array
    {
        $page = DataPage::where('key', $this->pageKey())->first();
        if (! $page) {
            return ['per_page' => 10, 'default_view' => null, 'enabled' => array_keys($this->views())];
        }
        return [
            'per_page' => $page->per_page,
            'default_view' => $page->views()->where('is_default', true)->value('key'),
            'enabled' => $page->views()->where('is_enabled', true)->pluck('key')->all(),
        ];
    }

    protected function enabledViewKeys(): array
    {
        $declared = array_keys($this->views());
        $enabled = $this->config()['enabled'];
        $result = array_values(array_intersect($declared, $enabled ?: $declared));
        return $result ?: $declared;
    }

    protected function currentView(): array
    {
        $views = $this->views();
        $enabled = $this->enabledViewKeys();
        $key = in_array($this->view, $enabled) ? $this->view : ($enabled[0] ?? array_key_first($views));
        return ['key' => $key] + $views[$key];
    }

    /* ---------------- Interactions ---------------- */

    public function switchView(string $key): void
    {
        if (array_key_exists($key, $this->views())) {
            $this->view = $key;
            $this->sortField = '';
            $this->resetPage();
        }
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->resetPage(); }

    /**
     * A filter changed. Grids with a cascade override this to clear the filters
     * the changed one narrows — leaving an impossible pair selected shows an
     * empty table with nothing explaining why.
     */
    public function updatedFilters($value = null, $key = null): void { $this->resetPage(); }

    /** Apply the chosen dropdown filters. `$map` = filter name => column. */
    protected function applyFilters($q, array $map)
    {
        foreach ($map as $name => $column) {
            $value = $this->filters[$name] ?? '';
            if ($value !== '' && $value !== 'all') {
                $q->where($column, $value);
            }
        }

        return $q;
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }
    }

    protected function buildQuery(array $view)
    {
        $q = ($view['query'])();

        if ($this->search !== '' && ! empty($view['searchable'])) {
            $term = '%' . $this->search . '%';
            $q->where(function ($w) use ($view, $term) {
                foreach ($view['searchable'] as $col) {
                    $w->orWhere($col, 'like', $term);
                }
            });
        }

        $type = $view['type'] ?? 'table';
        if ($type === 'table' && $this->sortField && in_array($this->sortField, $view['sortable'] ?? [])) {
            $q->orderBy($this->sortField, $this->sortDir);
        }

        return $q;
    }

    /* ---------------- CRUD (editable grids) ---------------- */

    /**
     * Page key used for ability checks. pageKey() is the dashed form (also used
     * for the data-views config); the page registry / permissions use the
     * underscored form (aligned with route names).
     */
    protected function pageAccessKey(): string
    {
        return str_replace('-', '_', $this->pageKey());
    }

    /** May the current user perform an ability on this grid's page? */
    public function mayDo(string $ability): bool
    {
        return (bool) auth()->user()?->canDo($this->pageAccessKey(), $ability);
    }

    /**
     * Extra actions rendered at the START of the row-actions cell, before Edit
     * and Delete. Return HTML (typically a wire:click button) or '' for none —
     * for a view action that should sit with the other row controls rather than
     * take a column of its own.
     */
    public function leadingRowActions($row): string { return ''; }

    /**
     * Whether this grid contributes leading actions in the current view. Kept
     * separate from leadingRowActions() so the column can be decided once, not
     * per row.
     */
    public function hasLeadingRowActions(): bool { return false; }

    /**
     * Whether the row actions column should show. Leading actions count: a
     * view-only user with no edit or delete rights still needs the column.
     */
    public function rowActionsVisible(): bool
    {
        if ($this->hasLeadingRowActions()) {
            return true;
        }

        return $this->editable() && ($this->mayDo('edit') || $this->mayDo('delete'));
    }

    public function create(): void
    {
        if (! $this->editable() || ! $this->mayDo('create') || ! $this->ensureShiftOpen()) return;
        $this->editingId = null;
        $this->resetForm();
        $this->resetValidation();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        if (! $this->editable() || ! $this->mayDo('edit') || ! $this->ensureShiftOpen()) return;
        $this->editingId = $id;
        $this->fillForm($id);
        $this->resetValidation();
        $this->showModal = true;
    }

    public function deleteConfirmed(): void
    {
        if ($this->editable() && $this->mayDo('delete') && $this->confirmingDelete) {
            $row = $this->findRow($this->confirmingDelete);
            $reason = $row ? $this->deleteGuard($row) : null;
            if ($reason) {
                // Downline references still active — refuse (belt-and-braces
                // behind the disabled button).
                session()->flash('err', $reason);
            } else {
                $this->performDelete($this->confirmingDelete);
                session()->flash('ok', 'Record deleted.');
            }
        }
        $this->confirmingDelete = null;
    }

    /**
     * Page a view, counting the cheap way when the view offers one.
     *
     * `paginate()` runs `COUNT(*)` over the same builder it selects with —
     * joins and all. A grid that LEFT JOINs only to show a name pays for that
     * on every render: BPL Hardroll Production's 12-month listing counted
     * 27,317 rows while doing two eq_ref probes per row into products and
     * customers, none of which the count reads. 17 ms became 110 ms.
     *
     * A view may therefore supply a `count` builder — the same rows, without
     * the joins. It is used ONLY when no search is active, because the search
     * clause is built from `searchable`, which usually names joined columns;
     * counting without them would report a different set than the page shows.
     */
    protected function paginateView(array $view)
    {
        $query = $this->buildQuery($view);

        if (! isset($view['count']) || $this->search !== '') {
            return $query->paginate($this->perPage);
        }

        $total = ($view['count'])()->count();
        $page = Paginator::resolveCurrentPage();
        $items = $total > 0
            ? $query->forPage($page, $this->perPage)->get()
            : collect();

        return new LengthAwarePaginator($items, $total, $this->perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /* ---------------- Footer totals ---------------- */

    /**
     * Footer totals for the fields a view lists under `'totals'`, summed over
     * the WHOLE filtered and searched set — not just the page on screen.
     *
     * Opt-in by name, deliberately. The BIL report base auto-detects numeric
     * columns instead, and that is how it came to add up a Days column: an
     * age is numeric, and summing ages means nothing. Listing the fields
     * makes that mistake impossible here rather than merely excluded.
     */
    protected function totalsFor(array $view): array
    {
        $fields = array_values(array_filter((array) ($view['totals'] ?? [])));
        if ($fields === []) {
            return [];
        }

        $q = $this->buildQuery($view)->reorder();
        $selects = [];
        foreach ($fields as $i => $f) {
            $selects[] = 'SUM(`' . str_replace('`', '', $f) . '`) as `t' . $i . '`';
        }

        $agg = $q->getConnection()->query()->fromSub($q, 't')
            ->selectRaw(implode(', ', $selects))->first();

        $out = [];
        foreach ($fields as $i => $f) {
            $v = $agg->{'t' . $i} ?? null;
            if ($v !== null && is_numeric($v)) {
                $out[$f] = $this->formatTotal($view, $f, (float) $v);
            }
        }

        return $out;
    }

    /**
     * A footer total, through the column's own cell closure where that closure
     * works from a bare {field: value} — so a "kg" or 2-decimal column reads
     * the same in the footer as in the rows.
     */
    protected function formatTotal(array $view, string $field, float $value): string
    {
        foreach ($view['columns'] as $col) {
            if (($col[1] ?? null) === $field && isset($col[2]) && is_callable($col[2])) {
                try {
                    $s = trim(strip_tags((string) $col[2]((object) [$field => $value])));
                    if ($s !== '') {
                        return $s;
                    }
                } catch (\Throwable $e) {
                    // needs more than this one field — plain number below
                }
            }
        }

        return number_format($value, ($value == floor($value)) ? 0 : 2);
    }

    /* ---------------- Export / print ---------------- */

    /**
     * Row cap for the PDF format. dompdf loads the whole document into memory
     * and is slow per row (~15ms, superlinear); at ~450 rows × 10 columns it
     * nears 512M and the php-cgi worker OOM-crashes (empty 500, nothing logged
     * — it dies before flushing). 300 stays well inside that even though
     * GridExporter::pdf() also lifts memory to 1024M.
     *
     * Matches Bil\...\RawMaterialReport, which hit this first; xlsx/csv are
     * left uncapped because they stream.
     */
    public const PRINT_ROW_CAP = 300;

    /**
     * Above this the PDF menu item is disabled and export('pdf') refuses, so a
     * big grid can't be turned into a doomed dompdf run. Print (browser-
     * rendered, no dompdf) and Excel/CSV (streamed) stay available at any size.
     */
    public const PDF_MAX_ROWS = 150;

    /**
     * The columns an export/print should carry. Action columns declare a null
     * field — they render a button, not a value, so there is nothing to write
     * into a spreadsheet.
     */
    protected function exportColumns(array $view): array
    {
        return array_values(array_filter($view['columns'], fn ($c) => ($c[1] ?? null) !== null));
    }

    protected function rowsForExport(array $view, ?int $limit = null): array
    {
        $columns = $this->exportColumns($view);
        $query = $this->buildQuery($view);
        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(fn ($r) => array_map(fn ($c) => (string) data_get($r, $c[1]), $columns))
            ->all();
    }

    /**
     * Count the current view's rows but stop at $cap — a `LIMIT $cap` subquery
     * wrapped in a COUNT, so it returns min(actual, $cap) without scanning the
     * whole set.
     */
    protected function cappedCount(array $view, int $cap): int
    {
        $connection = $this->buildQuery($view)->getModel()->getConnection();
        $sub = $this->buildQuery($view)->reorder()->limit($cap);

        return $connection->query()->fromSub($sub, 't')->count();
    }

    /**
     * What produced this data — the view and any search — written into every
     * export and printout. A spreadsheet is read away from the screen that made
     * it, so it has to say what it is on its own.
     */
    public function exportContext(): array
    {
        $out = [];

        if (count($this->views()) > 1) {
            // The resolved view, not the raw property: the print route leaves it
            // blank when the grid opened on its default.
            $out[] = ['View', $this->currentView()['label'] ?? ''];
        }

        foreach ($this->filterDefs() as $name => $def) {
            $value = $this->filters[$name] ?? '';
            if ($value !== '' && $value !== 'all') {
                $out[] = [$def['label'] ?? $name, (string) ($def['options'][$value] ?? $value)];
            }
        }

        if ($this->search !== '') {
            $out[] = ['Search', $this->search];
        }

        return $out;
    }

    public function export(string $format = 'xlsx')
    {
        abort_unless($this->mayDo('export'), 403);

        $view = $this->currentView();
        $headings = array_map(fn ($c) => $c[0], $this->exportColumns($view));
        $base = str_replace('.', '-', $this->pageKey());
        $context = $this->exportContext();

        if (strtolower($format) === 'pdf') {
            // Refused server-side as well as disabled in the menu: export() is
            // reachable directly, and an oversized dompdf run takes the worker
            // down with it rather than failing cleanly.
            if ($this->cappedCount($view, self::PDF_MAX_ROWS + 1) > self::PDF_MAX_ROWS) {
                abort(422, 'This view has more than ' . self::PDF_MAX_ROWS
                    . ' rows — use Print, or export to Excel/CSV for the full data.');
            }

            return GridExporter::pdf($base, $this->pageLabel(), $headings, $this->rowsForExport($view, self::PRINT_ROW_CAP), $context);
        }

        return GridExporter::download($format, $base, $headings, $this->rowsForExport($view), $context);
    }

    /** Used by the generic print controller (plain call, no Livewire lifecycle). */
    public function printPayload(?string $viewKey, string $search): array
    {
        if ($viewKey) $this->view = $viewKey;
        $this->search = $search;
        $view = $this->currentView();

        return [
            'label' => $this->pageLabel(),
            'context' => $this->exportContext(),
            'headings' => array_map(fn ($c) => $c[0], $this->exportColumns($view)),
            'rows' => $this->rowsForExport($view),
            'logo' => \Modules\Core\Support\Branding::logo($this->pageKey()),
        ];
    }

    /* ---------------- Render ---------------- */

    public function render()
    {
        $view = $this->currentView();
        $rows = $this->paginateView($view);

        return view('core::livewire.datagrid', [
            'gridView' => $view,
            'viewsList' => array_map(
                fn ($k) => ['key' => $k, 'label' => $this->views()[$k]['label']],
                $this->enabledViewKeys()
            ),
            'rows' => $rows,
            'columns' => $view['columns'],
            // Every grid view paginates, so the total is already to hand — no
            // extra count needed to decide whether PDF is offered.
            'totals' => $this->totalsFor($view),
            'pdfBlocked' => $rows->total() > self::PDF_MAX_ROWS,
            'pdfMaxRows' => self::PDF_MAX_ROWS,
        ]);
    }
}
