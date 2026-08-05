<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A production line, or a sub-line of one — the same table either way, via a
 * self-referencing parent_id. Legacy kept these in two tables (factory_lines /
 * factory_sublines) but the consumer columns never distinguished them:
 * factory_usage_rawmaterials.linename holds a line name in ~80k rows and a
 * sub-line name in ~20k. One table means one lookup resolves both.
 *
 * `name` is globally unique across both levels, which the compatibility views
 * and the legacy name-joins depend on.
 */
class MachineLine extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $fillable = ['factory_id', 'parent_id', 'name', 'code', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(MachineProject::class, 'line_id');
    }

    public function scopeRoots(Builder $q): Builder
    {
        return $q->whereNull('parent_id');
    }

    /**
     * Order so each parent is immediately followed by its own children.
     * COALESCE groups a node with its parent; the second term floats the
     * parent to the top of its own group.
     */
    public function scopeTreeOrder(Builder $q): Builder
    {
        return $q->orderByRaw('COALESCE(parent_id, id), (parent_id IS NULL) DESC, name');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }
}
