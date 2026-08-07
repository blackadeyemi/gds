<?php

namespace Modules\Bil\Livewire\RawMaterials\Reports;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Core\Support\GridExporter;

/**
 * Shared base for the Raw Materials reports (Supplier Deliveries, Warehouse
 * Entry, Warehouse Stock, …). Rebuilt from the legacy report_rawmaterial_*
 * pages, which were DataTables screens with a "Search Options" modal, a view
 * switcher (Default / summaries) and — for editors — row edit/delete.
 *
 * A concrete report declares its filters, its switchable views (columns +
 * base query), and — where the legacy allowed it — how a row is edited or
 * deleted, plus the referential guards that DISABLE those actions (e.g. a
 * delivery already received into the warehouse, or an item that has left the
 * store). Edit is gated by `edit-raw-materials`, delete by `delete-raw-materials`.
 */
#[Layout('core::layouts.admin')]
abstract class RawMaterialReport extends Component
{
    use WithPagination;

    public string $dateFrom = '';
    public string $dateTo = '';
    public string $view = '';
    public string $search = '';
    // Starts at 10, matching the DataGrid pages, so a report opens with a short
    // page rather than a wall of rows.
    public int $perPage = 10;

    /** Filter values keyed by name (see filterDefs()). */
    public array $filters = [];

    public array $perPageOptions = [10, 25, 50, 100, 250];

    // Row edit modal + delete confirmation.
    public bool $showEdit = false;
    public ?int $editingId = null;
    public array $edit = [];
    public ?int $confirmingDelete = null;

    /* ---------------- Child contract ---------------- */

    abstract public function title(): string;

    /** Slug used for the print route + export filenames (e.g. 'supplier-deliveries'). */
    abstract public function printKey(): string;

    public function subtitle(): string
    {
        return '';
    }

    /** Whether the date-range picker applies to this report (stock is a snapshot). */
    public function hasDateRange(): bool
    {
        return true;
    }

    /**
     * Whether to use count-free simple pagination (prev/next only) instead of
     * full pagination (which shows a total + page numbers).
     *
     * Reports used to drop to count-free past a fixed date span, on the grounds
     * that the display-joined COUNT gets expensive. That cost is real but
     * avoidable: the display joins are all LEFT joins, so they cannot change the
     * row count, and counting the base table alone returns the identical number.
     * Measured at all-time on dev — joined vs join-free:
     *
     *   warehouse-exit    316,836 rows   9135ms  ->    1ms
     *   factory-entrance  178,114 rows   4913ms  ->    2ms
     *   consumption       123,804 rows   4783ms  ->  100ms
     *   everything else                  <170ms
     *
     * So the default is now full pagination on ANY range, including all-time.
     * A report whose joined count is slow supplies countQuery() below and pays
     * nothing. The one case that still costs (~5s) is a joined-column predicate
     * over a multi-year range, where the join-free count would be wrong — that
     * is what slowCountNotice() warns about rather than silently dropping the
     * total. Snapshot reports override this.
     */
    public function usesSimplePagination(): bool
    {
        return false;
    }

    /**
     * Filter keys whose predicate lives on a joined table, so a join-free count
     * would be wrong. Reports with such filters declare them here.
     */
    protected function joinedFilterKeys(): array
    {
        return [];
    }

    /**
     * A join-free equivalent of base() used only for counting: the base table
     * with the date range and base-column filters applied, and none of the
     * display joins. Null when the report doesn't need one (its joined count is
     * already cheap).
     */
    protected function countQuery()
    {
        return null;
    }

    /**
     * Whether the current filters force the count to go through the joins.
     * Search counts too — the searchable columns include joined ones on the
     * reports that have joined filters at all.
     */
    protected function countNeedsJoins(): bool
    {
        foreach ($this->joinedFilterKeys() as $key) {
            if (($this->filters[$key] ?? '') !== '') {
                return true;
            }
        }

        return $this->search !== '';
    }

    /**
     * Whether an exact total for this report costs seconds once the range gets
     * wide. True for the reports whose count has to go through the barcode →
     * warehouse-entry join: that join is not cardinality-preserving (a barcode
     * can match several entry rows), so it can't be dropped without making the
     * total wrong, and 300k fan-out lookups take ~7s however it's indexed.
     */
    protected function countIsSlowOverWideRange(): bool
    {
        return false;
    }

