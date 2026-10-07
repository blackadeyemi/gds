<?php

namespace Modules\Core\Support\Activity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Settings;

/**
 * How long the activity log is kept — one place for the settings page and
 * `gds:prune-activity` to agree on.
 *
 * An Admin's choice (Settings → Activity Log, stored in app_settings) wins over
 * config('activity.retention_days'); see Support\Settings for that layering.
 */
class Retention
{
    public const KEY = 'activity.retention_days';

    public static function days(): int
    {
        return self::clamp((int) Settings::get(self::KEY, 730));
    }

    public static function defaultDays(): int
    {
        return self::clamp((int) Settings::configured(self::KEY, 730));
    }

    public static function min(): int
    {
        return (int) config('activity.retention_days_min', 30);
    }

    public static function max(): int
    {
        return (int) config('activity.retention_days_max', 3650);
    }

    /** Entries created before this are due for deletion. */
    public static function cutoff(?int $days = null): Carbon
    {
        return now()->subDays($days ?? self::days())->startOfDay();
    }

    /** How many entries a prune at `$days` would delete right now. */
    public static function dueCount(?int $days = null): int
    {
        return DB::connection('core')->table('activities')
            ->where('created_at', '<', self::cutoff($days))
            ->count();
    }

    /** A stored value outside the bounds (hand-edited row, changed config) is pulled back inside them. */
    private static function clamp(int $days): int
    {
        return max(self::min(), min(self::max(), $days ?: 730));
    }
}
