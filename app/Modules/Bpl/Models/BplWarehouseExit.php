<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A hardroll released from a BPL warehouse (bpl.bpl_warehouse_exit, renamed
 * from bpl_storeexit 2026-09-16; the old name is a writable compat view).
 *
 * `barcode` is UNIQUE, so a roll whose release was deleted is released again in
 * place rather than as a second row.
 *
 * Releasing also stamps `bpl_warehouse_entry.status = 'Exited'` and takes the
 * weight back off `bpl_hardroll_stock` — see Bpl\Livewire\JumboRolls\WarehouseExit.
 */
class BplWarehouseExit extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_warehouse_exit';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'location_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