    /** Whether the current range is wide enough for that cost to bite (> ~1 year). */
    protected function hasWideRange(): bool
    {
        if (! $this->hasDateRange() || $this->dateFrom === '' || $this->dateTo === '') {
            return true; // unbounded / all-time
        }
        $span = strtotime($this->dateTo) - strtotime($this->dateFrom);

        return $span === false || $span > 366 * 86400;
    }

    /**
     * A message for the UI when the exact total for the current combination is
     * going to take seconds, or null when it's cheap. Shown inline rather than
     * blocking: the report still loads with a real total and page numbers, the
     * user just gets told why it's taking a moment.
     */
    public function slowCountNotice(): ?string
    {
        if (! $this->hasWideRange()) {
            return null;
        }

        // A joined-column filter forces the count through the product/group
        // chain as well, which is slower still.
        if ($this->countQuery() !== null && $this->countNeedsJoins()) {
            return 'Counting every matching row over this range has to go through the '
                . 'product and group lookup, which takes several seconds. Narrowing the '
                . 'dates, or clearing the group / sub-group / search filters, makes it quicker.';
        }

        if ($this->countIsSlowOverWideRange()) {
            return 'This report resolves each row back to its raw material by barcode, so '
                . 'an exact total over a range this wide takes a few seconds. Narrowing the '
                . 'dates makes it immediate.';
        }

        return null;
    }

    /**
     * A cheap pre-computed total for full pagination, or null to let paginate()
     * run its default COUNT(*). Override when the default count is expensive but
     * a faster equivalent exists (e.g. a join-free count on the base table).
     * Only consulted when usesSimplePagination() is false.
     */
    protected function paginationTotal(array $view): ?int
    {
        // Only the row-listing views paginate; summaries are fetched whole.
        if (($view['type'] ?? 'table') !== 'table') {
            return null;
        }

        // A joined predicate is active, so the join-free count would be wrong —
        // return null and let paginate() run the accurate (slower) count.
        if ($this->countNeedsJoins()) {
            return null;
        }

        return $this->countQuery()?->count();
    }

    /**
     * Dropdown filters: name => ['label' => string, 'options' => [value => label]].
     * The selected value is applied to a column by the report's own queries.
     */
    public function filterDefs(): array
    {
        return [];
    }

    /**
     * Views: key => [
     *   'label'   => string,
     *   'type'    => 'table'|'summary',
     *   'columns' => [[label, field, ?closure], …],
     *   'query'   => fn() => Builder,   // already filtered; base applies paging
     * ].
     */
    abstract public function views(): array;

    /* ---------------- Summary row drill-down ---------------- */

    public bool $detailOpen = false;
    public ?string $detailKey = null;

    /**
     * The fields that identify a summary row for drill-down — every column the
     * view groups by, e.g. ['linename', 'project'] — or null for no drill-down.
     *
     * Non-null puts a view-detail button on each row of that view, which opens
     * the records behind that group in a modal: the legacy grouped reports
     * listed each group's records under a heading, and a modal gives them the
     * width to read properly without pushing the table around. Keyed by value
     * rather than id because summary rows are aggregates and have no id — and
     * by *all* the grouping columns, or the modal would show more records than
     * the count the user clicked (one project can appear under two lines).
     */
    public function expandableBy(): ?array
    {
        return null;
    }

