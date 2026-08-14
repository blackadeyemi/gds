<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A gate goods pass through on their way into or out of a factory — the
 * factory-side twin of WarehouseGate.
 *
 * Replaces two legacy name-pair tables: `factoryexit_details` (finished goods
 * leaving) and `factoryentrance_details` (raw material arriving). Both resolved
 * their factory on import.
 *
 * Two gates survive as INACTIVE so history resolves without offering them:
 * Bil-2's exit, dropped from the legacy table but named by 16 pallets from
 * April 2017, and "Oregun Store", which the legacy entrance table listed but
 * which is a store rather than a factory.
 */
class FactoryGate extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $table = 'factory_gates';

    protected $fillable = ['factory_id', 'name', 'direction', 'legacy_name', 'sort_order', 'is_active'];

    protected $casts = [
        'factory_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public const IN = 'in';
    public const OUT = 'out';
    public const BOTH = 'both';

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'factory_gate_user', 'gate_id', 'user_id', 'id', 'userid');
    }

    /** Usable = active, and attached to a factory goods can move through. */
    public function scopeUsable($query)
    {
        return $query->where('is_active', true)->whereNotNull('factory_id');
    }

    /** Gates goods can move `in` / `out` through — `both` counts for either. */
    public function scopeDirection($query, string $direction)
    {
        return $query->whereIn('direction', [$direction, self::BOTH]);
    }

    /** Gates on factories belonging to one company, matched on companies.code. */
    public function scopeForCompany($query, string $companyCode)
    {
        return $query->whereHas(
            'factory',
            fn ($f) => $f->whereHas('company', fn ($c) => $c->where('code', $companyCode))
        );
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function directionLabel(): string
    {
        return config('warehouses.directions')[$this->direction] ?? $this->direction;
    }
}
