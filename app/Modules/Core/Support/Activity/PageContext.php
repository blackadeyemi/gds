<?php

namespace Modules\Core\Support\Activity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\Page;

/**
 * Which page a change was made on.
 *
 * Every gated route already names its page in its middleware (`page:{key}`),
 * so the map is read off the router rather than declared anywhere: a new page
 * is covered the moment it has a route.
 *
 * A Livewire update request arrives at /livewire/update, not at the page, so
 * the page is found from the component's class (full-page components ARE the
 * route's action) or, for a nested component, from the URL the page was
 * opened at (Livewire::originalPath()).
 */
class PageContext
{
    /** @var array{byClass: array<class-string,array>, byKey: array<string,string>}|null */
    private static ?array $routes = null;

    /** @var array<string,array{label:string,module:?string}>|null */
    private static ?array $pages = null;

    /**
     * @return array{page_key:?string, route:?string}
     */
    public static function forComponent(object $component): array
    {
        $map = self::routes();

        if ($hit = $map['byClass'][$component::class] ?? null) {
            return $hit;
        }

        return self::forPath(\Livewire\Livewire::originalPath());
    }

    /** @return array{page_key:?string, route:?string} */
    public static function forRequest(): array
    {
        if (\Livewire\Livewire::isLivewireRequest()) {
            return self::forPath(\Livewire\Livewire::originalPath());
        }

        $route = request()->route();

        return $route ? self::describe($route) : ['page_key' => null, 'route' => null];
    }

    /** @return array{page_key:?string, route:?string} */
    public static function forPath(?string $path): array
    {
        if (! $path || $path === 'POST') {
            return ['page_key' => null, 'route' => null];
        }

        try {
            $route = Route::getRoutes()->match(Request::create('/' . ltrim($path, '/'), 'GET'));
        } catch (\Throwable) {
            return ['page_key' => null, 'route' => null];
        }

        return self::describe($route);
    }

    /** Label + module of a page key, from the `pages` table. */
    public static function page(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        self::$pages ??= Cache::remember('activity:pages', 600, fn () => Page::query()
            ->get(['key', 'label', 'module'])
            ->mapWithKeys(fn ($p) => [$p->key => ['label' => $p->label, 'module' => $p->module]])
            ->all());

        if (isset(self::$pages[$key])) {
            return self::$pages[$key];
        }

        // An admin-only route (not in the registry) is system configuration:
        // it files under Settings, which also routes its changes to the other
        // Admins' bells (activity.admin_modules).
        $route = Route::getRoutes()->getByName($key);
        if ($route && in_array('admin', $route->gatherMiddleware(), true)) {
            return self::$pages[$key] = [
                'label' => \Illuminate\Support\Str::headline(\Illuminate\Support\Str::afterLast($key, '.')),
                'module' => 'Settings',
            ];
        }

        return null;
    }

    /** The route to open for a page key, or null. */
    public static function url(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $name = self::routes()['byKey'][$key] ?? null;

        try {
            return $name ? route($name) : null;
        } catch (\Throwable) {
            return null; // a route with parameters cannot be linked bare
        }
    }

    /** For tests: the router changed. */
    public static function forget(): void
    {
        self::$routes = null;
        self::$pages = null;
        Cache::forget('activity:pages');
    }

    /** @return array{page_key:?string, route:?string} */
    private static function describe($route): array
    {
        return [
            'page_key' => self::pageKeyOf($route),
            'route' => $route->getName(),
        ];
    }

    /**
     * The route's page key: its `page:` middleware, or — for an admin-only
     * route, which is deliberately not in the page registry — its route name.
     * No non-admin can open such a key, so its activity is Admin-only reading.
     */
    private static function pageKeyOf($route): ?string
    {
        $middleware = $route->gatherMiddleware();
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'page:')) {
                return substr($m, 5);
            }
        }

        return in_array('admin', $middleware, true) ? $route->getName() : null;
    }

    private static function routes(): array
    {
        if (self::$routes !== null) {
            return self::$routes;
        }

        $byClass = [];
        $byKey = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $key = self::pageKeyOf($route);
            $action = $route->getActionName();
            if (class_exists($action) && is_subclass_of($action, \Livewire\Component::class)) {
                $byClass[$action] ??= ['page_key' => $key, 'route' => $route->getName()];
            }
            if ($key !== null && $route->getName() && ! str_contains($route->uri(), '{')) {
                $byKey[$key] ??= $route->getName();
            }
        }

        return self::$routes = ['byClass' => $byClass, 'byKey' => $byKey];
    }
}
