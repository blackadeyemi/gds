<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Bil\Livewire\FinishedGoods\ConversionOutput;
use Modules\Bil\Livewire\FinishedGoods\ConversionWaste;
use Modules\Bil\Livewire\FinishedGoods\StockTransfer as FgStockTransfer;
use Modules\Bil\Livewire\FinishedGoods\StockTransferReceive;
use Modules\Bil\Livewire\FinishedGoods\Statistics as FgStatistics;
use Modules\Bil\Livewire\FinishedGoods\FactoryExit;
use Modules\Bil\Livewire\FinishedGoods\Reports\ConversionOutput as ConversionOutputReport;
use Modules\Bil\Livewire\FinishedGoods\Reports\ConversionWaste as ConversionWasteReport;
use Modules\Bil\Livewire\FinishedGoods\Reports\StockTransfer as StockTransferReport;
use Modules\Bil\Livewire\FinishedGoods\Reports\FactoryExit as FactoryExitReport;
use Modules\Bil\Livewire\FinishedGoods\Reports\FactoryFloorStock as FgFactoryFloorStock;
use Modules\Bil\Livewire\FinishedGoods\Reports\WarehouseEntrance as WarehouseEntranceReport;
use Modules\Bil\Livewire\FinishedGoods\WarehouseEntrance;
use Modules\Bil\Livewire\FinishedGoods\Products as FinishedGoodsProducts;
use Modules\Bil\Livewire\FinishedGoods\Stock as FinishedGoodsStockPage;
use Modules\Bil\Livewire\JumboRolls\Consumption as JumboRollConsumption;
use Modules\Bil\Livewire\JumboRolls\FactoryEntrance as JumboRollFactoryEntrance;
use Modules\Bil\Livewire\JumboRolls\Reports\Consumption as JumboRollConsumptionReport;
use Modules\Bil\Livewire\JumboRolls\Reports\FactoryEntrance as JumboRollFactoryEntranceReport;
use Modules\Bil\Livewire\JumboRolls\Reports\Returns as JumboRollReturnsReport;
use Modules\Bil\Livewire\JumboRolls\Returns as JumboRollReturns;
use Modules\Bil\Livewire\JumboRolls\Statistics as JumboRollStatistics;
use Modules\Bil\Livewire\JumboRolls\Stock as JumboRollStock;
use Modules\Bil\Livewire\Machines\Lines as MachineLines;
use Modules\Bil\Livewire\Machines\Projects as MachineProjects;
use Modules\Bil\Livewire\Machines\Reports\ConversionHistory;
use Modules\Bil\Livewire\Machines\Reports\Services as ServicesReport;
use Modules\Bil\Livewire\Machines\ConversionSetup;
use Modules\Bil\Livewire\Machines\Services as MachineServices;
use Modules\Bil\Livewire\Machines\Statistics as MachineStatistics;
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
use Modules\Bil\Livewire\Sales\Customers as SalesCustomers;
use Modules\Bil\Livewire\Sales\Loading as SalesLoadingPage;
use Modules\Bil\Livewire\Sales\Orders as SalesOrders;
use Modules\Bil\Livewire\Sales\Transporters as SalesTransporters;

/*
| BIL module routes — factory, raw materials, sales, store, quality.
| Served under the /bil URL prefix (see ModuleServiceProvider).
| Pages are added here as they are rebuilt from production screenshots.
*/

