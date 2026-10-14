<?php

use Illuminate\Support\Facades\Route;
use Modules\Bil\Livewire\FinishedGoods\Stock as FinishedGoodsStockPage;
use Modules\Bil\Livewire\JumboRolls\Stock as JumboRollStock;
use Modules\Bil\Livewire\Sales\Customers as SalesCustomers;
use Modules\Bil\Livewire\Sales\Delivery as SalesDeliveryPage;
use Modules\Bil\Livewire\Sales\Loading as SalesLoadingPage;
use Modules\Bil\Livewire\Sales\Orders as SalesOrders;
use Modules\Bil\Livewire\Sales\Returns as SalesReturnsPage;
use Modules\Bil\Livewire\Sales\Transporters as SalesTransporters;
use Modules\Bil\Livewire\Sales\Waybill as SalesWaybillPage;

/*
| BHN (Abuja) module routes. BHN reuses the BIL screens — the same Livewire
| components — served under the /bhn prefix. ResolveCompanyModule rebinds the
| `bil` connection + config to BHN for these requests, so the shared components
| operate on BHN's schema. Page keys + route names are BHN's own (bhn.*), so
| access control and nav are per-company.
|
| FIRST SLICE: the Sales chain plus FG/Jumbo stock, to exercise the reuse on
| real BHN data. The remaining BIL groups are added here as the shared route
| builder is factored out of routes/bil.php.
*/

Route::middleware('auth')
    ->prefix('sales')->name('sales.')
    ->group(function () {
        Route::get('/customers', SalesCustomers::class)
            ->middleware('page:bhn.sales.customers')->name('customers');
        Route::get('/orders', SalesOrders::class)
            ->middleware('page:bhn.sales.orders')->name('orders');
        Route::get('/loading', SalesLoadingPage::class)
            ->middleware('page:bhn.sales.loading')->name('loading');
        Route::get('/delivery', SalesDeliveryPage::class)
            ->middleware('page:bhn.sales.delivery')->name('delivery');
        Route::get('/returns', SalesReturnsPage::class)
            ->middleware('page:bhn.sales.returns')->name('returns');
        Route::get('/waybill', SalesWaybillPage::class)
            ->middleware('page:bhn.sales.waybill')->name('waybill');
        Route::get('/transporters', SalesTransporters::class)
            ->middleware('page:bhn.sales.transporters')->name('transporters');
    });

Route::middleware('auth')
    ->prefix('finished-goods')->name('finished-goods.')
    ->group(function () {
        Route::get('/warehouse-stock', FinishedGoodsStockPage::class)
            ->middleware('page:bhn.finished_goods.warehouse_stock')->name('warehouse-stock');
    });

Route::middleware('auth')
    ->prefix('jumbo-rolls')->name('jumbo-rolls.')
    ->group(function () {
        Route::get('/stock', JumboRollStock::class)
            ->middleware('page:bhn.jumbo_rolls.stock')->name('stock');
    });
