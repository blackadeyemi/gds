<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Support\PageSyncer;

/**
 * Reconcile the pages table + "{key}:{ability}" permissions with the code
 * registry (config/pages.php). Adds new pages, prunes removed ones, and
 * regenerates permissions — preserving admin-edited abilities (see PageSyncer).
 * Day-to-day, abilities are managed in the Pages settings UI.
 */
class SyncPages extends Command
{
    protected $signature = 'gds:sync-pages';
    protected $description = 'Sync gated pages + their ability permissions from config/pages.php';

    public function handle(PageSyncer $syncer): int
    {
        $result = $syncer->sync();

        $this->info("Pages synced ({$result['total']} pages, {$result['added']} new).");
        return self::SUCCESS;
    }
}
