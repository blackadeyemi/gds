<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Models\DataPage;
use Modules\Core\Models\DataView;

/**
 * Upserts data_pages / data_views from each registered DataGrid's code-declared
 * views (config/datagrid.php). Admin overrides (is_enabled, is_default, per_page)
 * are preserved for views/pages that already exist; newly declared views are
 * added enabled, and views no longer declared are removed.
 */
class SyncDataViews extends Command
{
    protected $signature = 'gds:sync-data-views';
    protected $description = 'Sync DataGrid pages/views from code into the admin config tables';

    public function handle(): int
    {
        foreach (config('datagrid.grids', []) as $class) {
            $grid = new $class;
            $key = $grid->pageKey();

            $page = DataPage::firstOrNew(['key' => $key]);
            $page->label = $grid->pageLabel();
            $page->per_page = $page->per_page ?: 10;
            $page->save();

            $declared = $grid->views();
            $order = 0;
            foreach ($declared as $viewKey => $def) {
                $view = DataView::firstOrNew(['data_page_id' => $page->id, 'key' => $viewKey]);
                $view->label = $def['label'];
                $view->sort_order = $order;
                if (! $view->exists) {
                    $view->is_enabled = true;
                    $view->is_default = ($order === 0);
                }
                $view->save();
                $order++;
            }

            // Prune views no longer declared in code.
            DataView::where('data_page_id', $page->id)
                ->whereNotIn('key', array_keys($declared))
                ->delete();

            // Guarantee exactly one default among enabled views.
            $this->ensureDefault($page);

            $this->line("  synced <info>{$key}</info> (" . count($declared) . ' views)');
        }

        $this->info('DataGrid views synced.');
        return self::SUCCESS;
    }

    protected function ensureDefault(DataPage $page): void
    {
        $enabled = $page->views()->where('is_enabled', true)->get();
        if ($enabled->isEmpty()) return;
        if ($enabled->where('is_default', true)->count() === 1) return;

        $page->views()->update(['is_default' => false]);
        $enabled->first()->update(['is_default' => true]);
    }
}
