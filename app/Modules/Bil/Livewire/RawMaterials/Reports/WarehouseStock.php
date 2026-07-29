<?php

namespace Modules\Bil\Livewire\RawMaterials\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Models\RawMaterialStock;

/**
 * Reports → Warehouse Stock. Current stock on hand.
 *
 * The **source of truth is the barcodes** — each in-store unit is a row in
 * `rawmaterials_warehouse_entry` with `status IS NULL` (still in store). So the
 * default view lists those barcodes, and two summaries roll them up (by product,
 * by sub group). The legacy per-product/location aggregate `rawmaterials_stock`
 * is kept as a separate "Aggregate (legacy)" tab — it's what several still-live
 * legacy pages read and the only editable surface here (a stock reset appended
 * to the line's modification audit; delete disabled while barcodes reference it).
 * The aggregate is reconciled from the barcodes by `bil:reconcile-warehouse-stock`,
 * so the tabs agree.
 *
 * Only the Aggregate tab shows row actions — canEdit()/canDelete() are overridden
 * to be view-aware (the barcode/summary tabs are read-only snapshots).
 */
#[Title('Warehouse Stock Report')]
class WarehouseStock extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Warehouse Stock Report';
    }

    public function printKey(): string
    {
        return 'warehouse-stock';
    }

    public function subtitle(): string
    {
        return 'Raw material currently in the warehouse — by barcode, with per-product and legacy-aggregate rollups.';
    }

    public function hasDateRange(): bool
    {
        return false; // stock is a live snapshot
    }

    public function usesSimplePagination(): bool
    {
        // Both table views are small enough for an instant COUNT (the aggregate
        // is ~120 lines; the in-store barcode set ~14k, counted via the
        // status/product index — see paginationTotal). Use numbered pagination
        // with a visible total rather than the count-free prev/next.
        return false;
    }

    /**
     * Fast join-free total for the big barcode view — the display joins are all
     * on unique keys, so a base-table count is exact. Falls back to the default
     * COUNT (null) when a joined-column predicate is active, or for the small
     * aggregate view where the default count is already trivial.
     */
    protected function paginationTotal(array $view): ?int
    {
        if (($view['key'] ?? '') !== 'barcodes') {
            return null;
        }
        $joined = ($this->filters['group'] ?? '') !== '' || ($this->filters['subgroup'] ?? '') !== '';
        if ($joined || $this->search !== '') {
            return null;
        }

        $q = DB::connection('bil')->table('rawmaterials_warehouse_entry')->whereNull('status');
        $this->applyFilters($q, ['location' => 'location_id', 'entry_type' => 'source', 'product' => 'productid']);

        return $q->count();
    }

    protected function options(): array
    {
        return $this->optCache ??= [
            'locations' => DB::connection('bil')->table('rawmaterial_store_location')
                ->orderBy('location')->pluck('location', 'id')->all(),
            'products' => DB::connection('bil')->table('rawmaterials_products')
                ->orderBy('productname')->pluck('productname', 'id')->all(),
            'groups' => DB::connection('bil')->table('rawmaterials_groups')
                ->orderBy('groupname')->pluck('groupname', 'id')->all(),
            'subgroups' => DB::connection('bil')->table('rawmaterials_subgroups')
                ->orderBy('subgroupname')->pluck('subgroupname', 'id')->all(),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'location' => ['label' => 'Location', 'options' => $o['locations']],
            'entry_type' => ['label' => 'Entry Type', 'options' => [
                'supplier' => 'From Suppliers',
                'factory' => 'From Factory',
            ]],
            'group' => ['label' => 'Group', 'options' => $o['groups']],
            'subgroup' => ['label' => 'Sub Group', 'options' => $o['subgroups']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
        ];
    }

    /** In-store barcodes (status IS NULL) with product/group/subgroup joined + filters applied. */
    protected function barcodeBase()
    {
        $q = DB::connection('bil')->table('rawmaterials_warehouse_entry as w')
            ->leftJoin('rawmaterials_products as p', 'w.productid', '=', 'p.id')
            ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
            ->leftJoin('rawmaterials_subgroups as sg', 'p.subgroupid', '=', 'sg.id')
            ->whereNull('w.status');

        $this->applyFilters($q, [
            'location' => 'w.location_id',
            'entry_type' => 'w.source',
            'product' => 'w.productid',
            'group' => 'p.groupid',
            'subgroup' => 'p.subgroupid',
        ]);

        return $q;
    }

    /** The legacy aggregate lines (rawmaterials_stock), qty > 0, filters applied. */
    protected function aggregateBase()
    {
        $q = DB::connection('bil')->table('rawmaterials_stock as s')
            ->leftJoin('rawmaterials_products as p', 's.productid', '=', 'p.id')
            ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
            ->leftJoin('rawmaterials_subgroups as sg', 'p.subgroupid', '=', 'sg.id')
            ->leftJoin('rawmaterial_store_location as loc', 'loc.location', '=', 's.location')
            ->where('s.quantity', '>', 0);

        $this->applyFilters($q, [
            'location' => 'loc.id',
            'product' => 's.productid',
            'group' => 'p.groupid',
            'subgroup' => 'p.subgroupid',
        ]);

        return $q;
    }

    public function views(): array
    {
        return [
            'barcodes' => [
                'label' => 'Barcodes (in warehouse)',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Supplier', 'suppliercode'],
                    ['Entry Type', 'source', fn ($r) => $r->source === 'factory' ? 'From Factory' : 'From Suppliers'],
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Product', 'productname'],
                    ['Weight (kg)', 'weight'],
                    $this->dateCol('Date In', 'dateofcreation'),
                ],
                'searchable' => ['w.barcode', 'w.suppliercode', 'p.productname', 'g.groupname', 'sg.subgroupname'],
                'query' => fn () => $this->barcodeBase()
                    ->select('w.id', 'w.barcode', 'w.suppliercode', 'w.source', 'g.groupname', 'sg.subgroupname',
                        'p.productname', 'w.weight', 'w.dateofcreation')
                    ->orderByDesc('w.id'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Product', 'productname'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['p.productname', 'g.groupname', 'sg.subgroupname'],
                'query' => fn () => $this->barcodeBase()
                    ->selectRaw('g.groupname, sg.subgroupname, p.productname, COUNT(*) as quantity, SUM(w.weight) as weight')
                    ->groupBy('w.productid', 'g.groupname', 'sg.subgroupname', 'p.productname')
                    ->orderBy('g.groupname')->orderBy('sg.subgroupname')->orderBy('p.productname'),
            ],
            'by_subgroup' => [
                'label' => 'Summary (by sub group)',
                'type' => 'summary',
                'columns' => [
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['g.groupname', 'sg.subgroupname'],
                'query' => fn () => $this->barcodeBase()
                    ->selectRaw('g.groupname, sg.subgroupname, COUNT(*) as quantity, SUM(w.weight) as weight')
                    ->groupBy('g.groupname', 'sg.subgroupname')
                    ->orderBy('g.groupname')->orderBy('sg.subgroupname'),
            ],
            'aggregate' => [
                'label' => 'Aggregate (legacy)',
                'type' => 'table',
                'columns' => [
                    ['Location', 'location'],
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Product', 'productname'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['s.location', 'p.productname', 'g.groupname', 'sg.subgroupname'],
                'query' => fn () => $this->aggregateBase()
                    ->select('s.id', 's.location', 'g.groupname', 'sg.subgroupname', 'p.productname',
                        's.quantity', 's.weight', 's.productid')
                    ->orderBy('g.groupname')->orderBy('sg.subgroupname')->orderBy('p.productname'),
            ],
        ];
    }

    /* ---------------- Row actions: Aggregate tab only ---------------- */

    public function canEdit(): bool
    {
        return $this->view === 'aggregate' && parent::canEdit();
    }

    public function canDelete(): bool
    {
        return $this->view === 'aggregate' && parent::canDelete();
    }

    public function editFields(): array
    {
        return [
            'quantity' => ['label' => 'Quantity', 'step' => '1'],
            'weight' => ['label' => 'Weight (kg)'],
        ];
    }

    protected ?array $inStoreSet = null;
    protected ?array $locationIds = null;

    /**
     * productid|location_id => count of in-store barcodes, computed once per
     * render (one indexed GROUP BY) instead of a query per row.
     */
    protected function inStoreSet(): array
    {
        return $this->inStoreSet ??= DB::connection('bil')->table('rawmaterials_warehouse_entry')
            ->whereNull('status')
            ->selectRaw('productid, location_id, COUNT(*) as c')
            ->groupBy('productid', 'location_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->productid . '|' . $r->location_id => (int) $r->c])
            ->all();
    }

    protected function locationIdFor(string $location): ?int
    {
        $this->locationIds ??= DB::connection('bil')->table('rawmaterial_store_location')
            ->pluck('id', 'location')->all();

        return $this->locationIds[$location] ?? null;
    }

    /** Cannot delete a stock line while in-store barcodes still reference it. */
    public function deleteGuard($row): ?string
    {
        if (! $row) {
            return 'Row not found.';
        }

        $locationId = $this->locationIdFor($row->location);
        $inStore = $this->inStoreSet()[$row->productid . '|' . $locationId] ?? 0;

        return $inStore > 0
            ? "Product still has {$inStore} in-store barcode(s) at {$row->location} — cannot delete."
            : null;
    }

    protected function findRow(int $id)
    {
        return RawMaterialStock::query()->find($id);
    }

    protected function fillEdit(int $id): void
    {
        $stock = RawMaterialStock::find($id);
        $this->edit = [
            'quantity' => (int) ($stock->quantity ?? 0),
            'weight' => (float) ($stock->weight ?? 0),
        ];
    }

    public function saveEdit(): void
    {
        $stock = RawMaterialStock::find($this->editingId);
        if (! $stock) {
            return;
        }

        $quantity = max(0, (int) ($this->edit['quantity'] ?? 0));
        $weight = max(0, (float) ($this->edit['weight'] ?? 0));

        // Append a "Stock reset" entry to the modification JSON audit trail.
        $entry = json_encode([
            'description' => 'Stock reset',
            'weight' => $weight,
            'user' => auth()->user()?->username ?? '',
            'timestamp' => now()->timestamp,
        ]);

        // Guard against legacy rows whose `modification` isn't valid JSON.
        DB::connection('bil')->statement(
            "UPDATE `rawmaterials_stock` SET `quantity` = ?, `weight` = ?, `modification` = JSON_ARRAY_APPEND(IF(JSON_VALID(`modification`), `modification`, JSON_ARRAY()), '$', CAST(? AS JSON)) WHERE `id` = ?",
            [$quantity, $weight, $entry, $stock->id]
        );
    }

    protected function performDelete(int $id): void
    {
        RawMaterialStock::whereKey($id)->delete();
    }
}
