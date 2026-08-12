<?php

namespace Modules\Bil\Livewire\FinishedGoods\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Bil\Models\FgWarehouseReceipt;
use Modules\Bil\Models\FinishedGoodsProduct;
use Modules\Bil\Support\FinishedGoodsStock;
use Modules\Core\Models\Warehouse;
use Modules\Core\Models\WarehouseEntrance as WarehouseEntranceModel;

/**
 * BIL → Finished Goods → Reports → Warehouse Entrance, over the rebuilt
 * `finished_goods_warehouse_receipts`.
 *
 * Keeps the three views the legacy report_store_entrance.php led with —
 * Default, Summary (by entrance, product) and Summary (by product) — with
 * warehouse in place of the old hard-coded store floor.
 *
 * ⚠️ **This shows gds receipts only.** The 1.17M legacy `store_entrance` rows
 * are not merged in: the schemas differ, the legacy app still owns and reports
 * on them, and blending two sources into one total is how a number nobody can
 * reproduce gets created. The legacy report remains the place to look at
 * anything received before the cut-over.
 *
 * There is no edit: a receipt records a scan, and its product and bundle count
 * are the pallet's. Delete un-receives — it takes the bundles back OUT of the
 * warehouse total. Unlike the legacy design that is now recoverable: stock
 * derives from these rows, so `bil:reconcile-fg-stock` can always prove it.
 *
 * The report joins `products` on the bil connection by hand rather than through
 * a query-builder join, because the receipts live on `core` and the products on
 * `bil` — MySQL cannot join across two connections in one statement.
 */
#[Title('Warehouse Entrance Report')]
class WarehouseEntrance extends RawMaterialReport
{
    protected ?array $optCache = null;

    /** productid => ['productname','productcode'], loaded once per request. */
    protected ?array $productCache = null;

    public function title(): string
    {
        return 'Warehouse Entrance Report';
    }

    public function printKey(): string
    {
        return 'warehouse-entrance';
    }

    public function subtitle(): string
    {
        return 'Pallets received into the finished-goods warehouses — by pallet, by entrance and by product.';
    }

    protected function reportPageKey(): string
    {
        return 'bil.finished_goods.reports.warehouse_entrance';
    }

    protected function printRouteName(): string
    {
        return 'bil.finished-goods.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bil.finished-goods.reports.download';
    }

