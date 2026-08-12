<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Bil\Console\ReconcileFinishedGoodsStock;
use Modules\Bil\Console\ReconcileWarehouseStock;
use Modules\Core\Console\MigrateLegacyAuth;
use Modules\Core\Console\SyncDataViews;
use Modules\Core\Console\SyncPages;
use Modules\Core\Console\SyncShiftContexts;

/**
 * Wires the Bil/Bpl/Core modules into the app: each module owns its own
 * Blade view namespace (core::, bil::, bpl::) and its route file. Bil and
 * Bpl routes are served under /bil and /bpl URL prefixes; core routes sit
 * at the web root (login, logout, dashboard).
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncDataViews::class, MigrateLegacyAuth::class, ReconcileWarehouseStock::class, ReconcileFinishedGoodsStock::class, SyncShiftContexts::class, SyncPages::class]);
        }

        $modules = base_path('app/Modules');

        $this->loadViewsFrom("$modules/Core/Views", 'core');
        $this->loadViewsFrom("$modules/Bil/Views", 'bil');
        $this->loadViewsFrom("$modules/Bpl/Views", 'bpl');

        Route::middleware('web')->group(base_path('routes/core.php'));

        Route::middleware('web')->prefix('bil')->name('bil.')
            ->group(base_path('routes/bil.php'));

        Route::middleware('web')->prefix('bpl')->name('bpl.')
            ->group(base_path('routes/bpl.php'));
    }
}
