<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A softroll released from a BPL warehouse (bpl.bpl_softroll_warehouse_exit,
 * renamed from softroll_storeexit 2026-09-16) — the softroll
 * twin of BplWarehouseExit.
 *
 * Note the table name: `bpl_softroll_warehouse_exit`, matching `bpl_softroll_warehouse_entry`
 * and NOT the `bpl_softroll_*` shape its factory exit uses.
 */
class BplSoftrollWarehouseExit extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_softroll_warehouse_exit';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'location_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
