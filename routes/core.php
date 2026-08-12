<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Controllers\AuthController;
use Modules\Core\Livewire\Admin\Companies;
use Modules\Core\Livewire\Admin\Departments;
use Modules\Core\Livewire\Admin\Factories;
use Modules\Core\Livewire\Admin\FactoryExitLocations;
use Modules\Core\Livewire\Admin\Divisions;
use Modules\Core\Livewire\Admin\Roles;
use Modules\Core\Livewire\Admin\Staffs;
use Modules\Core\Livewire\Admin\Users;
use Modules\Core\Livewire\Admin\WarehouseEntrances;
use Modules\Core\Livewire\Admin\Warehouses;
use Modules\Core\Livewire\Settings\Appearance;
use Modules\Core\Livewire\Settings\DataViews;
use Modules\Core\Livewire\Settings\ServiceTypes;
use Modules\Core\Livewire\Settings\Pages;
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
        // Factories sit with the rest of the organisation structure (company,
        // department, division, staff); the lines and projects beneath them
        // live under BIL > Machines.
        Route::get('/factories', Factories::class)->middleware('page:admin.factories')->name('admin.factories');
        // Warehouses are the storage sibling of factories: a company owns
        // both, and each owns the gates goods move through.
        Route::get('/warehouses', Warehouses::class)->middleware('page:admin.warehouses')->name('admin.warehouses');
        Route::get('/warehouse-entrances', WarehouseEntrances::class)->middleware('page:admin.warehouse_entrances')->name('admin.warehouse_entrances');
        Route::get('/exit-locations', FactoryExitLocations::class)->middleware('page:admin.factory_exit_locations')->name('admin.factory_exit_locations');
        Route::get('/divisions', Divisions::class)->middleware('page:admin.divisions')->name('admin.divisions');
        Route::get('/staff', Staffs::class)->middleware('page:admin.staff')->name('admin.staff');
        Route::get('/roles', Roles::class)->middleware('page:admin.roles')->name('admin.roles');
        Route::get('/users', Users::class)->middleware('page:admin.users')->name('admin.users');

        // Generic DataGrid print — resolves the grid class by its pageKey.
        Route::get('/grid/{page}/print', function (string $page) {
            $class = collect(config('datagrid.grids'))
                ->first(fn ($c) => (new $c)->pageKey() === $page);
            abort_unless($class, 404);
            abort_unless(
                (bool) request()->user()?->canDo(str_replace('-', '_', $page), 'export'),
                403
            );

            $payload = (new $class)->printPayload(request('view'), (string) request('search', ''));

            return view('core::print.grid', $payload);
        })->name('admin.grid.print');
    });

    // Appearance is a personal preference — any authenticated user. Data Views
    // is admin configuration — gated.
    Route::get('/settings/appearance', Appearance::class)->name('settings.appearance');
    Route::get('/settings/pages', Pages::class)->middleware('page:settings.pages')->name('settings.pages');
    Route::get('/settings/data-views', DataViews::class)->middleware('page:settings.data_views')->name('settings.data-views');

    Route::get('/settings/service-types', ServiceTypes::class)->middleware('page:settings.service_types')->name('settings.service-types');

    // Shift windows — access granted per page (settings.shifts). Give the
    // Shift Settings page to Admin + an Operations Manager role, etc.
    Route::get('/settings/shifts', ShiftSettings::class)->middleware('page:settings.shifts')->name('settings.shifts');
});
