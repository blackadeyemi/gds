<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Bil\Livewire\RawMaterials\DamagedGoods;
use Modules\Bil\Livewire\RawMaterials\FactoryEntrance;
use Modules\Bil\Livewire\RawMaterials\Consumption;
use Modules\Bil\Livewire\RawMaterials\FactoryReturns;
use Modules\Bil\Livewire\RawMaterials\Products;
use Modules\Bil\Livewire\RawMaterials\Statistics;
use Modules\Bil\Livewire\RawMaterials\StockTransfer;
use Modules\Bil\Livewire\RawMaterials\Reports\Consumption as ConsumptionReport;
use Modules\Bil\Livewire\RawMaterials\Reports\DamagedGoods as DamagedGoodsReport;
use Modules\Bil\Livewire\RawMaterials\Reports\FactoryEntrance as FactoryEntranceReport;
use Modules\Bil\Livewire\RawMaterials\Reports\FactoryFloorStock;
use Modules\Bil\Livewire\RawMaterials\Reports\SupplierDeliveries as SupplierDeliveriesReport;
use Modules\Bil\Livewire\RawMaterials\Reports\WarehouseEntry as WarehouseEntryReport;
use Modules\Bil\Livewire\RawMaterials\Reports\WarehouseExit as WarehouseExitReport;
use Modules\Bil\Livewire\RawMaterials\Reports\WarehouseStock;
use Modules\Bil\Livewire\RawMaterials\SupplierDeliveries;
use Modules\Bil\Livewire\RawMaterials\Suppliers;
use Modules\Bil\Livewire\RawMaterials\WarehouseEntry;
use Modules\Bil\Livewire\RawMaterials\WarehouseExit;

/*
| BIL module routes — factory, raw materials, sales, store, quality.
| Served under the /bil URL prefix (see ModuleServiceProvider).
| Pages are added here as they are rebuilt from production screenshots.
*/

