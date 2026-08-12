<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A gate goods pass through on their way into or out of a warehouse.
 *
 * Direction is an attribute rather than a separate table, because a gate is a
 * place and which way goods are moving is a property of the movement. `both` is
 * a real case — one elevator or roller door is often used either way.
 *
 * Belongs to one warehouse; that link is what tells a movement whose stock to
 * change. `warehouse_id` is nullable because the finished-goods gates were
 * imported before any warehouse existed — **a gate with no warehouse cannot be
 * used**, which `usable()` enforces.
 *
 * Which users may pick a gate is a plain many-to-many, replacing the legacy
 * `switch` on user level. It narrows a dropdown; it is not access control,
 * which the `page:` middleware still owns.
 */
class WarehouseGate extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $table = 'warehouse_gates';

    protected $fillable = ['warehouse_id', 'name', 'direction', 'legacy_name', 'sort_order', 'is_active'];

    protected $casts = [
        'warehouse_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public const IN = 'in';
    public const OUT = 'out';
    public const BOTH = 'both';

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'warehouse_gate_user', 'gate_id', 'user_id', 'id', 'userid');
    }

    /** Usable = active, and attached to a warehouse whose stock it can move. */
    public function scopeUsable($query)
    {
        return $query->where('is_active', true)->whereNotNull('warehouse_id');
    }

    /** Gates goods can move `in` / `out` through — `both` counts for either. */
    public function scopeDirection($query, string $direction)
    {
        return $query->whereIn('direction', [$direction, self::BOTH]);
    }

    /** Limit to warehouses holding a given module's goods. */
    public function scopeForModule($query, string $module)
    {
        return $query->whereHas('warehouse', fn ($w) => $w->where('module', $module));
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
