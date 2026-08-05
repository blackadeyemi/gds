<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A member of factory staff — the people recorded against machine service jobs.
 * Migrated from the legacy bil.factory_staff, which is now a view over this.
 *
 * Distinct from User: these are shop-floor people, not logins, and none of the
 * 70 migrated rows matched an account. `user_id` is there for the ones who do
 * eventually get one.
 *
 * Names are NOT unique — "OTHERS" is a per-division placeholder that exists
 * four times — so service history resolves staff on (division, name).
 */
class Staff extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $table = 'staff';
    protected $fillable = ['staff_no', 'name', 'department_id', 'division_id', 'user_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'staff_no' => 'integer',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'userid');
    }
}
