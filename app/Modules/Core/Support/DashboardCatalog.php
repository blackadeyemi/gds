<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\Route;
use Modules\Core\Livewire\StatisticsPage;
use Modules\Core\Models\Page;
use Modules\Core\Models\User;

/**
 * What the home dashboard shows a user: one card per module they can see
 * statistics or reports for, built from the page registry and their grants.
 *
 * Nothing here is hard-coded per module. A module joins the dashboard by
 * having a `*.statistics` page (whose route resolves to a StatisticsPage) or
 * pages under "<Module> Reports"; a role sees a card only for the pages it
 * holds `:view` on. Admin pages and settings never appear.
 */
class DashboardCatalog
{
    /**
     * @return array<string, array<int, array{module:string, name:string, stats:?string, statsUrl:?string, reports:array}>>
     *         company label (BIL, BPL) => module cards, in registry order
     */
    public static function for(User $user): array
    {
        $allowed = array_flip($user->accessiblePageKeys());
        $routes = self::routeMap();

        $modules = [];
        foreach (Page::orderBy('sort_order')->orderBy('id')->get(['key', 'label', 'module']) as $page) {
            if (! isset($allowed[$page->key]) || ! str_contains((string) $page->module, ' / ')) {
                continue;
            }

            $isReport = str_ends_with($page->module, ' Reports');
            $module = $isReport ? substr($page->module, 0, -strlen(' Reports')) : $page->module;
            $route = $routes[$page->key] ?? null;

            if ($isReport) {
                if ($route) {
                    $modules[$module]['reports'][] = ['label' => $page->label, 'url' => route($route)];
                }
            } elseif (str_ends_with($page->key, '.statistics') && self::statisticsClass($page->key)) {
                $modules[$module]['stats'] = $page->key;
                $modules[$module]['statsUrl'] = route($route);
            }
        }

        $grouped = [];
        foreach ($modules as $module => $card) {
            [$company, $name] = explode(' / ', $module, 2);
            $grouped[$company][] = [
                'module' => $module,
                'name' => $name,
                'stats' => $card['stats'] ?? null,
                'statsUrl' => $card['statsUrl'] ?? null,
                'reports' => $card['reports'] ?? [],
            ];
        }

        return $grouped;
    }

    /**
     * The working screens a user can open — entry forms, queues, registers —
     * grouped by module: everything they hold `:view` on that is not a
     * statistics page or a report (those have their own cards). Admin and
     * Settings come last, under "System".
     *
     * @return array<int, array{company:string, name:string, pages:array<int, array{label:string, url:string}>}>
     */
    public static function workFor(User $user): array
    {
        $allowed = array_flip($user->accessiblePageKeys());
        $routes = self::routeMap();

        $groups = [];
        foreach (Page::orderBy('sort_order')->orderBy('id')->get(['key', 'label', 'module']) as $page) {
            $module = (string) $page->module;
            $route = $routes[$page->key] ?? null;

            if (! $route || ! isset($allowed[$page->key])
                || str_ends_with($module, ' Reports') || str_ends_with($page->key, '.statistics')) {
                continue;
            }

            [$company, $name] = str_contains($module, ' / ') ? explode(' / ', $module, 2) : ['System', $module];
            $groups[$module] ??= ['company' => $company, 'name' => $name, 'pages' => []];
            $groups[$module]['pages'][] = ['label' => $page->label, 'url' => route($route)];
        }

        // Company modules in registry order, then System.
        uasort($groups, fn ($a, $b) => ($a['company'] === 'System') <=> ($b['company'] === 'System'));

        return array_values($groups);
    }

    /**
     * The StatisticsPage class behind a page key, or null when the key is not
     * a statistics page with a live route. Resolved from the route, so the
     * caller can never name an arbitrary class.
     *
     * @return class-string<StatisticsPage>|null
     */
    public static function statisticsClass(string $pageKey): ?string
    {
        if (! str_ends_with($pageKey, '.statistics')) {
            return null;
        }

        $route = self::routeMap()[$pageKey] ?? null;
        $class = $route ? Route::getRoutes()->getByName($route)?->getAction('controller') : null;

        return is_string($class) && is_subclass_of($class, StatisticsPage::class) ? $class : null;
    }

    /** Page key => route name, from the registry, falling back to the key with `_` → `-`. */
    private static function routeMap(): array
    {
        static $map = null;

        if ($map === null) {
            $map = [];
            foreach (config('pages.pages', []) as $p) {
                if (! empty($p['route'])) {
                    $map[$p['key']] = $p['route'];
                }
            }
            foreach (Page::pluck('key') as $key) {
                $map[$key] ??= str_replace('_', '-', $key);
            }
            $map = array_filter($map, fn ($r) => Route::has($r));
        }

        return $map;
    }
}
