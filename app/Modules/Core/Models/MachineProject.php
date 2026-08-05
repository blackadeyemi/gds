<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A project on a line, or a sub-project of one — same self-referencing shape as
 * MachineLine. `line_id` points at whichever line node owns it: usually a
 * sub-line, but two Gambini projects hang off the REW 11 line directly.
 */
class MachineProject extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $fillable = ['line_id', 'parent_id', 'name', 'code', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(MachineLine::class, 'line_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeRoots(Builder $q): Builder
    {
        return $q->whereNull('parent_id');
    }

    /** @see MachineLine::scopeTreeOrder() */
    public function scopeTreeOrder(Builder $q): Builder
    {
        return $q->orderByRaw('COALESCE(parent_id, id), (parent_id IS NULL) DESC, name');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }
}
