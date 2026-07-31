<?php

namespace Modules\Core\Support;

use Modules\Core\Models\Page;
use Modules\Core\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps the pages table and the "{key}:{ability}" permissions in step with the
 * code registry (config/pages.php) and the admin-managed Pages UI.
 *
 * config/pages.php provides page metadata and the DEFAULT abilities for NEW
 * pages; once a page exists its abilities are admin-owned (edited in the Pages
 * settings UI) and preserved across syncs. Permissions are always regenerated
 * from each page's current abilities.
 */
class PageSyncer
{
    /**
     * Reconcile pages with the registry: add new pages (default abilities),
     * refresh label/module/sort for existing ones (preserving their abilities),
     * prune pages no longer declared, then regenerate permissions.
     *
     * @return array{added:int,total:int}
     */
    public function sync(): array
    {
        $declared = config('pages.pages', []);
        $keys = [];
        $added = 0;

        foreach ($declared as $i => $def) {
            $keys[] = $def['key'];
            $page = Page::firstOrNew(['key' => $def['key']]);
            $isNew = ! $page->exists;

            $page->label = $def['label'];
            $page->module = $def['module'] ?? null;
            $page->sort_order = $i;
            if ($isNew) {
                $page->abilities = array_values($def['abilities'] ?? ['view']);
                $added++;
            }
            $page->save();
        }

        Page::whereNotIn('key', $keys)->delete();
        $this->regeneratePermissions();

        return ['added' => $added, 'total' => count($keys)];
    }

    /** Insert only pages present in the registry but missing from the DB. */
    public function discoverNew(): int
    {
        $existing = Page::pluck('key')->all();
        $added = 0;

        foreach (config('pages.pages', []) as $i => $def) {
            if (in_array($def['key'], $existing, true)) {
                continue;
            }
            Page::create([
                'key' => $def['key'],
                'label' => $def['label'],
                'module' => $def['module'] ?? null,
                'abilities' => array_values($def['abilities'] ?? ['view']),
                'sort_order' => $i,
            ]);
            $added++;
        }

        if ($added > 0) {
            $this->regeneratePermissions();
        }

        return $added;
    }

    /** Ensure a permission exists for every page ability; prune stale ones. */
    public function regeneratePermissions(): void
    {
        $names = [];
        foreach (Page::all() as $page) {
            foreach ($page->abilities ?? [] as $ability) {
                $name = $page->key . ':' . $ability;
                $names[] = $name;
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
        }

        Permission::query()
            ->where('name', 'like', '%:%')
            ->when($names, fn ($q) => $q->whereNotIn('name', $names))
            ->get()
            ->each(fn ($p) => $p->delete());

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /** The code-declared default abilities for a page key. */
    public function defaultAbilities(string $key): array
    {
        foreach (config('pages.pages', []) as $def) {
            if ($def['key'] === $key) {
                return array_values($def['abilities'] ?? ['view']);
            }
        }

        return ['view'];
    }
}
