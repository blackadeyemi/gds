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

    /**
     * Scope to the current module's company. The factory hierarchy is shared in
     * `core` across companies (BIL, BHN…), but each company module (incl. BHN
     * reusing the Bil screens) must only see its own lines. Keyed to
     * config('bil.company_code'), which ResolveCompanyModule rebinds per request.
     * Unassigned lines (no factory) stay visible to all. MachineMaps rebuilds via
     * the query builder, not this model, so the name→id maps stay complete.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('companyModule', function (Builder $q): void {
            $code = config('bil.company_code');
            if (! $code) {
                return;
            }
            $q->where(function (Builder $w) use ($code): void {
                $w->whereNull('factory_id')
                    ->orWhereHas('factory', fn (Builder $f) => $f->whereHas('company', fn (Builder $c) => $c->where('code', $code)));
            });
        });
    }

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
