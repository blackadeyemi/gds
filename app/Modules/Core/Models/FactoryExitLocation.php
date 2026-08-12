<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A gate goods leave a factory through — the factory-side twin of
 * WarehouseEntrance, replacing the legacy `factoryexit_details` name pair.
 *
 * Unlike the warehouse gates these resolved their parent on import, because
 * `factoryexit_details` already named the factory. One extra was recovered:
 * Bil-2's gate, dropped from that table but still named by 16 pallets from
 * April 2017. It exists here but is inactive.
 *
 * `legacy_name` is the old `factory_exit.exitlocation` string; the 1.2M historic
 * exits were backfilled with `exit_location_id` from it, including three
 * spelling variants from the system's first weeks.
 */
class FactoryExitLocation extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $table = 'factory_exit_locations';

    protected $fillable = ['factory_id', 'name', 'legacy_name', 'sort_order', 'is_active'];

    protected $casts = [
        'factory_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'factory_exit_location_user',
            'exit_location_id',
            'user_id',
            'id',
            'userid'
        );
    }

    /** Usable = active, and attached to a factory goods can leave from. */
    public function scopeUsable($query)
    {
        return $query->where('is_active', true)->whereNotNull('factory_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
