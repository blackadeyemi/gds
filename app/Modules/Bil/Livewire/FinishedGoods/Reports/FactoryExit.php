<?php

namespace Modules\Bil\Livewire\FinishedGoods\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Bil\Models\FactoryExit as FactoryExitModel;
use Modules\Bil\Models\FinishedGoodsProduct;
use Modules\Core\Models\Factory;
use Modules\Core\Models\FactoryGate;

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
     * Gates come from `factory_exit_locations` on the core connection, so they
     * are resolved in PHP rather than joined: `factory_exit` lives on bil and
     * MySQL cannot join two connections in one statement.
     *
     * Inactive gates are still offered as FILTERS — the retired Bil-2 gate has
     * 16 historic exits, and a report you cannot filter to is one that hides
     * them.
     */
    protected function options(): array
    {
        if ($this->optCache !== null) {
            return $this->optCache;
        }

        $gates = FactoryGate::with('factory')->direction(FactoryGate::OUT)->ordered()->get();

        return $this->optCache = [
            'factories' => Factory::orderBy('name')->pluck('name', 'id')->all(),
            'locations' => $gates->pluck('name', 'id')->all(),
            'products' => FinishedGoodsProduct::query()->active()
                ->orderBy('productname')->pluck('productname', 'productname')->all(),
            'gates' => $gates->mapWithKeys(fn ($g) => [$g->id => [
                'name' => $g->name,
                'factory' => $g->factory?->name ?? '-',
                'factory_id' => $g->factory_id,
            ]])->all(),
        ];
    }

    /** Gate id => its factory's name, for rows the join cannot reach. */
    protected function gateFactory($id): string
    {
        return $this->options()['gates'][(int) $id]['factory'] ?? '-';
    }

    /** Gate ids belonging to a factory — how the factory filter is applied. */
    protected function gateIdsForFactory($factoryId): array
    {
        return array_keys(array_filter(
            $this->options()['gates'],
            fn ($g) => (int) $g['factory_id'] === (int) $factoryId
        ));
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

    /** Only the product filter and the search reach across into `products`. */
    protected function joinedFilterKeys(): array
    {
        return ['product'];
    }

    /**
     * The same rows, counted without the `products` join — a LEFT join cannot
     * change the count. Measured over 2020–2026: 853,948 either way, 4,757ms
     * joined against 1,211ms without. paginate() falls back to the accurate
     * joined count whenever a product filter or search is active.
     */
    protected function countQuery()
    {
        $f = $this->filters;

        $q = DB::connection('bil')->table('factory_exit as e');

        return $this->applyDate($q, 'e.dateofexit', slash: true)
            ->when($f['factory'] ?? '', fn ($q, $v) => $q->whereIn('e.exit_location_id', $this->gateIdsForFactory($v)))
            ->when($f['location'] ?? '', fn ($q, $v) => $q->where('e.exit_location_id', $v));
    }

    /**
     * `dateofexit` is a varchar in Y/m/d form, which sorts correctly as a
     * string, so the range compares directly — as the legacy report did.
     *
     * Via applyDate() rather than a hand-rolled >= / <= pair: only the BETWEEN it
     * produces lets MySQL use the `(dateofexit, id)` index for the ORDER BY too.
     * See the note on applyDate().
     *
     * Gates are filtered by `exit_location_id`, the indexed column the rebuild
     * added and backfilled across all 1.2M rows — including four spellings from
     * 2017 that predate the gate table.
     */
    protected function base()
    {
        $f = $this->filters;

        $q = DB::connection('bil')->table('factory_exit as e')
            ->leftJoin('products as p', 'e.productid', '=', 'p.productid');

        return $this->applyDate($q, 'e.dateofexit', slash: true)
            ->when($f['factory'] ?? '', fn ($q, $v) => $q->whereIn('e.exit_location_id', $this->gateIdsForFactory($v)))
            ->when($f['location'] ?? '', fn ($q, $v) => $q->where('e.exit_location_id', $v))
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
                    ['Factory', 'exit_location_id', fn ($r) => e($this->gateFactory($r->exit_location_id))],
                    ['Location', 'exitlocation'],
                    ['Product Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Bundles', 'bundles'],
                ],
                'searchable' => ['e.barcode', 'p.productname', 'p.productcode', 'e.exitlocation'],
                // Factory is resolved per row from the core connection, so it
                // cannot be sorted on in SQL.
                'sortable' => ['barcode', 'exitlocation', 'productcode', 'productname', 'bundles'],
                'query' => fn () => $this->base()
                    ->select('e.id', 'e.barcode', 'e.exit_location_id', 'e.exitlocation',
                        'p.productcode', 'p.productname', 'e.bundles')
                    // Newest first, ordered ALONG the (dateofexit, id) index
                    // rather than across it — see the same note on the
                    // Conversion Output report. Ordering by `e.id` alone made
                    // MySQL fall back to a backwards primary-key scan of 1.2M
                    // rows whenever the dates were bound rather than literal.
                    ->orderByDesc('e.dateofexit')->orderByDesc('e.id'),
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
                // Both receipt tables are real during the cut-over: gds
                // writes the new one, the legacy app still writes the old.
                $hits = array_flip(array_merge(
                    DB::connection('bil')->table('store_entrance')
                        ->whereIn('barcode', $page)->distinct()->pluck('barcode')->all(),
                    DB::connection('bil')->table('finished_goods_warehouse_receipts')
                        ->whereIn('barcode', $page)->distinct()->pluck('barcode')->all()
                ));
                foreach ($page as $code) {
                    $this->receivedCache[$code] = isset($hits[$code]);
                }

                if (array_key_exists($barcode, $this->receivedCache)) {
                    return $this->receivedCache[$barcode];
                }
            }
        }

        return $this->receivedCache[$barcode] = DB::connection('bil')->table('store_entrance')
            ->where('barcode', $barcode)->exists()
            || DB::connection('bil')->table('finished_goods_warehouse_receipts')
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