/*
| Finished Goods — the products BIL makes, starting with the QC-controlled
| product specifications.
*/
Route::middleware('auth')
    ->prefix('finished-goods')->name('finished-goods.')
    ->group(function () {
        Route::get('/products', FinishedGoodsProducts::class)
            ->middleware('page:bil.finished_goods.products')->name('products');
        Route::get('/conversion-output', ConversionOutput::class)
            ->middleware('page:bil.finished_goods.conversion_output')->name('conversion-output');
        Route::get('/conversion-waste', ConversionWaste::class)
            ->middleware('page:bil.finished_goods.conversion_waste')->name('conversion-waste');

        Route::get('/statistics', FgStatistics::class)
            ->middleware('page:bil.finished_goods.statistics')->name('statistics');
        // Direct download of the current statistics section as xlsx/csv/pdf.
        Route::get('/statistics/export', function () {
            abort_unless((bool) request()->user()?->canDo('bil.finished_goods.statistics', 'export'), 403);

            $format = strtolower((string) request('format', 'xlsx'));
            abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

            $c = new FgStatistics();
            $c->section = (string) request('section', '');
            $c->range = (string) request('range', '30d');
            $c->figures = (string) request('figures', 'rounded');

            return $c->exportResponse($format);
        })->middleware('page:bil.finished_goods.statistics')->name('statistics.export');

        Route::get('/stock-transfer', FgStockTransfer::class)
            ->middleware('page:bil.finished_goods.stock_transfer')->name('stock-transfer');
        Route::get('/stock-transfer/receive', StockTransferReceive::class)
            ->middleware('page:bil.finished_goods.stock_transfer_receive')->name('stock-transfer.receive');
        Route::get('/factory-exit', FactoryExit::class)
            ->middleware('page:bil.finished_goods.factory_exit')->name('factory-exit');
        Route::get('/warehouse-entrance', WarehouseEntrance::class)
            ->middleware('page:bil.finished_goods.warehouse_entrance')->name('warehouse-entrance');
        Route::get('/warehouse-stock', FinishedGoodsStockPage::class)
            ->middleware('page:bil.finished_goods.warehouse_stock')->name('warehouse-stock');

        // Labels for the pallets just created (ids held in session).
        Route::get('/conversion-output/print', function () {
            $ids = array_map('intval', session('fg_conversion_print_ids', []));
            abort_if($ids === [], 404);

            $rows = DB::connection('bil')->table('factory_conversion as c')
                ->leftJoin('products as p', 'c.productid', '=', 'p.productid')
                ->whereIn('c.id', $ids)
                ->orderByRaw('FIELD(c.id, ' . implode(',', $ids) . ')')
                ->get(['c.barcode', 'c.bundles', 'c.factory', 'c.linename', 'c.sublinename',
                       'p.productname', 'p.productcode']);

            return view('bil::print.conversion-barcodes', ['rows' => $rows]);
        })->middleware('page:bil.finished_goods.conversion_output')->name('conversion-output.print');

        // Reports — same print/download contract as the other report groups,
        // gated on bil.finished_goods.reports.* keys.
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/conversion-output', ConversionOutputReport::class)
                ->middleware('page:bil.finished_goods.reports.conversion_output')->name('conversion-output');
            Route::get('/factory-exit', FactoryExitReport::class)
                ->middleware('page:bil.finished_goods.reports.factory_exit')->name('factory-exit');
            Route::get('/warehouse-entrance', WarehouseEntranceReport::class)
                ->middleware('page:bil.finished_goods.reports.warehouse_entrance')->name('warehouse-entrance');
            Route::get('/factory-floor-stock', FgFactoryFloorStock::class)
                ->middleware('page:bil.finished_goods.reports.factory_floor_stock')->name('factory-floor-stock');
            Route::get('/conversion-waste', ConversionWasteReport::class)
                ->middleware('page:bil.finished_goods.reports.conversion_waste')->name('conversion-waste');
            Route::get('/stock-transfer', StockTransferReport::class)
                ->middleware('page:bil.finished_goods.reports.stock_transfer')->name('stock-transfer');

            $reports = [
                'conversion-output' => ConversionOutputReport::class,
                'factory-exit' => FactoryExitReport::class,
                'warehouse-entrance' => WarehouseEntranceReport::class,
                'factory-floor-stock' => FgFactoryFloorStock::class,
                'conversion-waste' => ConversionWasteReport::class,
                'stock-transfer' => StockTransferReport::class,
            ];

            $hydrate = function (string $class) {
                $c = new $class();
                $c->view = (string) request('view', '');
                $c->search = (string) request('search', '');
                $c->dateFrom = (string) request('dateFrom', now()->format('Y-m-d'));
                $c->dateTo = (string) request('dateTo', now()->format('Y-m-d'));
                $c->filters = array_map('strval', (array) request('filters', []));

                return $c;
            };

            Route::get('/{report}/print', function (string $report) use ($reports, $hydrate) {
                abort_unless(isset($reports[$report]), 404);
                abort_unless((bool) request()->user()?->canDo('bil.finished_goods.reports.' . str_replace('-', '_', $report), 'export'), 403);

                return view('core::print.grid', $hydrate($reports[$report])->reportPayload());
            })->name('print');

            Route::get('/{report}/download', function (string $report) use ($reports, $hydrate) {
                abort_unless(isset($reports[$report]), 404);
                abort_unless((bool) request()->user()?->canDo('bil.finished_goods.reports.' . str_replace('-', '_', $report), 'export'), 403);

                $format = strtolower((string) request('format', 'xlsx'));
                abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

                return $hydrate($reports[$report])->export($format);
            })->name('download');
        });
    });

