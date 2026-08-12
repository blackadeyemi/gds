<?php

namespace Modules\Bil\Livewire\FinishedGoods\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Bil\Models\FinishedGoodsProduct;
use Modules\Core\Models\Factory;
use Modules\Core\Models\MachineLine;

/**
 * BIL → Finished Goods → Reports → Factory Floor Stock.
 *
 * Pallets that have been made but have not left the factory: rows in
 * `factory_conversion` whose `status` is still NULL. Factory Exit sets it to
 * 'yes' when the pallet passes the gate, so NULL means "still on the floor".
 *
 * The other half of the stock picture. Warehouse Stock counts what is in a
 * warehouse; this counts what is made and waiting to get there. A pallet is in
 * exactly one of the two, which is why they are separate screens rather than
 * one total.
 *
 * NO DATE RANGE — this is a snapshot of what is on the floor right now, not a
 * period. `dateofproduction` is offered as a FILTER instead, and the age in days
 * is the column that actually matters: a pallet sitting for three months is the
 * thing this report exists to surface. Sorted oldest first by default.
 *
 * Read-only. Nothing here is edited — a pallet leaves the floor by being
 * scanned at Factory Exit, which is where the correction belongs.
 */
#[Title('Factory Floor Stock')]
class FactoryFloorStock extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Factory Floor Stock';
    }

    public function printKey(): string
    {
        return 'factory-floor-stock';
    }

    public function subtitle(): string
    {
        return 'Pallets made but not yet sent to a warehouse — still on the factory floor.';
    }

    /** A snapshot, not a period: what is on the floor is what is on the floor. */
    public function hasDateRange(): bool
    {
        return false;
    }

    public function readOnly(): bool
    {
        return true;
    }

    protected function reportPageKey(): string
    {
        return 'bil.finished_goods.reports.factory_floor_stock';
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
            'ages' => [
                '7' => 'Over 7 days',
                '14' => 'Over 14 days',
                '30' => 'Over 30 days',
                '60' => 'Over 60 days',
            ],
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
            'age' => ['label' => 'Age', 'options' => $o['ages']],
        ];
    }

    /**
     * `status IS NULL` is the whole definition of "on the floor".
     *
     * `dateofproduction` is a legacy `Y/m/d` varchar, so the age filter compares
     * against a formatted cut-off rather than doing date arithmetic in SQL.
     */
    protected function base()
    {
        $f = $this->filters;

        return DB::connection('bil')->table('factory_conversion as c')
            ->leftJoin('products as p', 'c.productid', '=', 'p.productid')
            ->whereNull('c.status')
            ->when($f['factory'] ?? '', fn ($q, $v) => $q->where('c.factory', $v))
            ->when($f['line'] ?? '', fn ($q, $v) => $q->where('c.linename', $v))
            ->when($f['sublinename'] ?? '', fn ($q, $v) => $q->where('c.sublinename', $v))
            ->when($f['product'] ?? '', fn ($q, $v) => $q->where('p.productname', $v))
            ->when($f['age'] ?? '', fn ($q, $v) => $q->where(
                'c.dateofproduction', '<=', now()->subDays((int) $v)->format('Y/m/d')
            ))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('c.barcode', 'like', $term)
                  ->orWhere('p.productname', 'like', $term)
                  ->orWhere('p.productcode', 'like', $term)
                  ->orWhere('c.sublinename', 'like', $term);
            }));
    }

    /** Whole days since a legacy `Y/m/d` production date. */
    protected function ageInDays(?string $date): ?int
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }

        try {
            return (int) \Illuminate\Support\Carbon::createFromFormat('Y/m/d', $date)
                ->startOfDay()->diffInDays(now()->startOfDay());
        } catch (\Throwable) {
            return null;
        }
    }

    public function views(): array
    {
        $age = function ($r) {
            $days = $this->ageInDays($r->dateofproduction ?? null);
            if ($days === null) {
                return '—';
            }

            // Anything sitting a fortnight has been forgotten about.
            $class = match (true) {
                $days >= 30 => 'badge-danger',
                $days >= 14 => 'badge-muted',
                default => 'badge-success',
            };

            return '<span class="badge ' . $class . '">' . $days . 'd</span>';
        };

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
                    $this->dateCol('Produced', 'dateofproduction', 'Y/m/d'),
                    ['Age', 'age', $age],
                ],
                'searchable' => ['c.barcode', 'p.productname', 'p.productcode', 'c.sublinename'],
                // Age is derived in PHP; it sorts by the date it comes from.
                'sortable' => ['barcode', 'factory', 'linename', 'sublinename',
                    'productcode', 'productname', 'bundles', 'dateofproduction'],
                'query' => fn () => $this->base()
                    ->select('c.id', 'c.barcode', 'c.factory', 'c.linename', 'c.sublinename',
                        'p.productcode', 'p.productname', 'c.bundles', 'c.dateofproduction')
                    // Oldest first: the point of this screen is what has been
                    // sitting too long, not what was made this morning.
                    ->orderBy('c.dateofproduction'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Product Code', 'productcode'],
                    ['Product', 'productname'],
                    ['Pallets', 'pallets'],
                    ['Bundles', 'bundles'],
                    ['Oldest', 'oldest'],
                ],
                'searchable' => ['p.productname', 'p.productcode'],
                'query' => fn () => $this->base()
                    ->selectRaw('p.productcode, p.productname, COUNT(c.barcode) as pallets,
                                 SUM(c.bundles) as bundles, MIN(c.dateofproduction) as oldest')
                    ->groupBy('p.productcode', 'p.productname')
                    ->orderByDesc(DB::raw('SUM(c.bundles)')),
            ],
            'by_line' => [
                'label' => 'Summary (by line)',
                'type' => 'summary',
                'columns' => [
                    ['Factory', 'factory'],
                    ['Line', 'linename'],
                    ['Sub-Line', 'sublinename'],
                    ['Pallets', 'pallets'],
                    ['Bundles', 'bundles'],
                    ['Oldest', 'oldest'],
                ],
                'searchable' => ['c.sublinename'],
                'query' => fn () => $this->base()
                    ->selectRaw('c.factory, c.linename, c.sublinename, COUNT(c.barcode) as pallets,
                                 SUM(c.bundles) as bundles, MIN(c.dateofproduction) as oldest')
                    ->groupBy('c.factory', 'c.linename', 'c.sublinename')
                    ->orderBy('c.factory')->orderBy('c.linename'),
            ],
        ];
    }
}
