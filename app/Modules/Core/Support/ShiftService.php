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
     * The enabled windows of a context, in order, as ['name','start','end'].
     * Empty when the context has never been synced.
     *
     * Unlike status(), this ignores `is_active`. Some callers need to know what
     * the windows SAY without being gated by them: Conversion Output stamps a
     * pallet with the shift it was made on whether or not the area is enforced,
     * and the waste run is keyed on that same value. Turning enforcement on and
     * off must not change which shift a pallet belongs to.
     */
    public function windows(string $key): array
    {
        $ctx = $this->context($key);

        if (! $ctx) {
            return [];
        }

        return $ctx->windows->where('is_enabled', true)
            ->sortBy('sort_order')
            ->map(fn ($w) => [
                'name' => $w->name,
                'start' => substr((string) $w->start_time, 0, 5),
                'end' => substr((string) $w->end_time, 0, 5),
            ])->values()->all();
    }

    /**
     * Which window a moment falls in, regardless of `is_active` — or null when
     * the context is unconfigured or the moment falls in no window.
     */
    public function windowAt(string $key, ?Carbon $at = null): ?array
    {
        $at ??= Carbon::now(config('app.timezone'));
        $ctx = $this->context($key);

        if (! $ctx) {
            return null;
        }

        foreach ($ctx->windows->where('is_enabled', true)->sortBy('sort_order') as $w) {
            if ($w->containsAt($at)) {
                return [
                    'name' => $w->name,
                    'start' => substr((string) $w->start_time, 0, 5),
                    'end' => substr((string) $w->end_time, 0, 5),
                ];
            }
        }

        return null;
    }

    /**
     * `HH:MM` at which this context's day begins — the start of its first
     * window, which is the boundary a production date rolls over on.
     */
    public function dayBoundary(string $key): ?string
    {
        return $this->windows($key)[0]['start'] ?? null;
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
