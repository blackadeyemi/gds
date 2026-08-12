<?php

namespace Modules\Bil\Livewire\RawMaterials\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\WarehouseGate;
use Livewire\Attributes\Title;
use Modules\Bil\Models\RawMaterialItem;
use Modules\Bil\Support\RawMaterialsStock;
use Modules\Core\Models\Warehouse;

/**
 * Reports → Warehouse Entry. Raw material received into the warehouse, over
 * `rawmaterials_warehouse_entry`. This is the legacy "Items Received" report
 * (Report\Rawmaterials\Entrance) content, kept alongside the delivery-staging
 * "Supplier Deliveries" report.
 *
 * Delete removes the in-store barcode AND decrements the stock aggregate
 * (quantity −1, weight − the item's weight), mirroring the legacy _delete.
 * Edit adjusts the item's weight and shifts stock by the delta. Both are
 * DISABLED once the item has left the store (status not NULL) — you cannot
 * retro-edit an item that has already been exited/consumed.
 */
#[Title('Warehouse Entry Report')]
class WarehouseEntry extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Warehouse Entry Report';
    }

    public function printKey(): string
    {
        return 'warehouse-entry';
    }

    public function subtitle(): string
    {
        return 'Raw material received into the warehouse (live in-store items).';
    }


    /**
     * Gates, for the filter.
     *
     * Only gds movements carry a `gate_id` — the legacy app never recorded which
     * gate was used, and the historic rows were deliberately not backfilled with
     * a guess. So this filter narrows to movements booked through gds; the
     * Location filter is still the one that covers all of history.
     */
    protected function options(): array
    {
        return $this->optCache ??= [
            'gates' => WarehouseGate::ordered()->pluck('name', 'id')->all(),
            'suppliers' => DB::connection('bil')->table('rawmaterials_supplier')
                ->orderBy('suppliername')->pluck('suppliername', 'suppliercode')->all(),
            'products' => DB::connection('bil')->table('rawmaterials_products')
                ->orderBy('productname')->pluck('productname', 'id')->all(),
            'locations' => DB::connection('bil')->table('rawmaterial_store_location')
                ->orderBy('id')->pluck('location', 'id')->all(),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'supplier' => ['label' => 'Supplier', 'options' => $o['suppliers']],
            'location' => ['label' => 'Location', 'options' => $o['locations']],
            'gate' => ['label' => 'Entrance Gate', 'options' => $o['gates']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
        ];
    }

    /**
     * Exact total without the display joins. Unlike Warehouse Exit / Consumption
     * there is no barcode join here — products/groups/sub-groups all join on
     * primary keys, so they cannot fan out and dropping them can't change the
     * count. Every filter is on the base table too, so this covers all of them.
     *
     * Worth having: over a 7-year range the joined count took ~22s (the date
     * predicate makes MySQL read full rows), against ~1ms here.
     */
    protected function countQuery()
    {
        $q = DB::connection('bil')->table('rawmaterials_warehouse_entry as r');
        $this->applyDate($q, 'r.dateofcreation');
        $this->applyFilters($q, [
            'supplier' => 'r.suppliercode',
            'gate' => 'r.gate_id',
            'location' => 'r.location_id',
            'product' => 'r.productid',
        ]);

        return $q;
    }

    protected function base()
    {
        // No store-location join here: on a LIMIT query it forces a hash join
        // that defeats the ordered-index early-stop (→ full scan + filesort of
        // 229k, ~30s). The paginated view resolves the name in PHP; the summary
        // views (no LIMIT) add the join themselves.
        $q = DB::connection('bil')->table('rawmaterials_warehouse_entry as r')
            ->leftJoin('rawmaterials_products as p', 'r.productid', '=', 'p.id')
            ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
            ->leftJoin('rawmaterials_subgroups as sg', 'p.subgroupid', '=', 'sg.id');

        $this->applyDate($q, 'r.dateofcreation');
        $this->applyFilters($q, [
            'supplier' => 'r.suppliercode',
            'gate' => 'r.gate_id',
            'location' => 'r.location_id',
            'product' => 'r.productid',
        ]);

        return $q;
    }

    /** Resolve a store location name from its id without a SQL join. */
    protected ?array $locationMap = null;

    protected function locationName($id): string
    {
        $this->locationMap ??= DB::connection('bil')->table('rawmaterial_store_location')
            ->pluck('location', 'id')->all();

        return $this->locationMap[(int) $id] ?? '—';
    }

    public function views(): array
    {
        $status = fn ($row) => $row->status
            ? '<span class="badge">' . e($row->status) . '</span>'
            : '<span class="badge badge-success">In store</span>';
        $location = fn ($row) => e($this->locationName($row->location_id));

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Location', 'location_id', $location],
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Product', 'productname'],
                    ['Weight (kg)', 'weight'],
                    $this->dateCol('Date', 'dateofcreation'),
                    ['Status', 'status', $status],
                ],
                'searchable' => ['r.barcode', 'p.productname', 'g.groupname', 'sg.subgroupname', 'r.status'],
                'query' => fn () => $this->base()
                    ->select('r.id', 'r.barcode', 'r.location_id', 'g.groupname', 'sg.subgroupname',
                        'p.productname', 'r.weight', 'r.dateofcreation', 'r.status')
                    ->orderByDesc('r.id'),
            ],
            'by_location_subgroup' => [
                'label' => 'Summary (by location, sub group)',
                'type' => 'summary',
                'columns' => [
                    ['Location', 'location_id', $location],
                    ['Group', 'groupname'],
                    ['Sub Group', 'subgroupname'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['g.groupname', 'sg.subgroupname'],
                'query' => fn () => $this->base()
                    ->selectRaw('r.location_id, g.groupname, sg.subgroupname, COUNT(*) as quantity, SUM(r.weight) as weight')
                    ->groupBy('r.location_id', 'g.groupname', 'sg.subgroupname')
                    ->orderBy('r.location_id')->orderBy('sg.subgroupname'),
            ],
            'by_location_product' => [
                'label' => 'Summary (by location, product)',
                'type' => 'summary',
                'columns' => [
                    ['Location', 'location_id', $location],
                    ['Product', 'productname'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['p.productname'],
                'query' => fn () => $this->base()
                    ->selectRaw('r.location_id, p.productname, COUNT(*) as quantity, SUM(r.weight) as weight')
                    ->groupBy('r.location_id', 'p.productname')
                    ->orderBy('r.location_id')->orderBy('p.productname'),
            ],
        ];
    }

    public function editFields(): array
    {
        return ['weight' => ['label' => 'Weight (kg)']];
    }

    /** Only in-store items (status NULL) may be edited/deleted. */
    protected function leftStoreGuard($row): ?string
    {
        if ($row && $row->status !== null) {
            return 'Item has left the store (' . $row->status . ') — cannot modify.';
        }

        return null;
    }

    public function editGuard($row): ?string
    {
        return $this->leftStoreGuard($row);
    }

    public function deleteGuard($row): ?string
    {
        return $this->leftStoreGuard($row);
    }

    protected function findRow(int $id)
    {
        return RawMaterialItem::query()->find($id);
    }

    protected function fillEdit(int $id): void
    {
        $this->edit = ['weight' => (float) (RawMaterialItem::whereKey($id)->value('weight') ?? 0)];
    }

    public function saveEdit(): void
    {
        $item = RawMaterialItem::find($this->editingId);
        if (! $item || $item->status !== null) {
            return;
        }

        $newWeight = (float) ($this->edit['weight'] ?? 0);
        $delta = $newWeight - (float) $item->weight;

        $item->update(['weight' => $newWeight]);

        // Shift the warehouse's stock by the weight change (quantity unchanged).
        if ($warehouseId = $this->warehouseIdFor($item->location_id)) {
            RawMaterialsStock::apply($warehouseId, (int) $item->productid, 0, $delta);
        }
    }

    /**
     * The warehouse behind a legacy `location_id`.
     *
     * Movement rows still carry the legacy store id because the legacy app
     * reads it; stock is keyed by warehouse, so the two are mapped here.
     */
    protected function warehouseIdFor($legacyLocationId): ?int
    {
        if (! $legacyLocationId) {
            return null;
        }

        return Warehouse::where('legacy_location_id', $legacyLocationId)->value('id');
    }

    protected function performDelete(int $id): void
    {
        $item = RawMaterialItem::find($id);
        if (! $item || $item->status !== null) {
            return;
        }

        // Take it back out of the warehouse's stock, then remove the item.
        if ($warehouseId = $this->warehouseIdFor($item->location_id)) {
            RawMaterialsStock::apply($warehouseId, (int) $item->productid, -1, -(float) $item->weight);
        }

        $item->delete();
    }
}
