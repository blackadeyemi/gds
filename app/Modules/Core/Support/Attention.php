<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\User;

/**
 * The dashboard's "Needs attention" strip: things waiting on someone.
 *
 * Each module declares its own items in a source class listed in
 * `config('dashboard.attention')`; Core only collects them. A source returns
 * item arrays:
 *
 *   'key'     unique id, also the cache key
 *   'page'    page-registry key the item is gated on
 *   'ability' ability on that page (default 'view'; 'approve' for approval queues)
 *   'label'   what the number counts, e.g. "Factory returns to approve"
 *   'route'   route name the card opens, 'query' optional query string for it
 *   'resolve' fn () => ['count' => int, 'detail' => ?string, 'tone' => 'danger'|'warn'|'info']
 *
 * Counts are company-wide (the same for everyone allowed to see them), so each
 * is cached for TTL seconds and shared across users.
 */
class Attention
{
    public const TTL = 300;

    /** Severity order for the cards, worst first. */
    public const TONES = ['danger' => 0, 'warn' => 1, 'info' => 2];

    /** The items this user may see. Cheap: no counts are run. */
    public static function itemsFor(User $user): array
    {
        $items = [];
        foreach (config('dashboard.attention', []) as $source) {
            foreach ($source::items() as $item) {
                if ($user->canDo($item['page'], $item['ability'] ?? 'view')) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * Run one item's count (cached). Null when it failed — one broken query
     * must not take the strip down.
     */
    public static function resolve(array $item): ?array
    {
        try {
            $r = Cache::remember('dashboard:attention:' . $item['key'], self::TTL, $item['resolve']);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return [
            'key' => $item['key'],
            'label' => $item['label'],
            'count' => (int) ($r['count'] ?? 0),
            'detail' => $r['detail'] ?? null,
            'tone' => $r['tone'] ?? 'info',
            'url' => route($item['route'], $item['query'] ?? []),
        ];
    }
}
