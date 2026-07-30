<?php

namespace Modules\Core\Support;

use Illuminate\Support\Carbon;
use Modules\Core\Models\ShiftContext;

/**
 * Resolves whether a shift-gated area is open at a given moment, which window
 * is current, and when it next opens. All times are evaluated in the app
 * timezone (config('app.timezone'), e.g. Africa/Lagos).
 *
 * Open semantics:
 *   - unknown context, or is_active = false  -> OPEN (ungated)
 *   - is_active = true                        -> OPEN only inside an enabled window
 */
class ShiftService
{
    /** Cache resolved contexts for the request so repeated status() calls are cheap. */
    protected array $cache = [];

    public function context(string $key): ?ShiftContext
    {
        return $this->cache[$key] ??= ShiftContext::with('windows')->where('key', $key)->first();
    }

    public function isOpen(string $key, ?Carbon $at = null): bool
    {
        return $this->status($key, $at)['open'];
    }

    /**
     * Full status for a context key:
     *   configured, gated, open, current (window name|null),
     *   next_open_at (Carbon|null), next_window (name|null), label, windows[]
     */
    public function status(string $key, ?Carbon $at = null): array
    {
        $at ??= Carbon::now(config('app.timezone'));
        $ctx = $this->context($key);

        $base = [
            'key' => $key,
            'label' => $ctx?->label ?? $key,
            'configured' => (bool) $ctx,
            'gated' => (bool) $ctx?->is_active,
            'open' => true,
            'current' => null,
            'next_open_at' => null,
            'next_window' => null,
            'windows' => $ctx ? $ctx->windows->map(fn ($w) => [
                'name' => $w->name,
                'start' => substr((string) $w->start_time, 0, 5),
                'end' => substr((string) $w->end_time, 0, 5),
                'enabled' => $w->is_enabled,
            ])->all() : [],
        ];

        // Ungated (no context or master switch off) -> always open.
        if (! $ctx || ! $ctx->is_active) {
            return $base;
        }

        $enabled = $ctx->windows->where('is_enabled', true);

        foreach ($enabled as $w) {
            if ($w->containsAt($at)) {
                return ['open' => true, 'current' => $w->name] + $base;
            }
        }

        // Closed: find the soonest upcoming window start.
        $next = null;
        $nextName = null;
        foreach ($enabled as $w) {
            $start = $w->nextStartAfter($at);
            if ($next === null || $start->lt($next)) {
                $next = $start;
                $nextName = $w->name;
            }
        }

        return [
            'open' => false,
            'current' => null,
            'next_open_at' => $next,
            'next_window' => $nextName,
        ] + $base;
    }
}
