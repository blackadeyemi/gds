<?php

namespace Modules\Bil\Livewire\RawMaterials\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Models\RawMaterialStock;

/**
 * Reports → Warehouse Stock. Current stock on hand, over `rawmaterials_stock`
 * (the per-product/location aggregate). Renamed from the legacy "Current Stock"
 * report (Report\Rawmaterials\Stock). Only lines with quantity > 0 are shown.
 *
 * Edit resets a line's quantity + weight (a "stock reset", appended to the
 * line's modification log), mirroring the legacy modify(). Delete removes a
 * stock line, but is DISABLED while the product still has in-store barcodes at
 * that location (deleting would desync the aggregate from the barcodes).
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
        return 'Current raw-material stock on hand, per product and location.';
    }

    public function hasDateRange(): bool
    {
        return false; // stock is a live snapshot
    }

    protected function options(): array
    {
        return $this->optCache ??= [
            'locations' => DB::connection('bil')->table('rawmaterials_stock')
                ->distinct()->orderBy('location')->pluck('location', 'location')->all(),
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
            'group' => ['label' => 'Group', 'options' => $o['groups']],
            'subgroup' => ['label' => 'Sub Group', 'options' => $o['subgroups']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
        ];
    }

    protected function base()
    {
        $q = DB::connection('bil')->table('rawmaterials_stock as s')
            ->leftJoin('rawmaterials_products as p', 's.productid', '=', 'p.id')
            ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
            ->leftJoin('rawmaterials_subgroups as sg', 'p.subgroupid', '=', 'sg.id')
            ->where('s.quantity', '>', 0);

        $this->applyFilters($q, [
            'location' => 's.location',
            'product' => 's.productid',
            'group' => 'p.groupid',
            'subgroup' => 'p.subgroupid',
        ]);

        return $q;
    }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
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
                'query' => fn () => $this->base()
                    ->select('s.id', 's.location', 'g.groupname', 'sg.subgroupname', 'p.productname',
                        's.quantity', 's.weight', 's.productid')
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
                'query' => fn () => $this->base()
                    ->selectRaw('g.groupname, sg.subgroupname, SUM(s.quantity) as quantity, SUM(s.weight) as weight')
                    ->groupBy('g.groupname', 'sg.subgroupname')
                    ->orderBy('g.groupname')->orderBy('sg.subgroupname'),
            ],
        ];
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
