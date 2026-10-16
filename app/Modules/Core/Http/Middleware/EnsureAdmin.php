<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only gate: ->middleware('admin').
 *
 * For the few pages that must never be delegated. A page in the registry
 * (config/pages.php + `page:` middleware) can be granted to any role from the
 * Role matrix; a route behind this one cannot — it is not in the registry, so
 * there is nothing to grant. Admin = a role with legacy_level 1.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->isAdmin(), 403);

        return $next($request);
    }
}
