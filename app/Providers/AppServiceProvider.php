<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Emit https for asset()/url()/route() whenever the app is configured
        // for a secure root — production, or the local https gds.test vhost.
        // This covers URLs built outside a request (CLI, queues, mail) where
        // the request scheme can't be inferred, avoiding mixed-content links.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // @canPage('bil.raw_materials.products') … @endcanPage — nav/link gating
        // by page key (mirrors the `page:` route middleware).
        Blade::if('canPage', fn (string $key) => (bool) auth()->user()?->canAccessPage($key));

        // @canPrefix('bil.raw_materials.') — true if the user can access ANY
        // page whose key starts with the prefix. For nav group wrappers.
        Blade::if('canPrefix', function (string $prefix) {
            return collect(auth()->user()?->accessiblePageKeys() ?? [])
                ->contains(fn ($k) => str_starts_with($k, $prefix));
        });

        // @pageCan('bil.raw_materials.factory_entrance', 'backdate') — a specific
        // ability on a page (mirrors User::canDo). For action buttons/fields.
        Blade::if('pageCan', fn (string $key, string $ability) => (bool) auth()->user()?->canDo($key, $ability));

        // Admin (role legacy_level 1) passes every gate — including native
        // ->can() checks for the "{key}:{ability}" permissions they're never
        // explicitly granted. Mirrors the isAdmin bypass in User::canDo.
        Gate::before(fn ($user) => (method_exists($user, 'isAdmin') && $user->isAdmin()) ? true : null);
    }
}