    protected function options(): array
    {
        return $this->optCache ??= [
            'warehouses' => Warehouse::ordered()->pluck('name', 'id')->all(),
            'entrances' => WarehouseEntranceModel::ordered()->pluck('name', 'id')->all(),
            'products' => FinishedGoodsProduct::query()->active()
                ->orderBy('productname')->pluck('productname', 'productid')->all(),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'warehouse' => ['label' => 'Warehouse', 'options' => $o['warehouses']],
            'entrance' => ['label' => 'Entrance', 'options' => $o['entrances']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
        ];
    }

    /**
     * `date_of_entrance` is a real DATE here, so the ISO range compares
     * directly — no string juggling, unlike the legacy `Y/m/d` varchar.
     */
    protected function base()
    {
        $f = $this->filters;

        return DB::connection('core')->table('finished_goods_warehouse_receipts as r')
            ->leftJoin('warehouse_entrances as e', 'r.entrance_id', '=', 'e.id')
            ->leftJoin('warehouses as w', 'r.warehouse_id', '=', 'w.id')
            ->when($this->dateFrom !== '', fn ($q) => $q->where('r.date_of_entrance', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('r.date_of_entrance', '<=', $this->dateTo))
            ->when($f['warehouse'] ?? '', fn ($q, $v) => $q->where('r.warehouse_id', $v))
            ->when($f['entrance'] ?? '', fn ($q, $v) => $q->where('r.entrance_id', $v))
            ->when($f['product'] ?? '', fn ($q, $v) => $q->where('r.productid', $v))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('r.barcode', 'like', $term)
                  ->orWhere('e.name', 'like', $term)
                  ->orWhere('w.name', 'like', $term)
                  // Products are on another connection, so a product-name search
                  // is resolved to ids first and matched here.
                  ->orWhereIn('r.productid', $this->productIdsMatching($this->search));
            }));
    }

    /** Product ids whose name or code matches a search term (bil connection). */
    protected function productIdsMatching(string $term): array
    {
        return DB::connection('bil')->table('products')
            ->where('productname', 'like', '%' . $term . '%')
            ->orWhere('productcode', 'like', '%' . $term . '%')
            ->pluck('productid')->all();
    }

    /** productid => product row, for rendering names the join can't reach. */
    protected function products(): array
    {
        return $this->productCache ??= DB::connection('bil')->table('products')
            ->get(['productid', 'productname', 'productcode'])
            ->keyBy('productid')
            ->map(fn ($p) => ['name' => $p->productname, 'code' => $p->productcode])
            ->all();
    }

    protected function productName($id): string
    {
        return $this->products()[(int) $id]['name'] ?? '—';
    }

    protected function productCode($id): string
    {
        return $this->products()[(int) $id]['code'] ?? '—';
    }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Warehouse', 'warehouse_name'],
                    ['Entrance', 'entrance_name'],
                    ['Product Code', 'productid', fn ($r) => e($this->productCode($r->productid))],
                    ['Product', 'productname', fn ($r) => e($this->productName($r->productid))],
                    ['Bundles', 'bundles'],
                    $this->dateCol('Date', 'date_of_entrance'),
                ],
                // Product name/code are resolved per row from the other
                // connection, so they cannot be sorted on in SQL.
                'sortable' => ['barcode', 'warehouse_name', 'entrance_name', 'bundles', 'date_of_entrance'],
                'query' => fn () => $this->base()
                    ->select('r.id', 'r.barcode', 'w.name as warehouse_name', 'e.name as entrance_name',
                        'r.productid', 'r.bundles', 'r.date_of_entrance')
                    ->orderByDesc('r.id'),
            ],
            'by_entrance_product' => [
                'label' => 'Summary (by entrance, product)',
                'type' => 'summary',
                'columns' => [
                    ['Warehouse', 'warehouse_name'],
                    ['Entrance', 'entrance_name'],
                    ['Product Code', 'productid', fn ($r) => e($this->productCode($r->productid))],
                    ['Product', 'productname', fn ($r) => e($this->productName($r->productid))],
                    ['Pallets', 'pallets'],
                    ['Bundles', 'bundles'],
                ],
                'sortable' => ['warehouse_name', 'entrance_name', 'pallets', 'bundles'],
                'query' => fn () => $this->base()
                    ->selectRaw('w.name as warehouse_name, e.name as entrance_name, r.productid,
                                 COUNT(r.barcode) as pallets, SUM(r.bundles) as bundles')
                    ->groupBy('w.name', 'e.name', 'r.productid')
                    ->orderBy('w.name')->orderBy('e.name'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Product Code', 'productid', fn ($r) => e($this->productCode($r->productid))],
                    ['Product', 'productname', fn ($r) => e($this->productName($r->productid))],
                    ['Pallets', 'pallets'],
                    ['Bundles', 'bundles'],
                ],
                'sortable' => ['pallets', 'bundles'],
                'query' => fn () => $this->base()
                    ->selectRaw('r.productid, COUNT(r.barcode) as pallets, SUM(r.bundles) as bundles')
                    ->groupBy('r.productid')
                    ->orderByDesc(DB::raw('SUM(r.bundles)')),
            ],
        ];
    }

    /* ---------------- Delete ---------------- */

    protected function findRow(int $id)
    {
        return FgWarehouseReceipt::find($id);
    }

    /**
     * Un-receive the pallet: drop the receipt, take its bundles back out of the
     * warehouse total, and clear `factory_exit.status` so it reads as sent but
     * not yet received.
     *
     * The reversal uses the RECEIPT's own warehouse, product and bundles — not
     * the entrance's current warehouse — so it exactly mirrors what this receipt
     * added, even if the gate has since been moved.
     */
    protected function performDelete(int $id): void
    {
        $receipt = FgWarehouseReceipt::find($id);
        if (! $receipt) {
            return;
        }

        DB::connection('core')->transaction(function () use ($receipt) {
            FinishedGoodsStock::apply(
                (int) $receipt->warehouse_id,
                (int) $receipt->productid,
                -(int) $receipt->bundles
            );

            DB::connection('bil')->table('factory_exit')
                ->where('barcode', $receipt->barcode)->update(['status' => null]);

            $receipt->delete();
        });
    }
}
