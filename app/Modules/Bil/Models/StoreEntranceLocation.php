<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A gate pallets are received through (bil.storeentrance_details).
 *
 * Three rows today, each pairing a store floor with a gate:
 *
 *   Store FB    FG Store FB Elevator 1
 *   Store FC 1  FG Store FC Gate 1
 *   Store FC 2  FG Store FC Buffer Room
 *
 * `store_entrance` stores the gate NAME, so this pair is the only route back to
 * the floor — which is why both the entry screen and the report join on
 * `entrancelocation` rather than an id.
 *
 * `storefloor` here is the reporting label; the *stock* floor a gate feeds is a
 * separate mapping onto `store_floors` — see FinishedGoodsStock::floorId().
 */
class StoreEntranceLocation extends Model
{
    protected $connection = 'bil';
    protected $table = 'storeentrance_details';
    public $timestamps = false;

    protected $guarded = [];
}
