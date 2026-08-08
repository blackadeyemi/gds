<?php

namespace Modules\Bil\Livewire\FinishedGoods\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Bil\Models\FinishedGoodsProduct;
use Modules\Bil\Models\StoreEntrance;
use Modules\Bil\Models\StoreEntranceLocation;
use Modules\Bil\Support\FinishedGoodsStock;

/**
 * BIL → Finished Goods → Reports → Warehouse Entrance. Rebuild of the legacy
 * report_store_entrance.php ("Item Received"), over store_entrance (1.17M rows).
 *
 * The legacy screen offered five views; the three kept here are the ones it led
 * with — Default, Summary (by location, product) and Summary (by product) —
 * matching Report\Store\Entrance::option1/2/5 column for column. Calendar Y and
 * Calendar X are deliberately left behind, as on the other two pallet reports.
 *
 * "Palette" in the legacy headings is spelled **Pallet** here.
 *
 * There is no edit: a receipt records a scan, and its product and bundle count
 * are the pallet's. Delete mirrors the legacy Store\Entrance::_delete — and
 * that delete is the one on this module that moves money-equivalent numbers: it
 * must take the bundles back OUT of the warehouse and floor totals, or stock
 * silently overstates. Nothing recomputes those totals from the receipts.
 */
#[Title('Warehouse Entrance Report')]
class WarehouseEntrance extends RawMaterialReport
{
    protected ?array $optCache = null;

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
        return 'Pallets received into the finished-goods warehouse — by pallet, by gate and by product.';
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

    /**
     * Floors and gates come from storeentrance_details — the same three rows the
     * entry screen offers, so a filter can never name a gate nothing was ever
     * received through.
     */
    protected function options(): array
    {
        if ($this->optCache !== null) {
            return $this->optCache;
        }

        $gates = StoreEntranceLocation::orderBy('storefloor')->orderBy('entrancelocation')->get();

        return $this->optCache = [
            'floors' => $gates->pluck('storefloor', 'storefloor')->all(),
            'locations' => $gates->pluck('entrancelocation', 'entrancelocation')->all(),
            'products' => FinishedGoodsProduct::query()->active()
                ->orderBy('productname')->pluck('productname', 'productname')->all(),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'floor' => ['label' => 'Store Floor', 'options' => $o['floors']],
            'location' => ['label' => 'Entrance Location', 'options' => $o['locations']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
        ];
    }

    /**
     * `dateofentrance` is a varchar in Y/m/d form, which sorts correctly as a
     * string, so the range compares directly — as the legacy report did.
     *
     * storeentrance_details is joined only to resolve the gate's store floor:
     * the receipt stores the gate name, not a floor.
     */
    protected function base()
    {
        $f = $this->filters;

        return DB::connection('bil')->table('store_entrance as e')
            ->leftJoin('products as p', 'e.productid', '=', 'p.productid')
            ->leftJoin('storeentrance_details as d', 'e.entrancelocation', '=', 'd.entrancelocation')
            ->when($this->dateFrom !== '', fn ($q) => $q->where('e.dateofentrance', '>=', str_replace('-', '/', $this->dateFrom)))
            ->when($this->dateTo !== '', fn ($q) => $q->where('e.dateofentrance', '<=', str_replace('-', '/', $this->dateTo)))
            ->when($f['floor'] ?? '', fn ($q, $v) => $q->where('d.storefloor', $v))
            ->when($f['location'] ?? '', fn ($q, $v) => $q->where('e.entrancelocation', $v))
            ->when($f['product'] ?? '', fn ($q, $v) => $q->where('p.productname', $v))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('e.barcode', 'like', $term)
                  ->orWhere('p.productname', 'like', $term)
                  ->orWhere('p.productcode', 'like', $term)
                  ->orWhere('e.entrancelocation', 'like', $term);
            }));
    }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Floor', 'storefloor'],
                    ['Location', 'entrancelocation'],
                    ['Product Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Bundles', 'bundles'],
                ],
                'searchable' => ['e.barcode', 'p.productname', 'p.productcode', 'e.entrancelocation'],
                'query' => fn () => $this->base()
                    ->select('e.id', 'e.barcode', 'd.storefloor', 'e.entrancelocation',
                        'p.productcode', 'p.productname', 'e.bundles')
                    // id is chronological, so newest-first via the PK — fast on
                    // any range, unlike ordering by the varchar date.
                    ->orderByDesc('e.id'),
            ],
            'by_location_product' => [
                'label' => 'Summary (by location, product)',
                'type' => 'summary',
                'columns' => [
                    ['Location', 'entrancelocation'],
                    ['Product Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Pallets', 'pallets'],
                    ['Bundles', 'bundles'],
                ],
                'searchable' => ['p.productname', 'p.productcode', 'e.entrancelocation'],
                'query' => fn () => $this->base()
                    ->selectRaw('e.entrancelocation, p.productcode, p.productname,
                                 COUNT(e.barcode) as pallets, SUM(e.bundles) as bundles')
                    ->groupBy('e.entrancelocation', 'p.productcode', 'p.productname')
                    ->orderBy('e.entrancelocation')->orderBy('p.productname'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Product Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Pallets', 'pallets'],
                    ['Bundles', 'bundles'],
                ],
                'searchable' => ['p.productname', 'p.productcode'],
                'query' => fn () => $this->base()
                    ->selectRaw('p.productcode, p.productname,
                                 COUNT(e.barcode) as pallets, SUM(e.bundles) as bundles')
                    ->groupBy('p.productcode', 'p.productname')
                    ->orderBy('p.productname'),
            ],
        ];
    }

    /* ---------------- Delete ---------------- */

    protected function findRow(int $id)
    {
        return StoreEntrance::find($id);
    }

    /**
     * Un-receive the pallet, exactly as the legacy Store\Entrance::_delete did:
     * drop the receipt, take the bundles back out of the warehouse and floor
     * totals, and clear `factory_exit.status` so the pallet reads as sent but
     * not yet received again.
     *
     * The stock reversal uses the RECEIPT's own product, bundles and gate — not
     * the pallet's current values — so it is an exact mirror of what the receipt
     * added, even if the pallet has since been changed.
     */
    protected function performDelete(int $id): void
    {
        $entrance = StoreEntrance::find($id);
        if (! $entrance) {
            return;
        }

        $username = (string) (auth()->user()?->username ?? auth()->user()?->name ?? '');

        DB::connection('bil')->transaction(function () use ($entrance, $username) {
            FinishedGoodsStock::apply(
                (int) $entrance->productid,
                -(int) $entrance->bundles,
                (string) $entrance->entrancelocation,
                $username,
                now()->getTimestamp()
            );

            DB::connection('bil')->table('factory_exit')
                ->where('barcode', $entrance->barcode)->update(['status' => null]);

            $entrance->delete();
        });
    }
}
