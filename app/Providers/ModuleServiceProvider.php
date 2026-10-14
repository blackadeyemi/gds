<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Bil\Console\BackfillFinishedGoodsReceipts;
use Modules\Bil\Console\CloseStaleLoadings;
use Modules\Bil\Console\RefreshFinishedGoodsOrderFrequency;
use Modules\Bil\Console\SeedFinishedGoodsStock;
use Modules\Bil\Console\ReconcileFinishedGoodsStock;
use Modules\Bil\Console\ReconcileRawMaterialsStock;
use Modules\Bil\Console\ReconcileWarehouseStock;
use Modules\Core\Console\CheckMachineMaps;
use Modules\Core\Console\MigrateLegacyAuth;
use Modules\Core\Console\ProvisionBhnRbac;
use Modules\Core\Console\PruneActivity;
use Modules\Core\Console\RebuildMachineMaps;
use Modules\Core\Console\RefreshLegacy;
use Modules\Core\Console\ReplayBackfills;
use Modules\Core\Console\SyncDataViews;
use Modules\Core\Console\SyncPages;
use Modules\Core\Console\SyncShiftContexts;
use Modules\Core\Support\Activity\ActivityRecorder;

/**
 * Wires the Bil/Bpl/Bhn/Core modules into the app: each module owns its own
 * Blade view namespace (core::, bil::, bpl::, bhn::) and its route file. Bil,
 * Bpl and Bhn routes are served under /bil, /bpl and /bhn URL prefixes; core
 * routes sit at the web root (login, logout, dashboard).
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
            $this->commands([SyncDataViews::class, MigrateLegacyAuth::class, CheckMachineMaps::class, RebuildMachineMaps::class, ReconcileWarehouseStock::class, ReconcileFinishedGoodsStock::class, BackfillFinishedGoodsReceipts::class, SeedFinishedGoodsStock::class, RefreshFinishedGoodsOrderFrequency::class, ReconcileRawMaterialsStock::class, CloseStaleLoadings::class, SyncShiftContexts::class, SyncPages::class, RefreshLegacy::class, ReplayBackfills::class, PruneActivity::class, ProvisionBhnRbac::class]);
        }

        // Every change a signed-in user makes is logged, from any page. See
        // Modules\Core\Support\Activity\ActivityRecorder.
        ActivityRecorder::register();

        $modules = base_path('app/Modules');

        $this->loadViewsFrom("$modules/Core/Views", 'core');
        $this->loadViewsFrom("$modules/Bil/Views", 'bil');
        $this->loadViewsFrom("$modules/Bpl/Views", 'bpl');
        $this->loadViewsFrom("$modules/Bhn/Views", 'bhn');

        Route::middleware('web')->group(base_path('routes/core.php'));

        Route::middleware('web')->prefix('bil')->name('bil.')
            ->group(base_path('routes/bil.php'));

        Route::middleware('web')->prefix('bpl')->name('bpl.')
            ->group(base_path('routes/bpl.php'));

        Route::middleware('web')->prefix('bhn')->name('bhn.')
            ->group(base_path('routes/bhn.php'));
    }
}
