<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Controllers\AuthController;
use Modules\Core\Livewire\Admin\Companies;
use Modules\Core\Livewire\Admin\Departments;
use Modules\Core\Livewire\Admin\Roles;
use Modules\Core\Livewire\Admin\Users;
use Modules\Core\Livewire\Settings\Appearance;
use Modules\Core\Livewire\Settings\DataViews;
use Modules\Core\Livewire\Settings\ShiftSettings;

/*
| Core (shared) routes — auth and anything not owned by the Bil or Bpl
| modules. Served at the web root, no URL prefix.
*/

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

/*
| Admin area — auth + per-page permission (Spatie registers each permission
| as a Gate ability, so `can:` middleware works). Non-admins are 403'd and
| the matching nav items are hidden (see the layout).
*/
Route::middleware('auth')->group(function () {
    Route::prefix('admin')->group(function () {
        Route::get('/companies', Companies::class)->middleware('page:admin.companies')->name('admin.companies');
        Route::get('/departments', Departments::class)->middleware('page:admin.departments')->name('admin.departments');
        Route::get('/roles', Roles::class)->middleware('page:admin.roles')->name('admin.roles');
        Route::get('/users', Users::class)->middleware('page:admin.users')->name('admin.users');

        // Generic DataGrid print — resolves the grid class by its pageKey.
        Route::get('/grid/{page}/print', function (string $page) {
            $class = collect(config('datagrid.grids'))
                ->first(fn ($c) => (new $c)->pageKey() === $page);
            abort_unless($class, 404);

            $payload = (new $class)->printPayload(request('view'), (string) request('search', ''));

            return view('core::print.grid', $payload);
        })->name('admin.grid.print');
    });

    // Appearance is a personal preference — any authenticated user. Data Views
    // is admin configuration — gated.
    Route::get('/settings/appearance', Appearance::class)->name('settings.appearance');
    Route::get('/settings/data-views', DataViews::class)->middleware('page:settings.data_views')->name('settings.data-views');

    // Shift windows — access granted per page (settings.shifts). Give the
    // Shift Settings page to Admin + an Operations Manager role, etc.
    Route::get('/settings/shifts', ShiftSettings::class)->middleware('page:settings.shifts')->name('settings.shifts');
});
