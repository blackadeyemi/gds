<?php

namespace Modules\Bil\Support;

/**
 * How long a service job stopped a machine.
 *
 * The legacy screen stored duration as JSON — {"d":0,"h":2,"m":30} — rather than
 * a number of minutes, and gds keeps writing it that way so the legacy reports
 * still work. Everything that needs to total or display a duration goes through
 * here, so the report and the statistics dashboard can't drift apart on what a
 * "total stop time" means.
 */
class ServiceDuration
{
    /**
     * Total minutes across a grouped query. Expects the maintenance table
     * aliased as `m` — the shape every consumer already uses.
     *
     * Reads `duration_minutes`, which the table's BEFORE INSERT/UPDATE triggers
     * keep in step with the JSON (migration 2026_08_07_120000). Parsing the JSON
     * per row instead cost ~500ms a query and made the all-time statistics tabs
     * unusable; `duration` is still the authoritative value.
     */
    public const MINUTES_SQL = 'SUM(COALESCE(m.duration_minutes, 0))';

    /** One row's duration, in minutes. */
    public const ROW_MINUTES_SQL = 'COALESCE(m.duration_minutes, 0)';

    /** Render a stored duration JSON value the way the legacy report did. */
    public static function human(?string $json): string
    {
        $d = json_decode((string) $json, true);

        if (! is_array($d)) {
            return '—';
        }

        return self::format(
            ((int) ($d['d'] ?? 0)) * 1440 + ((int) ($d['h'] ?? 0)) * 60 + (int) ($d['m'] ?? 0)
        );
    }

    /** "2 days, 6 hours, 36 minutes" — omitting the parts that are zero. */
    public static function format(int $minutes): string
    {
        if ($minutes <= 0) {
            return '—';
        }

        $parts = [];

        if ($days = intdiv($minutes, 1440)) {
            $parts[] = $days . ' day' . ($days > 1 ? 's' : '');
        }

        if ($hours = intdiv($minutes % 1440, 60)) {
            $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
        }

        if ($mins = $minutes % 60) {
            $parts[] = $mins . ' minute' . ($mins > 1 ? 's' : '');
        }

        return implode(', ', $parts);
    }

    /** Minutes as whole hours, for charting. */
    public static function hours(float $minutes): float
    {
        return round($minutes / 60);
    }
}
