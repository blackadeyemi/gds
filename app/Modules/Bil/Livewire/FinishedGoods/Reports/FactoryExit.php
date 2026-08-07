<?php

namespace Modules\Bil\Livewire\FinishedGoods\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Bil\Models\FactoryExit as FactoryExitModel;
use Modules\Bil\Models\FactoryExitLocation;
use Modules\Bil\Models\FinishedGoodsProduct;

/**
 * BIL → Finished Goods → Reports → Factory Exit. Rebuild of the legacy
 * report_factory_exit.php ("Item Sent (to Warehouse)"), over factory_exit.
 *
 * The legacy screen offered five views; the three kept here are the ones it led
 * with — Default, Summary (by location, product) and Summary (by product) —
 * matching Report\Factory\Out::option1/2/5 column for column. Calendar Y and
 * Calendar X are deliberately left behind, as on the Conversion Output report.
 *
 * "Palette" in the legacy headings is spelled **Pallet** here.
 *
 * There is no edit: an exit row records a scan, and its product and bundle count
 * are the pallet's, not the operator's. Delete mirrors the legacy
 * Factory\Out::_delete — remove the row and put the pallet back on the factory
 * floor (`factory_conversion.status` = NULL) — but refuses once the store has
 * received the pallet, because the receipt is what would then be orphaned.
 */
#[Title('Factory Exit Report')]
class FactoryExit extends RawMaterialReport
{
    protected ?array $optCache = null;
    protected ?array $receivedCache = null;

    public function title(): string
    {
        return 'Factory Exit Report';
    }

    public function printKey(): string
    {
        return 'factory-exit';
    }

    public function subtitle(): string
    {
        return 'Pallets sent from the factory to the warehouse — by pallet, by gate and by product.';
    }

