<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Support\MachineMaps;

/**
 * Rebuild the `machine_map_*` lookups from the machines hierarchy.
 *
 * They are refreshed automatically whenever a line, project, factory, division
 * or staff member is saved, so this is for the two cases that bypass the models:
 *
 *   - after a production data refresh, once `bil` has been reloaded ("re-run
 *     the map build" in the deployment notes);
 *   - after a bulk import or a direct SQL edit of the hierarchy.
 *
 * Safe to run on a live system: each map is built alongside the old one and
 * swapped in with an atomic RENAME, so a legacy insert never sees it missing.
 */
class RebuildMachineMaps extends Command
{
    protected $signature = 'gds:rebuild-machine-maps
                            {--kind=* : Rebuild only these (line, project, subproject, factory, division, staff)}';

    protected $description = 'Rebuild the legacy name -> id lookups from the machines hierarchy';

    public function handle(): int
    {
        $kinds = (array) $this->option('kind');
        $unknown = array_diff($kinds, MachineMaps::KINDS);

        if ($unknown !== []) {
            $this->error('Unknown kind(s): ' . implode(', ', $unknown));
            $this->line('Valid: ' . implode(', ', MachineMaps::KINDS));

            return self::FAILURE;
        }

        try {
            $written = MachineMaps::rebuild($kinds ?: null);
        } catch (\Throwable $e) {
            $this->error('Rebuild failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($written as $kind => $rows) {
            $this->line(sprintf('  %-12s %s row(s)', $kind, number_format($rows)));
        }

        $this->newLine();
        $this->info(count($written) . ' map(s) rebuilt.');
        $this->line('Run `gds:check-machine-maps` to confirm every legacy name now resolves.');

        return self::SUCCESS;
    }
}
