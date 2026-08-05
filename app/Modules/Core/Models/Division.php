<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A division within a department — Maintenance splits into Electrical and
 * Mechanical, Conversion into Quality Control and Supervisor.
 *
 * `legacy_name` holds the full string the legacy app writes for this division
 * ("MAINTENANCE ELECTRICAL"): 43k factory_machine_maintenance rows contain it,
 * and the factory_staff compatibility view has to emit it. Divisions created
 * from the UI have none, and resolve on `name` instead.
 */
class Division extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $fillable = ['department_id', 'name', 'legacy_name', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'division_id');
    }

    /** What the legacy tables know this division as. */
    public function legacyLabel(): string
    {
        return $this->legacy_name ?: $this->name;
    }
}
