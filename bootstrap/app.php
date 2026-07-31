<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Per-page access gate: ->middleware('page:{page.key}')
        $middleware->alias([
            'page' => \Modules\Core\Http\Middleware\EnsurePageAccess::class,
        ]);

        // Display-only date-format preference is set client-side (settings.js)
        // and must be read server-side (reports/exports), so it stays plaintext.
        $middleware->encryptCookies(except: [
            \Modules\Core\Support\Prefs::COOKIE,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
