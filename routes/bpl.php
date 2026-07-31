<?php

use Illuminate\Support\Facades\Route;
use Modules\Bpl\Livewire\JumboRolls\Grades;
use Modules\Bpl\Livewire\JumboRolls\Products\Hardroll;
use Modules\Bpl\Livewire\JumboRolls\Products\Softroll;
use Modules\Bpl\Livewire\JumboRolls\Sales\Customers;

/*
| BPL module routes — bpl_*, wp_* (waste paper), softroll production.
| Served under the /bpl URL prefix (see ModuleServiceProvider).
| Pages are added here as they are rebuilt from production screenshots.
*/

/*
| Jumbo Rolls — the first BPL functional area (mirrors BIL → Raw Materials).
| Grades (parent) and Products; Products is one page presented as Hardroll /
| Softroll tabs (each its own grid + form). Gated by `view-bpl`.
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

        // Sales — customer-facing masters and, later, orders/invoices.
        Route::prefix('sales')->name('sales.')->group(function () {
            Route::get('/customers', Customers::class)
                ->middleware('page:bpl.jumbo_rolls.sales.customers')->name('customers');
        });
    });
