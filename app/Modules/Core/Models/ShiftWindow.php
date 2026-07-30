<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One named time window (Day/Night/…) inside a shift context. `start_time` and
 * `end_time` are stored as TIME; a window whose start is after its end wraps
 * midnight (e.g. 19:00 → 07:00).
 */
class ShiftWindow extends Model
{
    protected $connection = 'core';
    protected $fillable = ['shift_context_id', 'name', 'start_time', 'end_time', 'is_enabled', 'sort_order'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function context(): BelongsTo
    {
        return $this->belongsTo(ShiftContext::class, 'shift_context_id');
    }

    /** Minutes-since-midnight for an "HH:MM(:SS)" time string. */
    protected static function minutesOf(string $time): int
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');
        return ((int) $h) * 60 + ((int) $m);
    }

    /** Does this window contain the given moment (midnight-wrap aware)? */
    public function containsAt(Carbon $at): bool
    {
        $now = $at->hour * 60 + $at->minute;
        $start = static::minutesOf((string) $this->start_time);
        $end = static::minutesOf((string) $this->end_time);

        if ($start === $end) {
            return false; // zero-length window is never open
        }

        return $start < $end
            ? ($now >= $start && $now < $end)
            : ($now >= $start || $now < $end); // wraps midnight
    }

    /** The next start of this window strictly after $at (today or tomorrow). */
    public function nextStartAfter(Carbon $at): Carbon
    {
        $start = static::minutesOf((string) $this->start_time);
        $candidate = $at->copy()->startOfDay()->addMinutes($start);

        return $candidate->gt($at) ? $candidate : $candidate->addDay();
    }
}
