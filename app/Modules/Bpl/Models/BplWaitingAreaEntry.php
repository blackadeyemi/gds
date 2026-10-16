<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `bpl.bpl_waiting_area_entry` — a roll parked in a waiting area: off the
 * factory floor, not yet received into a store.
 *
 * Renamed from `bpl_quarantine` (migration 2026_09_25_100000); the old name
 * survives as a view for the legacy screens.
 *
 * `barcode` is UNIQUE, so a roll that was parked, released and parked again
 * reuses its row — the same shape the warehouse-entry tables have. NOTHING
 * here touches stock: a roll in the waiting area has not been received yet.
 */
class BplWaitingAreaEntry extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_waiting_area_entry';
    public $timestamps = false;

    protected $fillable = ['user', 'barcode', 'location_id', 'date', 'status', 'created_at', 'updated_at', 'deleted_at'];

    protected $casts = ['location_id' => 'integer'];

    /** Set on the entry row when the roll leaves, the way the store tables mark theirs. */
    public const RELEASED = 'Released';
}
