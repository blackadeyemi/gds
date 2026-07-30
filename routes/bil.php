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
use Modules\Bil\Livewire\RawMaterials\Reports\FactoryReturns as FactoryReturnsReport;
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
| warehouse, into the factory and back. Each route is gated per page by the
| `page:{key}` middleware (page keys mirror the route names); export/print
| helpers inherit the page they belong to.
*/
Route::middleware('auth')
    ->prefix('raw-materials')->name('raw-materials.')
    ->group(function () {
        Route::get('/statistics', Statistics::class)
            ->middleware('page:bil.raw_materials.statistics')->name('statistics');
        // Direct download of the current statistics section as xlsx/csv/pdf.
        Route::get('/statistics/export', function () {
            $format = strtolower((string) request('format', 'xlsx'));
            abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

            $c = new Statistics();
            $c->section = (string) request('section', '');
            $c->range = (string) request('range', '30d');

            return $c->exportResponse($format);
        })->middleware('page:bil.raw_materials.statistics')->name('statistics.export');
        Route::get('/products', Products::class)
            ->middleware('page:bil.raw_materials.products')->name('products');
        Route::get('/suppliers', Suppliers::class)
            ->middleware('page:bil.raw_materials.suppliers')->name('suppliers');
        Route::get('/supplier-deliveries', SupplierDeliveries::class)
            ->middleware('page:bil.raw_materials.supplier_deliveries')->name('supplier-deliveries');

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
        })->middleware('page:bil.raw_materials.supplier_deliveries')->name('supplier-deliveries.print');

        Route::get('/warehouse-entry', WarehouseEntry::class)
            ->middleware('page:bil.raw_materials.warehouse_entry')->name('warehouse-entry');
        Route::get('/warehouse-exit', WarehouseExit::class)
            ->middleware('page:bil.raw_materials.warehouse_exit')->name('warehouse-exit');
        Route::get('/stock-transfer', StockTransfer::class)
            ->middleware('page:bil.raw_materials.stock_transfer')->name('stock-transfer');
        Route::get('/factory-entrance', FactoryEntrance::class)
            ->middleware('page:bil.raw_materials.factory_entrance')->name('factory-entrance');
        Route::get('/consumption', Consumption::class)
            ->middleware('page:bil.raw_materials.consumption')->name('consumption');
        Route::get('/factory-returns', FactoryReturns::class)
            ->middleware('page:bil.raw_materials.factory_returns')->name('factory-returns');

        // Reprint a CODE93 label for a returned item (esp. the new child barcode
        // a partial return creates). Barcode(s) via ?barcode=A or ?barcode=A,B —
        // resolved against the in-store warehouse row, rendered as store labels.
        Route::get('/factory-returns/print', function () {
            $barcodes = array_values(array_filter(array_map('trim', explode(',', (string) request('barcode', '')))));
            abort_if($barcodes === [], 404);

            $rows = DB::connection('bil')->table('rawmaterials_warehouse_entry as w')
                ->leftJoin('rawmaterials_products as p', 'w.productid', '=', 'p.id')
                ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
                ->whereIn('w.barcode', $barcodes)
                ->whereNull('w.status') // the unit currently in store
                ->orderByDesc('w.id')
                ->get(['w.barcode', 'w.weight', 'w.suppliercode', 'p.storecode', 'p.productname', 'g.groupcode'])
                ->unique('barcode')->values();
            abort_if($rows->isEmpty(), 404);

            return view('bil::print.delivery-barcodes', ['rows' => $rows]);
        })->middleware('page:bil.raw_materials.factory_returns')->name('factory-returns.print');

        Route::get('/damaged-goods', DamagedGoods::class)
            ->middleware('page:bil.raw_materials.damaged_goods')->name('damaged-goods');

        // Reports — sub-menu of raw-materials reports (flow order).
        Route::prefix('reports')->name('reports.')->group(function () {
            // Printable HTML for a report's current view/filters (opens in a new
            // tab and auto-prints; the browser's print dialog can Save as PDF).
            // The {report} slug maps to its report page key for access control.
            Route::get('/{report}/print', function (string $report) {
                $map = [
                    'supplier-deliveries' => SupplierDeliveriesReport::class,
                    'warehouse-entry' => WarehouseEntryReport::class,
                    'warehouse-exit' => WarehouseExitReport::class,
                    'factory-entrance' => FactoryEntranceReport::class,
                    'consumption' => ConsumptionReport::class,
                    'warehouse-stock' => WarehouseStock::class,
                    'factory-floor-stock' => FactoryFloorStock::class,
                    'factory-returns' => FactoryReturnsReport::class,
                    'damaged-goods' => DamagedGoodsReport::class,
                ];
                abort_unless(isset($map[$report]), 404);
                abort_unless((bool) request()->user()?->canAccessPage('bil.raw_materials.reports.' . str_replace('-', '_', $report)), 403);

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
                    'factory-returns' => FactoryReturnsReport::class,
                    'damaged-goods' => DamagedGoodsReport::class,
                ];
                abort_unless(isset($map[$report]), 404);
                abort_unless((bool) request()->user()?->canAccessPage('bil.raw_materials.reports.' . str_replace('-', '_', $report)), 403);

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

            Route::get('/supplier-deliveries', SupplierDeliveriesReport::class)
                ->middleware('page:bil.raw_materials.reports.supplier_deliveries')->name('supplier-deliveries');
            Route::get('/warehouse-entry', WarehouseEntryReport::class)
                ->middleware('page:bil.raw_materials.reports.warehouse_entry')->name('warehouse-entry');
            Route::get('/warehouse-exit', WarehouseExitReport::class)
                ->middleware('page:bil.raw_materials.reports.warehouse_exit')->name('warehouse-exit');
            Route::get('/factory-entrance', FactoryEntranceReport::class)
                ->middleware('page:bil.raw_materials.reports.factory_entrance')->name('factory-entrance');
            Route::get('/consumption', ConsumptionReport::class)
                ->middleware('page:bil.raw_materials.reports.consumption')->name('consumption');
            Route::get('/warehouse-stock', WarehouseStock::class)
                ->middleware('page:bil.raw_materials.reports.warehouse_stock')->name('warehouse-stock');
            Route::get('/factory-floor-stock', FactoryFloorStock::class)
                ->middleware('page:bil.raw_materials.reports.factory_floor_stock')->name('factory-floor-stock');
            Route::get('/factory-returns', FactoryReturnsReport::class)
                ->middleware('page:bil.raw_materials.reports.factory_returns')->name('factory-returns');
            Route::get('/damaged-goods', DamagedGoodsReport::class)
                ->middleware('page:bil.raw_materials.reports.damaged_goods')->name('damaged-goods');
        });
    });
