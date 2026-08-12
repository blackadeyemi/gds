<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A gate goods are received into a warehouse through.
 *
 * Belongs to one warehouse — that link is what tells a receipt whose stock to
 * move. `warehouse_id` is nullable because the three legacy gates were imported
 * before any warehouse existed; **an entrance with no warehouse cannot receive**,
 * since there would be nowhere to put the goods. `usable()` is the scope that
 * enforces it.
 *
 * `legacy_name` is the old `store_entrance.entrancelocation` string, kept so the
 * historic receipts can still be matched to the gate they came through.
 *
 * Which users may pick a gate is a plain many-to-many, replacing the legacy
 * `switch` on user level. It narrows a dropdown — it is not access control; the
 * `page:` middleware decides who may open the screen at all.
 */
class WarehouseEntrance extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $table = 'warehouse_entrances';

    protected $fillable = ['warehouse_id', 'name', 'legacy_name', 'sort_order', 'is_active'];

    protected $casts = [
        'warehouse_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'warehouse_entrance_user',
            'entrance_id',
            'user_id',
            'id',
            'userid'
        );
    }

    /** Usable = active, and attached to a warehouse to receive into. */
    public function scopeUsable($query)
    {
        return $query->where('is_active', true)->whereNotNull('warehouse_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
