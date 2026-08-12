<?php

namespace Modules\Bil\Livewire\FinishedGoods\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Bil\Models\FactoryConversion;
use Modules\Bil\Models\FinishedGoodsProduct;
use Modules\Core\Models\Factory;
use Modules\Core\Models\MachineLine;

/**
 * BIL → Finished Goods → Reports → Conversion Output. Rebuild of the legacy
 * report_factory_production.php, over factory_conversion (1.2M pallets).
 *
 * The legacy screen offered seven views; the three carried over here are the
 * ones it led with — Default, Summary (by sub-line, product) and Summary (by
 * product) — matching Report\Factory\Production::option1/2/5 column for column.
 * The Calendar X/Y and the two real-time views are deliberately left behind.
 *
 * "Palette" in the legacy column headings is spelled **Pallet** here.
 *
 * Delete mirrors the legacy guard: a pallet can only be removed while it has
 * NOT been received into the store, because store_entrance and everything after
 * it match on the barcode. There is no edit — a pallet's weights come from the
 * product spec, so correcting one means deleting and re-creating it.
 */
#[Title('Conversion Output Report')]
class ConversionOutput extends RawMaterialReport
{
    protected ?array $optCache = null;
    protected ?array $receivedCache = null;

    public function title(): string
    {
        return 'Conversion Output Report';
    }

    public function printKey(): string
    {
        return 'conversion-output';
    }

    public function subtitle(): string
    {
        return 'Pallets produced off the converting lines — by pallet, by line and by product.';
    }

    protected function reportPageKey(): string
    {
        return 'bil.finished_goods.reports.conversion_output';
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
            'factories' => Factory::orderBy('name')->pluck('name', 'name')->all(),
            'lines' => MachineLine::treeOrder()->get()
                ->mapWithKeys(fn ($l) => [
                    ($l->legacy_alias ?: $l->name) => ($l->parent_id ? '— ' : '') . $l->name,
                ])->all(),
            'products' => FinishedGoodsProduct::query()->active()
                ->orderBy('productname')->pluck('productname', 'productname')->all(),
            'shifts' => ['day' => 'Day', 'night' => 'Night'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'factory' => ['label' => 'Factory', 'options' => $o['factories']],
            'line' => ['label' => 'Line', 'options' => $o['lines']],
            'sublinename' => ['label' => 'Sub-Line', 'options' => $o['lines']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
            'shift' => ['label' => 'Shift', 'options' => $o['shifts']],
        ];
    }

    /** Only the product filter and the search reach across into `products`. */
    protected function joinedFilterKeys(): array
    {
        return ['product'];
    }

    /**
     * The same rows, counted without the `products` join.
     *
     * It is a LEFT join, so it cannot add or drop rows and the total is
     * identical — measured over 2020–2026: 855,338 either way, 5,414ms joined
     * against 1,247ms without. paginate() falls back to the accurate joined count
     * on its own whenever a product filter or a search is active (see
     * countNeedsJoins), so this is only used when it is provably equivalent.
     */
    protected function countQuery()
    {
        $f = $this->filters;

        $q = DB::connection('bil')->table('factory_conversion as c');

        return $this->applyDate($q, 'c.dateofproduction', slash: true)
            ->when($f['factory'] ?? '', fn ($q, $v) => $q->where('c.factory', $v))
            ->when($f['line'] ?? '', fn ($q, $v) => $q->where('c.linename', $v))
            ->when($f['sublinename'] ?? '', fn ($q, $v) => $q->where('c.sublinename', $v))
            ->when($f['shift'] ?? '', fn ($q, $v) => $q->where('c.shift', $v));
    }

    /**
     * `dateofproduction` is a varchar in Y/m/d form, which sorts correctly as a
     * string — so the range compares directly, as the legacy report did.
     *
     * Via applyDate() rather than a hand-rolled >= / <= pair: only the BETWEEN it
     * produces lets MySQL use the `(dateofproduction, id)` index for the ORDER BY
     * too. See the note on applyDate().
     */
    protected function base()
    {
        $f = $this->filters;

        $q = DB::connection('bil')->table('factory_conversion as c')
            ->leftJoin('products as p', 'c.productid', '=', 'p.productid');

        return $this->applyDate($q, 'c.dateofproduction', slash: true)
            ->when($f['factory'] ?? '', fn ($q, $v) => $q->where('c.factory', $v))
            ->when($f['line'] ?? '', fn ($q, $v) => $q->where('c.linename', $v))
            ->when($f['sublinename'] ?? '', fn ($q, $v) => $q->where('c.sublinename', $v))
            ->when($f['product'] ?? '', fn ($q, $v) => $q->where('p.productname', $v))
            ->when($f['shift'] ?? '', fn ($q, $v) => $q->where('c.shift', $v))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('c.barcode', 'like', $term)
                  ->orWhere('p.productname', 'like', $term)
                  ->orWhere('p.productcode', 'like', $term)
                  ->orWhere('c.sublinename', 'like', $term);
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
                    ['Factory', 'factory'],
                    ['Line', 'linename'],
                    ['Sub-Line', 'sublinename'],
                    ['Product Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Bundles', 'bundles'],
                ],
                'searchable' => ['c.barcode', 'p.productname', 'p.productcode', 'c.sublinename'],
                'query' => fn () => $this->base()
                    ->select('c.id', 'c.barcode', 'c.factory', 'c.linename', 'c.sublinename',
                        'p.productcode', 'p.productname', 'c.bundles')
                    // Newest first, ordered ALONG the (dateofproduction, id)
                    // index rather than across it.
                    //
                    // Ordering by `c.id` alone looks equivalent — id is
                    // chronological — but it forces MySQL to choose between the
                    // date index and the primary key, and with a bound
                    // `BETWEEN ? AND ?` it plans without knowing the values, so
                    // it cannot tell the range is one day and picks a backwards
                    // PK scan. That walks the whole table: 3,300ms against 3ms,
                    // on the identical rows. Naming both columns leaves one
                    // obviously-best plan whatever the dates turn out to be.
                    ->orderByDesc('c.dateofproduction')->orderByDesc('c.id'),
            ],
            'by_subline_product' => [
                'label' => 'Summary (by sub-line, product)',
                'type' => 'summary',
                'columns' => [
                    ['Line', 'linename'],
                    ['Sub-Line', 'sublinename'],
                    ['Product Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Pallets', 'pallets'],
                    ['Bundles', 'bundles'],
                ],
                'searchable' => ['p.productname', 'p.productcode', 'c.sublinename'],
                'query' => fn () => $this->base()
                    ->selectRaw('c.linename, c.sublinename, p.productcode, p.productname,
                                 COUNT(c.barcode) as pallets, SUM(c.bundles) as bundles')
                    ->groupBy('c.linename', 'c.sublinename', 'p.productcode', 'p.productname')
                    ->orderBy('c.linename')->orderBy('p.productname'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Product Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Pallets', 'pallets'],
                    ['Bundles', 'bundles'],
                    ['Weight (kg)', 'grossweight'],
                ],
                'searchable' => ['p.productname', 'p.productcode'],
                // Weights are stored in grams, hence the /1000 — same as legacy.
                'query' => fn () => $this->base()
                    ->selectRaw('p.productcode, p.productname, COUNT(c.barcode) as pallets,
                                 SUM(c.bundles) as bundles,
                                 ROUND(SUM(c.grossweight) / 1000, 2) as grossweight')
                    ->groupBy('p.productcode', 'p.productname')
                    ->orderBy('p.productname'),
            ],
        ];
    }