/*
| Raw Materials — the flow of raw material from supplier, through the
| warehouse, into the factory and back. Gated by the `view-raw-materials`
| permission (Raw Materials application module). Products is live; the rest
| are placeholders until each is rebuilt from its legacy page.
*/
Route::middleware(['auth', 'can:view-raw-materials'])
    ->prefix('raw-materials')->name('raw-materials.')
    ->group(function () {
        Route::get('/statistics', Statistics::class)->name('statistics');
        // Direct download of the current statistics section as xlsx/csv/pdf.
        Route::get('/statistics/export', function () {
            $format = strtolower((string) request('format', 'xlsx'));
            abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

            $c = new Statistics();
            $c->section = (string) request('section', '');
            $c->range = (string) request('range', '30d');

            return $c->exportResponse($format);
        })->name('statistics.export');
        Route::get('/products', Products::class)->name('products');
        Route::get('/suppliers', Suppliers::class)->name('suppliers');
        Route::get('/supplier-deliveries', SupplierDeliveries::class)->name('supplier-deliveries');

        // Label print for the barcodes just generated (ids held in session).
        Route::get('/supplier-deliveries/print', function () {
            $ids = array_map('intval', session('rm_delivery_print_ids', []));
            abort_if($ids === [], 404);

            $rows = DB::connection('bil')->table('rawmaterials_supplier_deliveries as c')
                ->leftJoin('rawmaterials_products as p', 'c.productid', '=', 'p.id')
                ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
                ->whereIn('c.id', $ids)
                ->orderByRaw('FIELD(c.id, ' . implode(',', $ids) . ')')
                ->get(['c.barcode', 'c.weight', 'c.suppliercode', 'p.storecode', 'p.productname', 'g.groupcode']);

            return view('bil::print.delivery-barcodes', ['rows' => $rows]);
        })->name('supplier-deliveries.print');

        Route::get('/warehouse-entry', WarehouseEntry::class)->name('warehouse-entry');
        Route::get('/warehouse-exit', WarehouseExit::class)->name('warehouse-exit');
        Route::get('/stock-transfer', StockTransfer::class)->name('stock-transfer');
        Route::get('/factory-entrance', FactoryEntrance::class)->name('factory-entrance');
        Route::get('/consumption', Consumption::class)->name('consumption');
        Route::get('/factory-returns', FactoryReturns::class)->name('factory-returns');
        Route::get('/damaged-goods', DamagedGoods::class)->name('damaged-goods');

        // Reports — sub-menu of raw-materials reports (flow order).
        Route::prefix('reports')->name('reports.')->group(function () {
            // Printable HTML for a report's current view/filters (opens in a new
            // tab and auto-prints; the browser's print dialog can Save as PDF).
            Route::get('/{report}/print', function (string $report) {
                $map = [
                    'supplier-deliveries' => SupplierDeliveriesReport::class,
                    'warehouse-entry' => WarehouseEntryReport::class,
                    'warehouse-exit' => WarehouseExitReport::class,
                    'factory-entrance' => FactoryEntranceReport::class,
                    'consumption' => ConsumptionReport::class,
                    'warehouse-stock' => WarehouseStock::class,
                    'factory-floor-stock' => FactoryFloorStock::class,
                    'damaged-goods' => DamagedGoodsReport::class,
                ];
                abort_unless(isset($map[$report]), 404);

                $c = new $map[$report]();
                $c->view = (string) request('view', '');
                $c->search = (string) request('search', '');
                $c->dateFrom = (string) request('dateFrom', now()->format('Y-m-d'));
                $c->dateTo = (string) request('dateTo', now()->format('Y-m-d'));
                $c->filters = array_map('strval', (array) request('filters', []));

                return view('core::print.grid', $c->reportPayload());
            })->name('print');

            // Direct file download (xlsx/csv/pdf) for a report's current
            // view/filters. A real HTTP download (Content-Disposition:
            // attachment) — unlike a Livewire wire:click, which base64-encodes
            // the whole file into the JSON response (breaks large exports and
            // trips some browsers' PDF viewers into a blank/black page).
            Route::get('/{report}/download', function (string $report) {
                $map = [
                    'supplier-deliveries' => SupplierDeliveriesReport::class,
                    'warehouse-entry' => WarehouseEntryReport::class,
                    'warehouse-exit' => WarehouseExitReport::class,
                    'factory-entrance' => FactoryEntranceReport::class,
                    'consumption' => ConsumptionReport::class,
                    'warehouse-stock' => WarehouseStock::class,
                    'factory-floor-stock' => FactoryFloorStock::class,
                    'damaged-goods' => DamagedGoodsReport::class,
                ];
                abort_unless(isset($map[$report]), 404);

                $format = strtolower((string) request('format', 'xlsx'));
                abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

                $c = new $map[$report]();
                $c->view = (string) request('view', '');
                $c->search = (string) request('search', '');
                $c->dateFrom = (string) request('dateFrom', now()->format('Y-m-d'));
                $c->dateTo = (string) request('dateTo', now()->format('Y-m-d'));
                $c->filters = array_map('strval', (array) request('filters', []));

                return $c->export($format);
            })->name('download');

            Route::get('/supplier-deliveries', SupplierDeliveriesReport::class)->name('supplier-deliveries');
            Route::get('/warehouse-entry', WarehouseEntryReport::class)->name('warehouse-entry');
            Route::get('/warehouse-exit', WarehouseExitReport::class)->name('warehouse-exit');
            Route::get('/factory-entrance', FactoryEntranceReport::class)->name('factory-entrance');
            Route::get('/consumption', ConsumptionReport::class)->name('consumption');
            Route::get('/warehouse-stock', WarehouseStock::class)->name('warehouse-stock');
            Route::get('/factory-floor-stock', FactoryFloorStock::class)->name('factory-floor-stock');
            Route::get('/damaged-goods', DamagedGoodsReport::class)->name('damaged-goods');
        });
    });
