<?php

use Illuminate\Support\Facades\Route;
use Modules\Bpl\Livewire\Finance\Accounts;
use Modules\Bpl\Livewire\Finance\Reports\Accounts as FinanceAccountsReport;
use Modules\Bpl\Livewire\Finance\Reports\ExportPayments as FinanceExportPaymentsReport;
use Modules\Bpl\Livewire\Finance\Reports\InvoicePayments as FinanceInvoicePaymentsReport;
use Modules\Bpl\Livewire\Finance\Reports\Receivables as FinanceReceivablesReport;
use Modules\Bpl\Livewire\Finance\Reports\WaybillPayments as FinanceWaybillPaymentsReport;
use Modules\Bpl\Livewire\Finance\Statistics as FinanceStatistics;
use Modules\Bpl\Livewire\Finance\Banks;
use Modules\Bpl\Livewire\Finance\InvoicePayments;
use Modules\Bpl\Livewire\Finance\PaymentTerms;
use Modules\Bpl\Livewire\Finance\WaybillPayments;
use Modules\Bpl\Livewire\Logistics\BillsOfLading;
use Modules\Bpl\Livewire\Logistics\Reports\BillsOfLading as BillsOfLadingReport;
use Modules\Bpl\Livewire\Logistics\Reports\Deliveries as DeliveriesReport;
use Modules\Bpl\Livewire\Logistics\Statistics as LogisticsStatistics;
use Modules\Bpl\Livewire\Logistics\Delivery;
use Modules\Bpl\Livewire\Logistics\Weighbridge;
use Modules\Bpl\Livewire\JumboRolls\FactoryExit;
use Modules\Bpl\Livewire\JumboRolls\Reports\FactoryExit as FactoryExitReport;
use Modules\Bpl\Livewire\JumboRolls\Reports\Production as ProductionReport;
use Modules\Bpl\Livewire\JumboRolls\Reports\RollTrail as RollTrailReport;
use Modules\Bpl\Livewire\JumboRolls\Reports\WaitingArea as WaitingAreaReport;
use Modules\Bpl\Livewire\JumboRolls\Reports\WarehouseEntry as WarehouseEntryReport;
use Modules\Bpl\Livewire\JumboRolls\Reports\WarehouseExit as WarehouseExitReport;
use Modules\Bpl\Livewire\JumboRolls\Reports\WarehouseTransfer as WarehouseTransferReport;
use Modules\Bpl\Livewire\JumboRolls\Statistics as JumboRollStatistics;
use Modules\Bpl\Livewire\JumboRolls\Grades;
use Modules\Bpl\Livewire\JumboRolls\Production\Hardroll as HardrollProduction;
use Modules\Bpl\Livewire\JumboRolls\Production\Softroll as SoftrollProduction;
use Modules\Bpl\Livewire\JumboRolls\Products\Hardroll;
use Modules\Bpl\Livewire\JumboRolls\Products\Softroll;
use Modules\Bpl\Livewire\JumboRolls\WaitingArea;
use Modules\Bpl\Livewire\JumboRolls\WarehouseEntry;
use Modules\Bpl\Livewire\JumboRolls\WarehouseExit;
use Modules\Bpl\Livewire\JumboRolls\WarehouseStock;
use Modules\Bpl\Livewire\JumboRolls\WarehouseTransfer;
use Modules\Bpl\Livewire\JumboRolls\WeightAllowances;
use Modules\Bpl\Livewire\Sales\Customers;
use Modules\Bpl\Livewire\Sales\Reports\OrderTrail as SalesOrderTrailReport;
use Modules\Bpl\Livewire\Sales\Reports\Orders as SalesOrdersReport;
use Modules\Bpl\Livewire\Sales\Reports\Proformas as SalesProformasReport;
use Modules\Bpl\Livewire\Sales\Reports\Shipments as SalesShipmentsReport;
use Modules\Bpl\Livewire\Sales\Statistics as SalesStatistics;
use Modules\Bpl\Support\SalesOrderTrail;
use Modules\Bpl\Livewire\Sales\Orders as SalesOrders;
use Modules\Bpl\Livewire\Sales\PackingLists;
use Modules\Bpl\Livewire\Sales\Proformas;
use Modules\Bpl\Livewire\Sales\Transporters;
use Modules\Bpl\Support\RollLabel;
use Modules\Bpl\Support\BillOfLadingDocs;
use Modules\Bpl\Support\PackingList;
use Modules\Bpl\Support\ProformaInvoice;
use Modules\Bpl\Support\Waybill;