    /**
     * Has this pallet been received into the store?
     *
     * The page's barcodes are prefetched in one query on first use, so a normal
     * render costs a single lookup. Anything NOT on that page — notably the
     * server-side re-check when a delete is confirmed — falls through to its own
     * query rather than being assumed absent: a cache miss must not read as
     * "not received" and quietly permit the delete.
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
                    DB::connection('core')->table('finished_goods_warehouse_receipts')
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
            || DB::connection('core')->table('finished_goods_warehouse_receipts')
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

    /** A pallet that reached the store can't be deleted — everything downstream
     *  matches on its barcode. Mirrors the legacy Production::_delete check. */
    public function deleteGuard($row): ?string
    {
        return $this->isReceived((string) ($row->barcode ?? ''))
            ? 'Already received into the store — cannot delete.'
            : null;
    }

    /* ---------------- Delete ---------------- */

    protected function findRow(int $id)
    {
        return FactoryConversion::find($id);
    }

    /**
     * Remove the pallet. The guard has already refused anything the store has
     * received, so the only downstream row that can still exist is a factory
     * exit — dropped with it, so a re-created pallet can be scanned out again
     * (`factory_exit.barcode` is UNIQUE).
     */
    protected function performDelete(int $id): void
    {
        $pallet = FactoryConversion::find($id);
        if (! $pallet) {
            return;
        }

        DB::connection('bil')->transaction(function () use ($pallet) {
            DB::connection('bil')->table('factory_exit')
                ->where('barcode', $pallet->barcode)->delete();
            $pallet->delete();
        });

        // The page's cached "received" answers describe rows that may no longer
        // exist.
        $this->receivedCache = null;
    }
}
