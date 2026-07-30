<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
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
    }
}