    /** Identity of one summary row, as a single string the view can post back. */
    public function detailKeyFor(object $row): ?string
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        return implode("\x1f", array_map(fn ($f) => (string) data_get($row, $f), $fields));
    }

    /** Splits a key made by detailKeyFor() back into its parts. */
    public function detailKeyParts(string $key): array
    {
        return explode("\x1f", $key);
    }

    /** Columns for the detail table: [[label, field, ?fn($row) => html], …]. */
    public function detailColumns(): array
    {
        return [];
    }

    /** The records behind one summary row. */
    public function detailRows(string $key): iterable
    {
        return [];
    }

    /** Heading for the detail modal. */
    public function detailTitle(string $key): string
    {
        return $key;
    }

    /** Optional line under the heading (e.g. the group's totals). */
    public function detailSubtitle(string $key): string
    {
        return '';
    }

    public function openRowDetails(string $key): void
    {
        if (! $this->expandableBy()) {
            return;
        }

        $this->detailKey = $key;
        $this->detailOpen = true;
    }

    public function closeRowDetails(): void
    {
        $this->detailOpen = false;
        $this->detailKey = null;
    }

    /** Fields for the generic edit modal: name => ['label','step'?]. Empty = no edit. */
    public function editFields(): array
    {
        return [];
    }

    /** A read-only report shows no row actions (historical logs / snapshots). */
    public function readOnly(): bool
    {
        return false;
    }

    /* ---------------- Permissions ---------------- */

    /** Page key for this report's abilities: bil.raw_materials.reports.{slug}. */
    protected function reportPageKey(): string
    {
        return 'bil.raw_materials.reports.' . str_replace('-', '_', $this->printKey());
    }

    /** May the current user perform an ability on this report's page? */
    public function mayDo(string $ability): bool
    {
        return (bool) auth()->user()?->canDo($this->reportPageKey(), $ability);
    }

    public function canEdit(): bool
    {
        return ! $this->readOnly() && $this->editFields() !== [] && $this->mayDo('edit');
    }

    public function canDelete(): bool
    {
        return ! $this->readOnly() && $this->mayDo('delete');
    }

    public function hasActions(): bool
    {
        return $this->canEdit() || $this->canDelete();
    }

    /** Reason to DISABLE delete for a row (referential guard), or null to allow. */
    public function deleteGuard($row): ?string
    {
        return null;
    }

    /** Reason to DISABLE edit for a row, or null to allow. */
    public function editGuard($row): ?string
    {
        return null;
    }

    /* ---------------- Child hooks ---------------- */

    protected function initFilters(): void {}

    protected function findRow(int $id)
    {
        return null;
    }

    protected function fillEdit(int $id): void {}

    protected function performDelete(int $id): void {}

    public function saveEdit(): void {}

    /* ---------------- Lifecycle ---------------- */

    public function mount(): void
    {
        $this->dateFrom = now()->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
        foreach (array_keys($this->filterDefs()) as $name) {
            $this->filters[$name] = $this->filters[$name] ?? '';
        }
        $this->view = array_key_first($this->views());
        $this->initFilters();
    }

    // Any change to what's being listed also closes open detail rows — they
    // belong to the previous result set.
    public function updatedDateFrom(): void { $this->resetListing(); }
    public function updatedDateTo(): void { $this->resetListing(); }
    public function updatedPerPage(): void { $this->resetListing(); }
    public function updatedView(): void { $this->resetListing(); }
    public function updatedFilters(): void { $this->resetListing(); }
    public function updatedSearch(): void { $this->resetListing(); }

    protected function resetListing(): void
    {
        $this->resetPage();
        $this->closeRowDetails();
    }

    protected function currentView(): array
    {
        $views = $this->views();
        $key = array_key_exists($this->view, $views) ? $this->view : array_key_first($views);

        return ['key' => $key] + $views[$key];
    }

    /* ---------------- Query helpers for children ---------------- */

    /** Apply the date range to a column. `$slash` = the column stores Y/m/d strings. */
    protected function applyDate($q, string $column, bool $slash = false)
    {
        if (! $this->hasDateRange() || $this->dateFrom === '' || $this->dateTo === '') {
            return $q;
        }
        $from = $slash ? str_replace('-', '/', $this->dateFrom) : $this->dateFrom;
        $to = $slash ? str_replace('-', '/', $this->dateTo) : $this->dateTo;

        return $q->whereBetween($column, [$from, $to]);
    }

    /** Apply dropdown filters. `$map` = filterName => column. */
    protected function applyFilters($q, array $map)
    {
        foreach ($map as $name => $col) {
            $v = $this->filters[$name] ?? '';
            if ($v !== '' && $v !== 'all') {
                $q->where($col, $v);
            }
        }

        return $q;
    }

    /* ---------------- Date display ---------------- */

    /**
     * The standard report date display, using the browser's chosen date format
     * (Appearance → Date format; default 03/Jul/2026). Used by every report so
     * dates read the same on screen, in print, and in exports (all of which run
     * each cell through the column closure via mapRow). Pass
     * $format when the stored value isn't year-first/ISO — e.g. the legacy
     * `d/m/y` text in return_approval, which Carbon would otherwise misread as
     * m/d/y. Blank / zero dates render empty; anything unparseable is left as-is.
     */
    protected function fmtDate($value, ?string $format = null): string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000')) {
            return '';
        }
        try {
            $dt = $format
                ? \Carbon\Carbon::createFromFormat($format, $value)
                : \Carbon\Carbon::parse($value);

            return $dt ? $dt->format(\Modules\Core\Support\Prefs::dateFormat()) : $value;
        } catch (\Throwable $e) {
            return $value;
        }
    }

    /**
     * A date column spec rendered via fmtDate() — use this for every report date
     * column so the DD/Mon/YYYY format stays consistent everywhere and in one place.
     */
    protected function dateCol(string $label, string $field, ?string $format = null): array
    {
        return [$label, $field, fn ($r) => $this->fmtDate(data_get($r, $field), $format)];
    }

    /* ---------------- Edit / delete ---------------- */

    public function editRow(int $id): void
    {
        if (! $this->canEdit()) {
            return;
        }
        $row = $this->findRow($id);
        if ($row && ($reason = $this->editGuard($row))) {
            session()->flash('err', $reason);

            return;
        }
        $this->editingId = $id;
        $this->fillEdit($id);
        $this->showEdit = true;
    }

    public function persistEdit(): void
    {
        if (! $this->canEdit() || $this->editingId === null) {
            return;
        }
        $row = $this->findRow($this->editingId);
        if ($row && ($reason = $this->editGuard($row))) {
            session()->flash('err', $reason);
            $this->showEdit = false;

            return;
        }
        $this->saveEdit();
        $this->showEdit = false;
        $this->editingId = null;
        session()->flash('ok', 'Record updated.');
    }

    public function deleteConfirmed(): void
    {
        if ($this->canDelete() && $this->confirmingDelete) {
            $row = $this->findRow($this->confirmingDelete);
            $reason = $row ? $this->deleteGuard($row) : 'Row not found.';
            if ($reason) {
                session()->flash('err', $reason);
            } else {
                $this->performDelete($this->confirmingDelete);
                session()->flash('ok', 'Record deleted.');
            }
        }
        $this->confirmingDelete = null;
    }

    /* ---------------- Query + live search ---------------- */

    /** Build the current view's query and apply the live-search term. */
    protected function runQuery(array $view)
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

        return $q;
    }

    /* ---------------- Export / print ---------------- */

    /**
     * Row cap for the PDF/print formats. dompdf loads the whole document into
     * memory and is slow per row (~15ms, superlinear). At ~450 rows × 10 columns
     * it nears 512M and the php-cgi worker OOM-crashes (empty 500, nothing
     * logged). 300 keeps a worst-case PDF well within memory and ~5s even at the
     * standard limit; GridExporter::pdf() also lifts memory to 1024M for headroom.
     * Bulk data belongs in xlsx/csv (which stream and are left uncapped).
     */
    public const PRINT_ROW_CAP = 300;

    /**
     * Max rows for the server-rendered PDF export. Above this the PDF menu item
     * is disabled (dompdf holds the whole doc in memory), and the user is steered
     * to Print (browser-rendered, no dompdf) or Excel (streamed, full data).
     */
    public const PDF_MAX_ROWS = 150;

    /** Map one result row to an array of scalar cells (column closures → text). */
    protected function mapRow($r, array $view): array
    {
        return array_map(
            fn ($c) => isset($c[2]) && is_callable($c[2])
                ? trim(strip_tags((string) $c[2]($r)))
                : (string) (data_get($r, $c[1]) ?? ''),
            $view['columns']
        );
    }

    /** Capped rows for PDF/print (dompdf loads all rows into one document). */
    protected function reportRows(array $view, int $limit): array
    {
        return $this->runQuery($view)->limit($limit)->get()
            ->map(fn ($r) => $this->mapRow($r, $view))->all();
    }

    /**
     * Lazily stream every matching row (via a DB cursor) for xlsx/csv export, so
     * a wide-range export of a 200k-row table can't exhaust memory.
     */
    protected function reportRowsLazy(array $view): \Generator
    {
        foreach ($this->runQuery($view)->cursor() as $r) {
            yield $this->mapRow($r, $view);
        }
    }

    /** Payload for the print route (title + headings + rows for the current view). */
    public function reportPayload(): array
    {
        $view = $this->currentView();

        return [
            'label' => $this->title(),
            'headings' => array_map(fn ($c) => $c[0], $view['columns']),
            'rows' => $this->reportRows($view, self::PRINT_ROW_CAP),
        ];
    }

    public function export(string $format = 'xlsx')
    {
        // A full xlsx/csv export streams row-by-row (low memory) but a wide
        // range can be 100k+ rows and take longer than the 30s web limit;
        // lift it for this user-initiated action.
        @set_time_limit(0);

        $view = $this->currentView();
        $headings = array_map(fn ($c) => $c[0], $view['columns']);
        $base = 'rm-' . $this->printKey();

        if (strtolower($format) === 'pdf') {
            // Enforce the PDF row limit server-side too (the UI disables it, but
            // the download route is a plain GET that a crafted URL could hit).
            if ($this->cappedCount($view, self::PDF_MAX_ROWS + 1) > self::PDF_MAX_ROWS) {
                abort(422, 'This report has more than ' . self::PDF_MAX_ROWS
                    . ' rows — use Print or Export Excel instead.');
            }

            // dompdf renders one document — cap rows to stay responsive.
            return GridExporter::pdf($base, $this->title(), $headings, $this->reportRows($view, self::PRINT_ROW_CAP));
        }

        // xlsx/csv stream row-by-row from a DB cursor — full set, low memory.
        return GridExporter::download($format, $base, $headings, $this->reportRowsLazy($view));
    }

    /** Query params describing the current view + filters + search + date range. */
    protected function reportQueryParams(): array
    {
        $q = ['view' => $this->view];
        if ($this->search !== '') {
            $q['search'] = $this->search;
        }
        if ($this->hasDateRange()) {
            $q['dateFrom'] = $this->dateFrom;
            $q['dateTo'] = $this->dateTo;
        }
        $active = array_filter($this->filters, fn ($v) => $v !== '' && $v !== null);
        if ($active !== []) {
            $q['filters'] = $active;
        }

        return $q;
    }

    /**
     * Route names for print/download. Reports outside Raw Materials (e.g.
     * Machines → Reports → Services) live under a different prefix and override
     * these; everything else keeps the RM routes.
     */
    protected function printRouteName(): string
    {
        return 'bil.raw-materials.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bil.raw-materials.reports.download';
    }

    /** URL to the print route carrying the current view + filters + search. */
    public function printUrl(): string
    {
        return route($this->printRouteName(), ['report' => $this->printKey()])
            . '?' . http_build_query($this->reportQueryParams());
    }

    /**
     * URL to the direct-download route for a given format (xlsx/csv/pdf). A
     * plain <a href> to this streams a real file download from the server,
     * instead of Livewire base64-encoding the file into its JSON response
     * (which breaks large exports and blanks some browsers' PDF viewers).
     */
    public function downloadUrl(string $format): string
    {
        return route($this->downloadRouteName(), ['report' => $this->printKey()])
            . '?' . http_build_query(['format' => $format] + $this->reportQueryParams());
    }

    /* ---------------- Render ---------------- */

    public function render()
    {
        $view = $this->currentView();
        $isSummary = ($view['type'] ?? 'table') === 'summary';
        $q = $this->runQuery($view);

        if ($isSummary) {
            $rows = $q->get();
        } elseif ($this->usesSimplePagination()) {
            $rows = $q->simplePaginate($this->perPage);
        } else {
            // A report may supply a cheap pre-computed total (e.g. a join-free
            // count) so full pagination doesn't run the expensive default
            // COUNT(*) over the display-joined set; null → let paginate() count.
            $total = $this->paginationTotal($view);
            $rows = $q->paginate($this->perPage, ['*'], 'page', null, $total);
        }

        // Decide whether PDF export is allowed for the current result set. Reuse
        // a count we already have where possible; otherwise do one cheap capped
        // count (LIMIT PDF_MAX_ROWS+1) so this stays fast even on wide ranges.
        if ($isSummary) {
            $exportRows = $rows->count();
        } elseif ($rows instanceof LengthAwarePaginator) {
            $exportRows = $rows->total();
        } else {
            $exportRows = $this->cappedCount($view, self::PDF_MAX_ROWS + 1);
        }
        $pdfBlocked = $exportRows > self::PDF_MAX_ROWS;

        return view('bil::livewire.raw-materials.reports.report', [
            'gridView' => $view,
            'columns' => $view['columns'],
            'rows' => $rows,
            'paginated' => $rows instanceof Paginator,
            'hasTotal' => $rows instanceof LengthAwarePaginator,
            'pdfBlocked' => $pdfBlocked,
            'pdfMaxRows' => self::PDF_MAX_ROWS,
            'viewsList' => array_map(
                fn ($k) => ['key' => $k, 'label' => $this->views()[$k]['label']],
                array_keys($this->views())
            ),
        ]);
    }

    /**
     * Count the current view's rows but stop at $cap — a `LIMIT $cap` subquery
     * wrapped in a COUNT, so it returns min(actual, $cap) and never scans the
     * whole joined set (fast on any date range, unlike a plain COUNT).
     */
    protected function cappedCount(array $view, int $cap): int
    {
        $sub = $this->runQuery($view)->reorder()->limit($cap);

        return DB::connection('bil')->query()->fromSub($sub, 't')->count();
    }
}
