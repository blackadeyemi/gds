<?php

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Models\Division;
use Modules\Core\Models\Factory;
use Modules\Core\Models\MachineLine;
use Modules\Core\Models\MachineProject;
use Modules\Core\Models\Staff;
use Modules\Core\Support\MachineMaps;

/**
 * Keeps the `machine_map_*` lookups in step with the machines hierarchy.
 *
 * Without this, adding a line or a staff member in gds leaves the legacy
 * triggers unable to resolve them: the row is written, the trigger recomputes
 * the id from the name, finds nothing, and stores NULL — discarding the correct
 * id gds had supplied. Nothing errors; the record simply belongs to nobody.
 *
 * Hooked on the MODEL rather than the screens that edit it, so a hierarchy
 * change made anywhere — a grid, a console command, a future import — keeps the
 * maps correct. The rebuild is a handful of rows and only fires on write.
 *
 * `saved` covers create and update (a RENAME changes what the map must contain);
 * `deleted` and `restored` cover soft deletes, which the builds filter on.
 */
class MachineMapsServiceProvider extends ServiceProvider
{
    /** Models whose changes invalidate a map. */
    private const WATCHED = [
        MachineLine::class,
        MachineProject::class,
        Factory::class,
        Division::class,
        Staff::class,
    ];

    public function boot(): void
    {
        foreach (self::WATCHED as $model) {
            foreach (['saved', 'deleted', 'restored'] as $event) {
                $model::{$event}(function ($record) use ($model) {
                    $this->refresh($model);
                });
            }
        }
    }

    /**
     * Rebuild the affected maps.
     *
     * Failure is logged, never thrown: the hierarchy edit itself succeeded, and
     * taking down a save because a lookup could not be refreshed would be a
     * worse outcome than a stale map — which `gds:check-machine-maps` reports
     * and `gds:rebuild-machine-maps` repairs.
     */
    private function refresh(string $model): void
    {
        $kinds = MachineMaps::kindsFor($model);

        if ($kinds === []) {
            return;
        }

        try {
            MachineMaps::rebuild($kinds);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
