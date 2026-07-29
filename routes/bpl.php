<?php

use Illuminate\Support\Facades\Route;
use Modules\Bpl\Livewire\Grades;
use Modules\Bpl\Livewire\Products\Hardroll;
use Modules\Bpl\Livewire\Products\Softroll;

/*
| BPL module routes — bpl_*, wp_* (waste paper), softroll production.
| Served under the /bpl URL prefix (see ModuleServiceProvider).
| Pages are added here as they are rebuilt from production screenshots.
*/

/*
| BPL masters — Grades (parent) and Products. Products is one page presented
| as Hardroll / Softroll tabs (each its own grid + form). Gated by `view-bpl`.
*/
Route::middleware(['auth', 'can:view-bpl'])->group(function () {
    Route::get('/grades', Grades::class)->name('grades');

    // /bpl/products lands on the Hardroll tab by default.
    Route::redirect('/products', '/bpl/products/hardroll');
    Route::get('/products/hardroll', Hardroll::class)->name('products.hardroll');
    Route::get('/products/softroll', Softroll::class)->name('products.softroll');
});
