<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "This activity concerns you", one row per recipient. See ActivityNotifier. */
class ActivityNotification extends Model
{
    protected $connection = 'core';
    protected $table = 'activity_notifications';
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['read_at' => 'datetime', 'created_at' => 'datetime'];

    public const REASONS = [
        'admin' => 'Admin',
        'approval' => 'Needs your approval',
        'your_record' => 'Your entry changed',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public static function unreadCount(int $userId): int
    {
        return static::where('user_id', $userId)->whereNull('read_at')->count();
    }
}
