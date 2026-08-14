<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A jumbo roll received into a BIL factory (bil.factory_entrance_reel).
 *
 * One row per reel barcode, unique on `barcode`. `location` is the factory the
 * reel entered, held as the legacy factory NAME (factories.code) because the
 * still-live legacy jumbo screens read it; `gate_id` records which gate it came
 * through.
 *
 * `status` tracks the reel on the factory floor and is set by the screens
 * downstream, not here: NULL = available, 'mid' = part used, 'yes' = consumed,
 * 'return' = sent back, 'blocked' = held. A new entrance is always NULL.
 *
 * `timestamp` is a unix int, and `dateofentrance` a 'Y/m/d' string — legacy
 * shapes kept as they are so both apps read the same rows.
 */
class JumboRollFactoryEntrance extends Model
{
    protected $connection = 'bil';
    protected $table = 'factory_entrance_reel';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'integer',
        'timestamp' => 'integer',
        'gate_id' => 'integer',
    ];
}
