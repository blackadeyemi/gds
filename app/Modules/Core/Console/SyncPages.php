<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Models\Page;
use Modules\Core\Models\Permission;

/**
 * Syncs the page registry (config/pages.php) into the `pages` table and
 * materializes every page+ability as a "{key}:{ability}" permission. Pages and
 * ability-permissions dropped from the registry are removed (role grants
 * cascade). Run whenever pages or their abilities change.
 */
class SyncPages extends Command
{
    protected $signature = 'gds:sync-pages';
    protected $description = 'Sync gated pages + their abilities into pages/permissions';

    public function handle(): int
    {
        $declared = config('pages.pages', []);
        $keys = [];
        $abilityPerms = [];

        foreach ($declared as $i => $def) {
            $keys[] = $def['key'];
            $abilities = $def['abilities'] ?? ['view'];

            $page = Page::firstOrNew(['key' => $def['key']]);
            $page->label = $def['label'];
            $page->module = $def['module'] ?? null;
            $page->abilities = array_values($abilities);
            $page->sort_order = $i;
            $page->save();

            foreach ($abilities as $ability) {
                $name = $def['key'] . ':' . $ability;
                $abilityPerms[] = $name;
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
        }

        // Prune pages and ability-permissions no longer declared.
        Page::whereNotIn('key', $keys)->delete();

        $stale = Permission::query()
            ->where('name', 'like', '%:%')
            ->whereNotIn('name', $abilityPerms)
            ->get();
        foreach ($stale as $perm) {
            $perm->delete(); // role_has_permissions cascades
        }

        if (app()->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $this->info('Pages synced (' . count($keys) . ' pages, ' . count($abilityPerms) . ' ability permissions).');
        return self::SUCCESS;
    }
}