/*
| Sales — the order-to-delivery chain for finished goods: the customer's order,
| then loading, delivery and the paperwork that follows. Built on the legacy
| `sales_*` tables, which the legacy screens still read, so the write contracts
| in Modules\Bil\Models\SalesOrder are load-bearing.
*/
Route::middleware('auth')
    ->prefix('sales')->name('sales.')
    ->group(function () {
        Route::get('/customers', SalesCustomers::class)
            ->middleware('page:bil.sales.customers')->name('customers');
        Route::get('/orders', SalesOrders::class)
            ->middleware('page:bil.sales.orders')->name('orders');
        Route::get('/loading', SalesLoadingPage::class)
            ->middleware('page:bil.sales.loading')->name('loading');

        /*
        | The loading print-out. Takes a comma-separated list of barcodes so a
        | day's loads print in one document — the legacy screen made you open a
        | browser tab per load, which is what the cageroom is used to but not
        | what it wanted.
        */
        Route::get('/loading/print', function () {
            $barcodes = array_values(array_filter(array_map(
                'trim', explode(',', (string) request('loads', ''))
            )));

            abort_if($barcodes === [], 404);
            // A runaway list would build an unprintable document and a very
            // large query; a day is never near this.
            abort_if(count($barcodes) > 100, 422, 'Too many loads in one print run.');

            $loads = \Modules\Bil\Support\SalesLoadings::printouts($barcodes);
            abort_if($loads === [], 404);

            return view('bil::print.loading', ['loads' => $loads]);
        })->middleware('page:bil.sales.loading')->name('loading.print');
        Route::get('/transporters', SalesTransporters::class)
            ->middleware('page:bil.sales.transporters')->name('transporters');
    });

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
            abort_unless((bool) request()->user()?->canDo('bil.raw_materials.statistics', 'export'), 403);

            $format = strtolower((string) request('format', 'xlsx'));
            abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

            $c = new Statistics();
            $c->section = (string) request('section', '');
            $c->range = (string) request('range', '30d');
            $c->figures = (string) request('figures', 'rounded');

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
                abort_unless((bool) request()->user()?->canDo('bil.raw_materials.reports.' . str_replace('-', '_', $report), 'export'), 403);

                $c = new $map[$report]();
                $c->view = (string) request('view', '');
                $c->search = (string) request('search', '');
                $c->dateFrom = (string) request('dateFrom', now()->format('Y-m-d'));
                $c->dateTo = (string) request('dateTo', now()->format('Y-m-d'));
                $c->filters = array_map('strval', (array) request('filters', []));
                // A drill-down export: same filters, but the open group's records.
                $c->detailMode = (bool) request('detail', false);
                $c->detailKey = request('detailKey') !== null ? (string) request('detailKey') : null;
                $c->detailSearch = (string) request('detailSearch', '');

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
                abort_unless((bool) request()->user()?->canDo('bil.raw_materials.reports.' . str_replace('-', '_', $report), 'export'), 403);

                $format = strtolower((string) request('format', 'xlsx'));
                abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

                $c = new $map[$report]();
                $c->view = (string) request('view', '');
                $c->search = (string) request('search', '');
                $c->dateFrom = (string) request('dateFrom', now()->format('Y-m-d'));
                $c->dateTo = (string) request('dateTo', now()->format('Y-m-d'));
                $c->filters = array_map('strval', (array) request('filters', []));
                // A drill-down export: same filters, but the open group's records.
                $c->detailMode = (bool) request('detail', false);
                $c->detailKey = request('detailKey') !== null ? (string) request('detailKey') : null;
                $c->detailSearch = (string) request('detailSearch', '');

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

