<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reason waste was produced — "Bad Cuts", "Trims", "Setting of Machine".
 *
 * Configured in Settings → Waste. Every cause is offered under every origin:
 * a bad cut is a bad cut whether the paper came off a jumbo roll or out of raw
 * materials, so the two lists are independent of each other.
 */
class WasteCause extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $table = 'waste_causes';

    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** The order the entry form and the settings grid both list them in. */
    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }
}
