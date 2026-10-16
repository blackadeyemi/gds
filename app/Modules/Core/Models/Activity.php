<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One user action, as the activity log recorded it. Read-only from the app:
 * rows are written by ActivityRecorder and removed only by gds:prune-activity.
 *
 * Visibility follows page access — see scopeVisibleTo().
 */
class Activity extends Model
{
    protected $connection = 'core';
    protected $table = 'activities';
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['created_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(ActivityItem::class)->orderBy('id');
    }

    /**
     * What a user may see: Admin everything; anyone else the activity of pages
     * they can open. Sign-ins and changes made off any page (page_key NULL)
     * are Admin only.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $q;
        }

        return $q->whereIn('activities.page_key', $user->accessiblePageKeys() ?: ['']);
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->isAdmin()
            || ($this->page_key !== null && in_array($this->page_key, $user->accessiblePageKeys(), true));
    }
}
