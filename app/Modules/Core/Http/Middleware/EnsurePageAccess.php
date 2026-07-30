<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-page access gate. A route declares its page key —
 * ->middleware('page:bil.raw_materials.warehouse_entry') — and this allows the
 * request only if the user may open that page (Admin, or a permission that
 * includes it). Otherwise 403.
 */
class EnsurePageAccess
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        abort_unless((bool) $request->user()?->canAccessPage($key), 403);

        return $next($request);
    }
}
