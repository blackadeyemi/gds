<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\ModuleContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves which company module the request operates as and, for the non-default
 * ones (BHN), rebinds the `bil` connection + `bil` config to that company's — so
 * the reused Bil models/components read and write the right schema.
 *
 * Runs on EVERY web request, because a Livewire interaction on a /bhn page POSTs
 * to /livewire/update (not /bhn/...), so the URL prefix alone would lose the
 * context on every click. For those we read the module from the component's
 * snapshot path, which Livewire round-trips.
 */
class ResolveCompanyModule
{
    public function handle(Request $request, Closure $next): Response
    {
        $module = $this->resolve($request);

        if ($module !== 'bil') {
            // Rebind the connection the Bil module uses to the target company's.
            config(['database.connections.bil' => config("database.connections.{$module}")]);
            DB::purge('bil');

            // Rebind the Bil module's own settings (company_code, jumbo customer…).
            config(['bil' => config($module)]);
        }

        ModuleContext::set($module);

        return $next($request);
    }

    /** 'bil' (default) or another known module. */
    protected function resolve(Request $request): string
    {
        // Livewire updates POST to /livewire/update; the originating page lives
        // in each component snapshot's memo.path, which Livewire round-trips.
        if ($request->is('livewire/update')) {
            foreach ((array) $request->input('components', []) as $component) {
                $snapshot = $component['snapshot'] ?? null;
                if (! is_string($snapshot)) {
                    continue;
                }
                $memo = json_decode($snapshot, true)['memo'] ?? [];
                $path = (string) ($memo['path'] ?? '');
                if ($m = $this->moduleFromPath($path)) {
                    return $m;
                }
            }

            return 'bil';
        }

        return $this->moduleFromPath($request->path()) ?: 'bil';
    }

    /** First path segment, if it names a known non-default module. */
    protected function moduleFromPath(string $path): ?string
    {
        $segment = strtolower(explode('/', ltrim(parse_url($path, PHP_URL_PATH) ?: $path, '/'))[0] ?? '');

        return ($segment !== 'bil' && in_array($segment, ModuleContext::MODULES, true)) ? $segment : null;
    }
}