    protected function reportPageKey(): string
    {
        return 'bil.finished_goods.reports.factory_exit';
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
     * Factories and gates come from factoryexit_details — the same three rows
     * the entry screen offers, so a filter can never name a gate nothing was
     * ever scanned through.
     */
    protected function options(): array
    {
        if ($this->optCache !== null) {
            return $this->optCache;
        }

        $gates = FactoryExitLocation::orderBy('factoryname')->orderBy('exitlocation')->get();

        return $this->optCache = [
            'factories' => $gates->pluck('factoryname', 'factoryname')->all(),
            'locations' => $gates->pluck('exitlocation', 'exitlocation')->all(),
            'products' => FinishedGoodsProduct::query()->active()
                ->orderBy('productname')->pluck('productname', 'productname')->all(),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'factory' => ['label' => 'Factory', 'options' => $o['factories']],
            'location' => ['label' => 'Exit Location', 'options' => $o['locations']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
        ];
    }

    /**
     * `dateofexit` is a varchar in Y/m/d form, which sorts correctly as a
     * string, so the range compares directly — as the legacy report did.
     *
     * factoryexit_details is joined only to resolve the gate's factory: the exit
     * row stores the location name, not a factory.
     */
    protected function base()
    {
        $f = $this->filters;

        return DB::connection('bil')->table('factory_exit as e')
            ->leftJoin('products as p', 'e.productid', '=', 'p.productid')
            ->leftJoin('factoryexit_details as d', 'e.exitlocation', '=', 'd.exitlocation')
            ->when($this->dateFrom !== '', fn ($q) => $q->where('e.dateofexit', '>=', str_replace('-', '/', $this->dateFrom)))
            ->when($this->dateTo !== '', fn ($q) => $q->where('e.dateofexit', '<=', str_replace('-', '/', $this->dateTo)))
            ->when($f['factory'] ?? '', fn ($q, $v) => $q->where('d.factoryname', $v))
            ->when($f['location'] ?? '', fn ($q, $v) => $q->where('e.exitlocation', $v))
            ->when($f['product'] ?? '', fn ($q, $v) => $q->where('p.productname', $v))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('e.barcode', 'like', $term)
                  ->orWhere('p.productname', 'like', $term)
                  ->orWhere('p.productcode', 'like', $term)
                  ->orWhere('e.exitlocation', 'like', $term);
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
                    ['Factory', 'factoryname'],
                    ['Location', 'exitlocation'],
                    ['Product Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Bundles', 'bundles'],
                ],
                'searchable' => ['e.barcode', 'p.productname', 'p.productcode', 'e.exitlocation'],
                'query' => fn () => $this->base()
                    ->select('e.id', 'e.barcode', 'd.factoryname', 'e.exitlocation',
                        'p.productcode', 'p.productname', 'e.bundles')
                    // id is chronological, so newest-first via the PK — fast on
                    // any range, unlike ordering by the varchar date.
                    ->orderByDesc('e.id'),
            ],
            'by_location_product' => [
                'label' => 'Summary (by location, product)',
                'type' => 'summary',
                'columns' => [
                    ['Location', 'exitlocation'],
                    ['Product Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Pallets', 'pallets'],
                    ['Bundles', 'bundles'],
                ],
                'searchable' => ['p.productname', 'p.productcode', 'e.exitlocation'],
                'query' => fn () => $this->base()
                    ->selectRaw('e.exitlocation, p.productcode, p.productname,
                                 COUNT(e.barcode) as pallets, SUM(e.bundles) as bundles')
                    ->groupBy('e.exitlocation', 'p.productcode', 'p.productname')
                    ->orderBy('e.exitlocation')->orderBy('p.productname'),
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

    /**
     * Has the store received this pallet?
     *
     * The page's barcodes are prefetched in one query on first use, so a normal
     * render costs a single lookup. Anything NOT on that page — notably the
     * server-side re-check when a delete is confirmed — falls through to its own
     * query rather than being assumed absent: a cache miss must not read as
     * "not received" and quietly permit the delete.
     *
     * `factory_exit.status` mirrors this, but it is a denormalised copy written
     * by two different screens, so the receipt itself is the authority.
     */
    protected function isReceived(string $barcode): bool
    {
        if ($barcode === '') {
            return false;
        }

        if (array_key_exists($barcode, $this->receivedCache ?? [])) {
            return $this->receivedCache[$barcode];
        }

        if ($this->receivedCache === null) {
            $this->receivedCache = [];
            $page = $this->currentPageBarcodes();

            if ($page !== []) {
                $hits = array_flip(
                    DB::connection('bil')->table('store_entrance')
                        ->whereIn('barcode', $page)->distinct()->pluck('barcode')->all()
                );
                foreach ($page as $code) {
                    $this->receivedCache[$code] = isset($hits[$code]);
                }

                if (array_key_exists($barcode, $this->receivedCache)) {
                    return $this->receivedCache[$barcode];
                }
            }
        }

        return $this->receivedCache[$barcode] = DB::connection('bil')->table('store_entrance')
            ->where('barcode', $barcode)->exists();
    }

    /** Barcodes on the page currently being rendered. */
    protected function currentPageBarcodes(): array
    {
        $view = $this->currentView();
        if (($view['type'] ?? 'table') !== 'table') {
            return [];
        }

        return $this->runQuery($view)->limit($this->perPage)->pluck('barcode')->all();
    }

    public function deleteGuard($row): ?string
    {
        return $this->isReceived((string) ($row->barcode ?? ''))
            ? 'Already received into the warehouse — delete the store entry first.'
            : null;
    }

    protected function findRow(int $id)
    {
        return FactoryExitModel::find($id);
    }

    /**
     * Undo the exit: drop the row and put the pallet back on the factory floor,
     * exactly as the legacy Factory\Out::_delete did.
     */
    protected function performDelete(int $id): void
    {
        $exit = FactoryExitModel::find($id);
        if (! $exit) {
            return;
        }

        DB::connection('bil')->transaction(function () use ($exit) {
            DB::connection('bil')->table('factory_conversion')
                ->where('barcode', $exit->barcode)->update(['status' => null]);
            $exit->delete();
        });

        // The cached "received" answers describe rows that may no longer exist.
        $this->receivedCache = null;
    }
}
