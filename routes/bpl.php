<?php

use Illuminate\Support\Facades\Route;
use Modules\Bpl\Livewire\Finance\Accounts;
use Modules\Bpl\Livewire\Finance\Banks;
use Modules\Bpl\Livewire\JumboRolls\FactoryExit;
use Modules\Bpl\Livewire\JumboRolls\Grades;
use Modules\Bpl\Livewire\JumboRolls\Production\Hardroll as HardrollProduction;
use Modules\Bpl\Livewire\JumboRolls\Production\Softroll as SoftrollProduction;
use Modules\Bpl\Livewire\JumboRolls\Products\Hardroll;
use Modules\Bpl\Livewire\JumboRolls\Products\Softroll;
use Modules\Bpl\Livewire\JumboRolls\WarehouseEntry;
use Modules\Bpl\Livewire\JumboRolls\WarehouseExit;
use Modules\Bpl\Livewire\JumboRolls\WarehouseStock;
use Modules\Bpl\Livewire\JumboRolls\WarehouseTransfer;
use Modules\Bpl\Livewire\JumboRolls\WeightAllowances;
use Modules\Bpl\Livewire\Sales\Customers;
use Modules\Bpl\Livewire\Sales\Orders as SalesOrders;
use Modules\Bpl\Livewire\Sales\Transporters;
use Modules\Bpl\Support\RollLabel;

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
        Route::get('/customers', Customers::class)
            ->middleware('page:bpl.sales.customers')->name('customers');
        Route::get('/transporters', Transporters::class)
            ->middleware('page:bpl.sales.transporters')->name('transporters');
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
    });