/*
| Jumbo Rolls — the reels BPL makes for BIL, followed from the factory gate
| inwards. BPL's own Jumbo Rolls module covers making and selling them; this
| one covers receiving and using them.
*/
Route::middleware('auth')
    ->prefix('jumbo-rolls')->name('jumbo-rolls.')
    ->group(function () {
        Route::get('/factory-entrance', JumboRollFactoryEntrance::class)
            ->middleware('page:bil.jumbo_rolls.factory_entrance')->name('factory-entrance');
        Route::get('/consumption', JumboRollConsumption::class)
            ->middleware('page:bil.jumbo_rolls.consumption')->name('consumption');
        Route::get('/returns', JumboRollReturns::class)
            ->middleware('page:bil.jumbo_rolls.returns')->name('returns');
        Route::get('/stock', JumboRollStock::class)
            ->middleware('page:bil.jumbo_rolls.stock')->name('stock');

        Route::get('/statistics', JumboRollStatistics::class)
            ->middleware('page:bil.jumbo_rolls.statistics')->name('statistics');
        // Direct download of the current statistics section as xlsx/csv/pdf.
        Route::get('/statistics/export', function () {
            abort_unless((bool) request()->user()?->canDo('bil.jumbo_rolls.statistics', 'export'), 403);

            $format = strtolower((string) request('format', 'xlsx'));
            abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

            $c = new JumboRollStatistics();
            $c->section = (string) request('section', '');
            $c->range = (string) request('range', '30d');
            $c->figures = (string) request('figures', 'rounded');

            return $c->exportResponse($format);
        })->middleware('page:bil.jumbo_rolls.statistics')->name('statistics.export');

        // Print / download for the report-framework pages in this group, on the
        // same contract as the other modules: slug => [component, page key].
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/factory-entrance', JumboRollFactoryEntranceReport::class)
                ->middleware('page:bil.jumbo_rolls.reports.factory_entrance')->name('factory-entrance');
            Route::get('/consumption', JumboRollConsumptionReport::class)
                ->middleware('page:bil.jumbo_rolls.reports.consumption')->name('consumption');
            Route::get('/returns', JumboRollReturnsReport::class)
                ->middleware('page:bil.jumbo_rolls.reports.returns')->name('returns');

            $reports = [
                'stock' => [JumboRollStock::class, 'bil.jumbo_rolls.stock'],
                'factory-entrance' => [JumboRollFactoryEntranceReport::class, 'bil.jumbo_rolls.reports.factory_entrance'],
                'consumption' => [JumboRollConsumptionReport::class, 'bil.jumbo_rolls.reports.consumption'],
                'returns' => [JumboRollReturnsReport::class, 'bil.jumbo_rolls.reports.returns'],
            ];

            $hydrate = function (array $report) {
                [$class] = $report;
                $c = new $class();
                $c->view = (string) request('view', '');
                $c->search = (string) request('search', '');
                $c->filters = array_map('strval', (array) request('filters', []));
                // Stock is a snapshot and ignores these; the movement reports need
                // them. Defaulted to today like the other modules, so a bare URL
                // exports one day rather than every row ever recorded.
                $c->dateFrom = (string) request('dateFrom', now()->format('Y-m-d'));
                $c->dateTo = (string) request('dateTo', now()->format('Y-m-d'));

                return $c;
            };

            $authorize = function (array $report) {
                abort_unless((bool) request()->user()?->canDo($report[1], 'export'), 403);
            };

            Route::get('/{report}/print', function (string $report) use ($reports, $hydrate, $authorize) {
                abort_unless(isset($reports[$report]), 404);
                $authorize($reports[$report]);

                return view('core::print.grid', $hydrate($reports[$report])->reportPayload());
            })->name('print');

            Route::get('/{report}/download', function (string $report) use ($reports, $hydrate, $authorize) {
                abort_unless(isset($reports[$report]), 404);
                $authorize($reports[$report]);

                $format = strtolower((string) request('format', 'xlsx'));
                abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

                return $hydrate($reports[$report])->export($format);
            })->name('download');
        });
    });

