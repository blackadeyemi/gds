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
    public int $perPage = 25;

    /** Filter values keyed by name (see filterDefs()). */
    public array $filters = [];

    public array $perPageOptions = [25, 50, 100, 250];

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

    /** Widest date span (days) for which a full COUNT is still affordable. */
    protected int $countableSpanDays = 92;

    /**
     * Whether to use count-free simple pagination (prev/next only) instead of
     * full pagination (which shows a total + page numbers).
     *
     * Full pagination runs a `SELECT COUNT(*)` over the whole filtered set, and
     * because the reports join products/groups/etc. for display, that count is
     * expensive on the big legacy tables over a wide range (e.g. Warehouse Exit
     * all-time = 316k joined rows → ~10s, historically a 30s timeout). But over
     * a bounded range the same count is trivial (~0.1s for a month), and showing
     * "Showing 1–25 of 655" + page numbers is what users expect.
     *
     * So: full pagination for a bounded range (≤ countableSpanDays), count-free
     * for a very wide or absent range. Snapshot reports with no date filter keep
     * it count-free by overriding this.
     */
    public function usesSimplePagination(): bool
    {
        if (! $this->hasDateRange() || $this->dateFrom === '' || $this->dateTo === '') {
            return true;
        }
        $span = strtotime($this->dateTo) - strtotime($this->dateFrom);

        return $span === false || $span > $this->countableSpanDays * 86400;
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

    public function canEdit(): bool
    {
        return ! $this->readOnly() && $this->editFields() !== [] && (bool) auth()->user()?->can('edit-raw-materials');
    }

    public function canDelete(): bool
    {
        return ! $this->readOnly() && (bool) auth()->user()?->can('delete-raw-materials');
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

    public function updatedDateFrom(): void { $this->resetPage(); }
    public function updatedDateTo(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->resetPage(); }
    public function updatedView(): void { $this->resetPage(); }
    public function updatedFilters(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }

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

    /** URL to the print route carrying the current view + filters + search. */
    public function printUrl(): string
    {
        return route('bil.raw-materials.reports.print', ['report' => $this->printKey()])
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
        return route('bil.raw-materials.reports.download', ['report' => $this->printKey()])
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
            $rows = $q->paginate($this->perPage);
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
