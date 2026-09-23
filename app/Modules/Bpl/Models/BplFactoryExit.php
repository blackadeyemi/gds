<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A hardroll leaving the BPL paper-machine floor (bpl.bpl_factoryexit).
 *
 * `barcode` is UNIQUE, so re-exiting a reel whose exit was deleted is an
 * UPDATE in place, never a second row — the same contract the legacy
 * Bpl\Movement::save() honours.
 *
 * `location_id` is a `bpl_stock_locations.id` (1 = PM2, 2 = PM3), and the flat
 * PHP app still reads it. `received_at` is stamped later by BIL's Jumbo Rolls
 * Factory Entrance — see [[bil-jumbo-rolls]]; a new exit starts NULL, meaning
 * "gone from BPL, not yet seen at BIL".
 */
class BplFactoryExit extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_factoryexit';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'location_id' => 'integer',
        'created_at' => 'datetime',
        'received_at' => 'date',
    ];
}