/*
| Machines — the Company > Factory > Line > Project spine. Factories live under
| Settings (they belong to a company, not to BIL); the machines beneath them are
| BIL's, so Lines and Projects sit here.
*/
Route::middleware('auth')
    ->prefix('machines')->name('machines.')
    ->group(function () {
        Route::get('/statistics', MachineStatistics::class)
            ->middleware('page:bil.machines.statistics')->name('statistics');
        // Direct download of the current statistics section as xlsx/csv/pdf.
        Route::get('/statistics/export', function () {
            abort_unless((bool) request()->user()?->canDo('bil.machines.statistics', 'export'), 403);

            $format = strtolower((string) request('format', 'xlsx'));
            abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

            $c = new MachineStatistics();
            $c->section = (string) request('section', '');
            $c->range = (string) request('range', '30d');
            $c->figures = (string) request('figures', 'rounded');

            return $c->exportResponse($format);
        })->middleware('page:bil.machines.statistics')->name('statistics.export');
        Route::get('/lines', MachineLines::class)
            ->middleware('page:bil.machines.lines')->name('lines');
        Route::get('/projects', MachineProjects::class)
            ->middleware('page:bil.machines.projects')->name('projects');
        Route::get('/conversion-setup', ConversionSetup::class)
            ->middleware('page:bil.machines.conversion_setup')->name('conversion-setup');
        Route::get('/services', MachineServices::class)
            ->middleware('page:bil.machines.services')->name('services');

        // Reports — same print/download contract as the Raw Materials reports,
        // but gated on bil.machines.reports.* keys.
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/services', ServicesReport::class)
                ->middleware('page:bil.machines.reports.services')->name('services');
            Route::get('/conversion-history', ConversionHistory::class)
                ->middleware('page:bil.machines.reports.conversion_history')->name('conversion-history');

            $reports = [
                'services' => ServicesReport::class,
                'conversion-history' => ConversionHistory::class,
            ];

            $hydrate = function (string $class) {
                $c = new $class();
                $c->view = (string) request('view', '');
                $c->search = (string) request('search', '');
                $c->dateFrom = (string) request('dateFrom', now()->format('Y-m-d'));
                $c->dateTo = (string) request('dateTo', now()->format('Y-m-d'));
                $c->filters = array_map('strval', (array) request('filters', []));
                // A drill-down export: same filters, but the open group's records.
                $c->detailMode = (bool) request('detail', false);
                $c->detailKey = request('detailKey') !== null ? (string) request('detailKey') : null;
                $c->detailSearch = (string) request('detailSearch', '');

                return $c;
            };

            Route::get('/{report}/print', function (string $report) use ($reports, $hydrate) {
                abort_unless(isset($reports[$report]), 404);
                abort_unless((bool) request()->user()?->canDo('bil.machines.reports.' . str_replace('-', '_', $report), 'export'), 403);

                return view('core::print.grid', $hydrate($reports[$report])->reportPayload());
            })->name('print');

            Route::get('/{report}/download', function (string $report) use ($reports, $hydrate) {
                abort_unless(isset($reports[$report]), 404);
                abort_unless((bool) request()->user()?->canDo('bil.machines.reports.' . str_replace('-', '_', $report), 'export'), 403);

                $format = strtolower((string) request('format', 'xlsx'));
                abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

                return $hydrate($reports[$report])->export($format);
            })->name('download');
        });
    });
