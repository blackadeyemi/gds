<?php

namespace Modules\Core\Livewire;

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
     *   'columns'    => [[label, field], …],
     *   'query'      => fn() => Builder,     // base query, no search/sort/paginate
     *   'searchable' => [col, …],            // optional
     *   'sortable'   => [col, …],            // optional (table only)
     * ]
     */
    abstract public function views(): array;

    public function editable(): bool { return false; }
    public function formView(): ?string { return null; }
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

    /** Whether the row actions column should show (edit and/or delete allowed). */
    public function rowActionsVisible(): bool
    {
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

    /* ---------------- Export / print ---------------- */

    protected function rowsForExport(array $view): array
    {
        $rows = $this->buildQuery($view)->get();
        return $rows->map(fn ($r) => array_map(fn ($c) => (string) data_get($r, $c[1]), $view['columns']))->all();
    }

    public function export(string $format = 'xlsx')
    {
        $view = $this->currentView();
        $headings = array_map(fn ($c) => $c[0], $view['columns']);
        $base = str_replace('.', '-', $this->pageKey());
        $rows = $this->rowsForExport($view);

        if (strtolower($format) === 'pdf') {
            return GridExporter::pdf($base, $this->pageLabel(), $headings, $rows);
        }

        return GridExporter::download($format, $base, $headings, $rows);
    }

    /** Used by the generic print controller (plain call, no Livewire lifecycle). */
    public function printPayload(?string $viewKey, string $search): array
    {
        if ($viewKey) $this->view = $viewKey;
        $this->search = $search;
        $view = $this->currentView();

        return [
            'label' => $this->pageLabel(),
            'headings' => array_map(fn ($c) => $c[0], $view['columns']),
            'rows' => $this->rowsForExport($view),
        ];
    }

    /* ---------------- Render ---------------- */

    public function render()
    {
        $view = $this->currentView();

        return view('core::livewire.datagrid', [
            'gridView' => $view,
            'viewsList' => array_map(
                fn ($k) => ['key' => $k, 'label' => $this->views()[$k]['label']],
                $this->enabledViewKeys()
            ),
            'rows' => $this->buildQuery($view)->paginate($this->perPage),
            'columns' => $view['columns'],
        ]);
    }
}
