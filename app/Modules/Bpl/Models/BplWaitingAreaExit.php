<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `bpl.bpl_waiting_area_exit` — a roll released from a waiting area, on its
 * way into a store (or back out to the floor).
 *
 * Renamed from `bpl_quarantine_storeexit` (migration 2026_09_25_100000); the
 * old name survives as a view for the legacy screens.
 *
 * `barcode` is UNIQUE here too, so releasing a roll that has been parked again
 * rewrites its row rather than adding a second. Like the entry table, it moves
 * no stock.
 */
class BplWaitingAreaExit extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_waiting_area_exit';
    public $timestamps = false;

    protected $fillable = ['user', 'barcode', 'location_id', 'date', 'status', 'created_at', 'updated_at', 'deleted_at'];

    protected $casts = ['location_id' => 'integer'];
}
