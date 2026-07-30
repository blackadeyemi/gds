<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Models\Page;

/**
 * Upserts the page registry (config/pages.php) into the `pages` table. Labels
 * and module groupings are refreshed each run; pages dropped from the registry
 * are removed (their permission links cascade). Run whenever pages are added.
 */
class SyncPages extends Command
{
    protected $signature = 'gds:sync-pages';
    protected $description = 'Sync gated pages from config/pages.php into the pages table';

    public function handle(): int
    {
        $declared = config('pages.pages', []);
        $keys = [];

        foreach ($declared as $i => $def) {
            $keys[] = $def['key'];
            $page = Page::firstOrNew(['key' => $def['key']]);
            $page->label = $def['label'];
            $page->module = $def['module'] ?? null;
            $page->sort_order = $i;
            $page->save();
        }

        $removed = Page::whereNotIn('key', $keys)->get();
        foreach ($removed as $page) {
            $page->delete(); // permission_page rows cascade
            $this->line("  removed <comment>{$page->key}</comment>");
        }

        $this->info('Pages synced (' . count($keys) . ' declared).');
        return self::SUCCESS;
    }
}
