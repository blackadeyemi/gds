<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A hardroll received into a BPL warehouse (bpl.bpl_warehouse_entry,
 * renamed from bpl_storeentrance 2026-09-16; the old name is a writable
 * compat view the flat PHP app still uses).
 *
 * `barcode` is UNIQUE, so a roll whose receipt was deleted is received again in
 * place rather than as a second row — the legacy Bpl\Movement::save() contract.
 *
 * `location_id` is a `bpl_stock_locations.id` (3 = PM2 Store, 4 = PM3 Store,
 * 5 = Waste Paper Store), which the flat PHP app reads. `status` is NULL while
 * the roll is still held; Warehouse Exit sets it.
 */
class BplWarehouseEntry extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_warehouse_entry';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'location_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