/*
| BPL module routes — bpl_*, wp_* (waste paper), softroll production.
| Served under the /bpl URL prefix (see ModuleServiceProvider).
| Pages are added here as they are rebuilt from production screenshots.
*/

/*
| Jumbo Rolls — the first BPL functional area (mirrors BIL → Raw Materials).
| Grades (parent), Products and Production. Products and Production are each
| one page presented as Hardroll / Softroll tabs (each its own grid + form),
| because the two streams have different masters, different columns and
| different label sheets.
*/
Route::middleware('auth')
    ->prefix('jumbo-rolls')->name('jumbo-rolls.')
    ->group(function () {
        Route::get('/grades', Grades::class)
            ->middleware('page:bpl.jumbo_rolls.grades')->name('grades');

        // /bpl/jumbo-rolls/products lands on the Hardroll tab by default.
        Route::redirect('/products', '/bpl/jumbo-rolls/products/hardroll');
        Route::get('/products/hardroll', Hardroll::class)
            ->middleware('page:bpl.jumbo_rolls.products.hardroll')->name('products.hardroll');
        Route::get('/products/softroll', Softroll::class)
            ->middleware('page:bpl.jumbo_rolls.products.softroll')->name('products.softroll');

        // The core/wrapper allowance deducted from a hardroll's scale weight.
        Route::get('/weight-allowances', WeightAllowances::class)
            ->middleware('page:bpl.jumbo_rolls.weight_allowances')->name('weight-allowances');

        Route::redirect('/production', '/bpl/jumbo-rolls/production/hardroll');
        Route::get('/production/hardroll', HardrollProduction::class)
            ->middleware('page:bpl.jumbo_rolls.production.hardroll')->name('production.hardroll');
        Route::get('/production/softroll', SoftrollProduction::class)
            ->middleware('page:bpl.jumbo_rolls.production.softroll')->name('production.softroll');

        // Rolls leaving the paper machine floor. One screen for both streams —
        // the barcode decides which, see Modules\Bpl\Support\RollResolver.
        Route::get('/factory-exit', FactoryExit::class)
            ->middleware('page:bpl.jumbo_rolls.factory_exit')->name('factory-exit');

        // Rolls arriving in a BPL store. Same one-screen-for-both-streams
        // shape as Factory Exit, and it MOVES STOCK — see JumboRollStock.
        Route::get('/warehouse-entry', WarehouseEntry::class)
            ->middleware('page:bpl.jumbo_rolls.warehouse_entry')->name('warehouse-entry');
        Route::get('/waiting-area', WaitingArea::class)
            ->middleware('page:bpl.jumbo_rolls.waiting_area')->name('waiting-area');

        // Rolls leaving a BPL store. Also moves stock, downwards, and is the
        // only movement screen that cares WHICH store a roll is in.
        Route::get('/warehouse-exit', WarehouseExit::class)
            ->middleware('page:bpl.jumbo_rolls.warehouse_exit')->name('warehouse-exit');

        // What the warehouses hold: entries minus exits, against what the
        // bpl_hardroll_stock / bpl_softroll_stock tables record. Read-only.
        Route::get('/warehouse-stock', WarehouseStock::class)
            ->middleware('page:bpl.jumbo_rolls.warehouse_stock')->name('warehouse-stock');

        // Moving a roll between BPL warehouses. No source picker — the scan
        // says where the roll is; the operator says where it is going.
        Route::get('/warehouse-transfer', WarehouseTransfer::class)
            ->middleware('page:bpl.jumbo_rolls.warehouse_transfer')->name('warehouse-transfer');

        /*
        | Statistics + Reports. Same contract as the BIL modules: the dashboard
        | has one export route, and the report-framework pages share a
        | print/download pair keyed by the report's own slug.
        */
        Route::get('/statistics', JumboRollStatistics::class)
            ->middleware('page:bpl.jumbo_rolls.statistics')->name('statistics');
        Route::get('/statistics/export', function () {
            abort_unless((bool) request()->user()?->canDo('bpl.jumbo_rolls.statistics', 'export'), 403);

            $format = strtolower((string) request('format', 'xlsx'));
            abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

            $c = new JumboRollStatistics();
            $c->section = (string) request('section', '');
            $c->range = (string) request('range', '30d');
            $c->figures = (string) request('figures', 'rounded');

            return $c->exportResponse($format);
        })->middleware('page:bpl.jumbo_rolls.statistics')->name('statistics.export');

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/production', ProductionReport::class)
                ->middleware('page:bpl.jumbo_rolls.reports.production')->name('production');
            Route::get('/factory-exit', FactoryExitReport::class)
                ->middleware('page:bpl.jumbo_rolls.reports.factory_exit')->name('factory-exit');
            Route::get('/warehouse-entry', WarehouseEntryReport::class)
                ->middleware('page:bpl.jumbo_rolls.reports.warehouse_entry')->name('warehouse-entry');
            Route::get('/warehouse-exit', WarehouseExitReport::class)
                ->middleware('page:bpl.jumbo_rolls.reports.warehouse_exit')->name('warehouse-exit');
            Route::get('/warehouse-transfer', WarehouseTransferReport::class)
                ->middleware('page:bpl.jumbo_rolls.reports.warehouse_transfer')->name('warehouse-transfer');
            Route::get('/waiting-area', WaitingAreaReport::class)
                ->middleware('page:bpl.jumbo_rolls.reports.waiting_area')->name('waiting-area');
            Route::get('/roll-trail', RollTrailReport::class)
                ->middleware('page:bpl.jumbo_rolls.reports.roll_trail')->name('roll-trail');

            // slug => [component, page key]
            $reports = [
                'production' => [ProductionReport::class, 'bpl.jumbo_rolls.reports.production'],
                'factory-exit' => [FactoryExitReport::class, 'bpl.jumbo_rolls.reports.factory_exit'],
                'warehouse-entry' => [WarehouseEntryReport::class, 'bpl.jumbo_rolls.reports.warehouse_entry'],
                'warehouse-exit' => [WarehouseExitReport::class, 'bpl.jumbo_rolls.reports.warehouse_exit'],
                'warehouse-transfer' => [WarehouseTransferReport::class, 'bpl.jumbo_rolls.reports.warehouse_transfer'],
                'waiting-area' => [WaitingAreaReport::class, 'bpl.jumbo_rolls.reports.waiting_area'],
                'roll-trail' => [RollTrailReport::class, 'bpl.jumbo_rolls.reports.roll_trail'],
            ];

            $hydrate = function (array $report) {
                $c = new $report[0]();
                $c->view = (string) request('view', '');
                $c->search = (string) request('search', '');
                // Defaulted to today like the other modules, so a bare URL
                // exports one day rather than every row ever recorded.
                $c->dateFrom = (string) request('dateFrom', now()->format('Y-m-d'));
                $c->dateTo = (string) request('dateTo', now()->format('Y-m-d'));
                $c->filters = array_map('strval', (array) request('filters', []));
                // A drill-down export: same filters, but the open group's records.
                $c->detailMode = (bool) request('detail', false);
                $c->detailKey = request('detailKey') !== null ? (string) request('detailKey') : null;
                $c->detailSearch = (string) request('detailSearch', '');

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

        // Reel labels. Gated on the production page that mints the roll, so a
        // reprint needs the same right as the original entry.
        Route::get('/production/hardroll/{id}/label', fn (int $id) => RollLabel::hardroll($id))
            ->whereNumber('id')
            ->middleware('page:bpl.jumbo_rolls.production.hardroll')->name('production.hardroll.label');
        Route::get('/production/softroll/{id}/label', fn (int $id) => RollLabel::softroll($id))
            ->whereNumber('id')
            ->middleware('page:bpl.jumbo_rolls.production.softroll')->name('production.softroll.label');
    });

/*
| Sales — a BPL functional area in its own right (customer-facing masters and,
| later, orders/invoices).
*/
Route::middleware('auth')
    ->prefix('sales')->name('sales.')
    ->group(function () {
        Route::get('/orders', SalesOrders::class)
            ->middleware('page:bpl.sales.orders')->name('orders');
        // The hardroll product list for the order lines, fetched once by the
        // page rather than embedded in every Livewire round-trip (~240 KB).
        Route::get('/orders/products', fn () => response()->json(SalesOrders::productOptions()))
            ->middleware('page:bpl.sales.orders')->name('orders.products');
        Route::get('/proformas', Proformas::class)
            ->middleware('page:bpl.sales.proformas')->name('proformas');
        // The sheet the customer is sent. Gated on the page that raises it.
        Route::get('/proformas/{order}/print', fn (int $order) => ProformaInvoice::render($order))
            ->whereNumber('order')
            ->middleware('page:bpl.sales.proformas')->name('proformas.print');
        Route::get('/packing-lists', PackingLists::class)
            ->middleware('page:bpl.sales.packing_lists')->name('packing-lists');
        // The document that travels with the shipment, and the invoice priced
        // on what actually shipped. Both gated on the page that raises them.
        Route::get('/packing-lists/{id}/print', fn (int $id) => PackingList::render($id))
            ->whereNumber('id')
            ->middleware('page:bpl.sales.packing_lists')->name('packing-lists.print');
        Route::get('/packing-lists/{id}/invoice', fn (int $id) => PackingList::finalInvoice($id))
            ->whereNumber('id')
            ->middleware('page:bpl.sales.packing_lists')->name('packing-lists.invoice');
        Route::get('/customers', Customers::class)
            ->middleware('page:bpl.sales.customers')->name('customers');

        /*
        | Statistics + Reports, on the same contract as every other module:
        | the dashboard has one export route, and the report-framework pages
        | share a print/download pair keyed by the report's own slug.
        */
        Route::get('/statistics', SalesStatistics::class)
            ->middleware('page:bpl.sales.statistics')->name('statistics');
        Route::get('/statistics/export', function () {
            abort_unless((bool) request()->user()?->canDo('bpl.sales.statistics', 'export'), 403);

            $format = strtolower((string) request('format', 'xlsx'));
            abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

            $c = new SalesStatistics();
            $c->section = (string) request('section', '');
            $c->range = (string) request('range', '12m');
            $c->figures = (string) request('figures', 'rounded');

            return $c->exportResponse($format);
        })->middleware('page:bpl.sales.statistics')->name('statistics.export');

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/orders', SalesOrdersReport::class)
                ->middleware('page:bpl.sales.reports.orders')->name('orders');
            Route::get('/proformas', SalesProformasReport::class)
                ->middleware('page:bpl.sales.reports.proformas')->name('proformas');
            Route::get('/shipments', SalesShipmentsReport::class)
                ->middleware('page:bpl.sales.reports.shipments')->name('shipments');

            /*
            | Order Trail stands apart from the three above: it is not a
            | date-ranged list on the report framework, so it does not go
            | through the shared {report}/print + {report}/download pair. It
            | takes ONE order and prints that order's whole trail.
            */
            Route::get('/order-trail', SalesOrderTrailReport::class)
                ->middleware('page:bpl.sales.reports.order_trail')->name('order-trail');

            $trail = function () {
                $order = SalesOrderTrail::find((string) request('order', ''));
                abort_unless($order !== null, 404, 'No such order.');

                $symbol = \Modules\Bpl\Support\ProformaInvoice::symbol((string) ($order->symbol ?? ''));
                $money = fn ($v) => $v === null ? '' : $symbol . number_format((float) $v, 2);

                $rows = [];

                foreach (SalesOrderTrail::trail($order) as $e) {
                    $rows[] = [
                        SalesOrderTrail::humanDate($e['date']),
                        $e['label'],
                        $e['reference'],
                        $money($e['money']),
                        $e['detail'],
                    ];
                }

                $totals = SalesOrderTrail::totals($order);

                return [
                    'label' => 'Order Trail — ' . $order->number,
                    'context' => array_values(array_filter([
                        ['Order', (string) $order->number],
                        ['Customer', (string) ($order->customername ?: '—')],
                        ['Country', (string) ($order->customercountry ?: '—')],
                        ['Date of order', SalesOrderTrail::humanDate($order->date)],
                        $order->nxp ? ['NXP', (string) $order->nxp] : null,
                        ['Ordered', number_format($totals['ordered'] / 1000, 3) . ' t'],
                        ['Shipped', number_format($totals['shipped'] / 1000, 3) . ' t'],
                        ['Invoiced', $money($totals['invoiced'])],
                        ['Paid', $money($totals['paid'])],
                        $totals['outstanding'] > 1 ? ['Outstanding', $money($totals['outstanding'])] : null,
                    ])),
                    'headings' => ['Date', 'Step', 'Reference', 'Amount', 'Detail'],
                    'rows' => $rows,
                    'logo' => \Modules\Core\Support\Branding::logo('bpl'),
                    'order' => $order,
                ];
            };

            $trailAuth = function () {
                abort_unless((bool) request()->user()?->canDo('bpl.sales.reports.order_trail', 'export'), 403);
            };

            Route::get('/order-trail/print', function () use ($trail, $trailAuth) {
                $trailAuth();

                return view('core::print.grid', $trail());
            })->middleware('page:bpl.sales.reports.order_trail')->name('order-trail.print');

            Route::get('/order-trail/download', function () use ($trail, $trailAuth) {
                $trailAuth();

                $format = strtolower((string) request('format', 'xlsx'));
                abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

                $payload = $trail();
                $base = 'bpl-order-trail-'
                    . preg_replace('~[^A-Za-z0-9]+~', '-', (string) $payload['order']->number);

                if ($format === 'pdf') {
                    return \Modules\Core\Support\GridExporter::pdf($base, $payload['label'],
                        $payload['headings'], $payload['rows'], $payload['context']);
                }

                return \Modules\Core\Support\GridExporter::download($format, $base,
                    $payload['headings'], $payload['rows'], $payload['context']);
            })->middleware('page:bpl.sales.reports.order_trail')->name('order-trail.download');

            // slug => [component, page key]
            $reports = [
                'orders' => [SalesOrdersReport::class, 'bpl.sales.reports.orders'],
                'proformas' => [SalesProformasReport::class, 'bpl.sales.reports.proformas'],
                'shipments' => [SalesShipmentsReport::class, 'bpl.sales.reports.shipments'],
            ];

            $hydrate = function (array $report) {
                $c = new $report[0]();
                $c->view = (string) request('view', '');
                $c->search = (string) request('search', '');
                // Defaulted to today like the other modules, so a bare URL
                // exports one day rather than every row ever recorded.
                $c->dateFrom = (string) request('dateFrom', now()->format('Y-m-d'));
                $c->dateTo = (string) request('dateTo', now()->format('Y-m-d'));
                $c->filters = array_map('strval', (array) request('filters', []));
                // A drill-down export: same filters, but the open group's records.
                $c->detailMode = (bool) request('detail', false);
                $c->detailKey = request('detailKey') !== null ? (string) request('detailKey') : null;
                $c->detailSearch = (string) request('detailSearch', '');

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
| Finance — the banks and receiving accounts proformas name.
*/
Route::middleware('auth')
    ->prefix('finance')->name('finance.')
    ->group(function () {
        Route::get('/banks', Banks::class)
            ->middleware('page:bpl.finance.banks')->name('banks');
        Route::get('/accounts', Accounts::class)
            ->middleware('page:bpl.finance.accounts')->name('accounts');
        Route::get('/payment-terms', PaymentTerms::class)
            ->middleware('page:bpl.finance.payment_terms')->name('payment-terms');
        Route::get('/waybill-payments', WaybillPayments::class)
            ->middleware('page:bpl.finance.waybill_payments')->name('waybill-payments');
        Route::get('/invoice-payments', InvoicePayments::class)
            ->middleware('page:bpl.finance.invoice_payments')->name('invoice-payments');

        /*
        | Statistics + Reports, on the same contract as every other module.
        */
        Route::get('/statistics', FinanceStatistics::class)
            ->middleware('page:bpl.finance.statistics')->name('statistics');
        Route::get('/statistics/export', function () {
            abort_unless((bool) request()->user()?->canDo('bpl.finance.statistics', 'export'), 403);

            $format = strtolower((string) request('format', 'xlsx'));
            abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

            $c = new FinanceStatistics();
            $c->section = (string) request('section', '');
            $c->range = (string) request('range', '12m');
            $c->figures = (string) request('figures', 'rounded');

            return $c->exportResponse($format);
        })->middleware('page:bpl.finance.statistics')->name('statistics.export');

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/receivables', FinanceReceivablesReport::class)
                ->middleware('page:bpl.finance.reports.receivables')->name('receivables');
            Route::get('/invoice-payments', FinanceInvoicePaymentsReport::class)
                ->middleware('page:bpl.finance.reports.invoice_payments')->name('invoice-payments');
            Route::get('/waybill-payments', FinanceWaybillPaymentsReport::class)
                ->middleware('page:bpl.finance.reports.waybill_payments')->name('waybill-payments');
            Route::get('/export-payments', FinanceExportPaymentsReport::class)
                ->middleware('page:bpl.finance.reports.export_payments')->name('export-payments');
            Route::get('/accounts', FinanceAccountsReport::class)
                ->middleware('page:bpl.finance.reports.accounts')->name('accounts');

            // slug => [component, page key]
            $reports = [
                'receivables' => [FinanceReceivablesReport::class, 'bpl.finance.reports.receivables'],
                'invoice-payments' => [FinanceInvoicePaymentsReport::class, 'bpl.finance.reports.invoice_payments'],
                'waybill-payments' => [FinanceWaybillPaymentsReport::class, 'bpl.finance.reports.waybill_payments'],
                'export-payments' => [FinanceExportPaymentsReport::class, 'bpl.finance.reports.export_payments'],
                'accounts' => [FinanceAccountsReport::class, 'bpl.finance.reports.accounts'],
            ];

            $hydrate = function (array $report) {
                $c = new $report[0]();
                $c->view = (string) request('view', '');
                $c->search = (string) request('search', '');
                // Defaulted to today like the other modules, so a bare URL
                // exports one day rather than every row ever recorded. The
                // snapshot reports (Receivables, Accounts) ignore these.
                $c->dateFrom = (string) request('dateFrom', now()->format('Y-m-d'));
                $c->dateTo = (string) request('dateTo', now()->format('Y-m-d'));
                $c->filters = array_map('strval', (array) request('filters', []));
                // A drill-down export: same filters, but the open group's records.
                $c->detailMode = (bool) request('detail', false);
                $c->detailKey = request('detailKey') !== null ? (string) request('detailKey') : null;
                $c->detailSearch = (string) request('detailSearch', '');

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
| Logistics — getting the rolls out of the gate: the truck load and its
| waybill, and the hauliers who carry them.
*/
Route::middleware('auth')
    ->prefix('logistics')->name('logistics.')
    ->group(function () {
        Route::get('/delivery', Delivery::class)
            ->middleware('page:bpl.logistics.delivery')->name('delivery');
        // The waybill sheet. Gated on the delivery page that creates it, so a
        // reprint needs the same right as the load did.
        Route::get('/delivery/{number}/waybill', fn (string $number) => Waybill::render($number))
            ->where('number', '[0-9A-Za-z\\-]+')
            ->middleware('page:bpl.logistics.delivery')->name('delivery.waybill');
        // The receiving mill's copy: every roll on its own line, and the file
        // their own system imports.
        Route::get('/delivery/{number}/processor-slip', fn (string $number) => Waybill::processor($number))
            ->where('number', '[0-9A-Za-z\-]+')
            ->middleware('page:bpl.logistics.delivery')->name('delivery.processor-slip');
        Route::get('/delivery/{number}/import-sql', fn (string $number) => Waybill::importSql($number))
            ->where('number', '[0-9A-Za-z\-]+')
            ->middleware('page:bpl.logistics.delivery')->name('delivery.import-sql');
        Route::get('/weighbridge', Weighbridge::class)
            ->middleware('page:bpl.logistics.weighbridge')->name('weighbridge');
        Route::get('/bills-of-lading', BillsOfLading::class)
            ->middleware('page:bpl.logistics.bills_of_lading')->name('bills-of-lading');
        // The scanned document, streamed from the folder shared with the legacy
        // app — it lives outside the web root, so it is served, not published.
        Route::get('/bills-of-lading/{id}/document', fn (int $id) => BillOfLadingDocs::stream($id))
            ->whereNumber('id')
            ->middleware('page:bpl.logistics.bills_of_lading')->name('bills-of-lading.document');
        Route::get('/transporters', Transporters::class)
            ->middleware('page:bpl.logistics.transporters')->name('transporters');

        /*
        | Statistics + Reports, on the same contract as every other module.
        */
        Route::get('/statistics', LogisticsStatistics::class)
            ->middleware('page:bpl.logistics.statistics')->name('statistics');
        Route::get('/statistics/export', function () {
            abort_unless((bool) request()->user()?->canDo('bpl.logistics.statistics', 'export'), 403);

            $format = strtolower((string) request('format', 'xlsx'));
            abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

            $c = new LogisticsStatistics();
            $c->section = (string) request('section', '');
            $c->range = (string) request('range', '30d');
            $c->figures = (string) request('figures', 'rounded');

            return $c->exportResponse($format);
        })->middleware('page:bpl.logistics.statistics')->name('statistics.export');

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/deliveries', DeliveriesReport::class)
                ->middleware('page:bpl.logistics.reports.deliveries')->name('deliveries');
            Route::get('/bills-of-lading', BillsOfLadingReport::class)
                ->middleware('page:bpl.logistics.reports.bills_of_lading')->name('bills-of-lading');

            // slug => [component, page key]
            $reports = [
                'deliveries' => [DeliveriesReport::class, 'bpl.logistics.reports.deliveries'],
                'bills-of-lading' => [BillsOfLadingReport::class, 'bpl.logistics.reports.bills_of_lading'],
            ];

            $hydrate = function (array $report) {
                $c = new $report[0]();
                $c->view = (string) request('view', '');
                $c->search = (string) request('search', '');
                // Defaulted to today like the other modules, so a bare URL
                // exports one day rather than every row ever recorded.
                $c->dateFrom = (string) request('dateFrom', now()->format('Y-m-d'));
                $c->dateTo = (string) request('dateTo', now()->format('Y-m-d'));
                $c->filters = array_map('strval', (array) request('filters', []));
                // A drill-down export: same filters, but the open group's records.
                $c->detailMode = (bool) request('detail', false);
                $c->detailKey = request('detailKey') !== null ? (string) request('detailKey') : null;
                $c->detailSearch = (string) request('detailSearch', '');

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
