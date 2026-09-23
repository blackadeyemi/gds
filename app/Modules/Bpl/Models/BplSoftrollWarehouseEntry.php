<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A softroll received into a BPL warehouse (bpl.bpl_softroll_warehouse_entry,
 * renamed from softroll_storeentrance 2026-09-16) — the
 * softroll twin of BplWarehouseEntry.
 *
 * Note the table name: `bpl_softroll_warehouse_entry`, NOT `bpl_softroll_*`. The
 * legacy naming is inconsistent across the softroll pipeline (its factory exit
 * IS `bpl_softroll_factoryexit`) and both names are load-bearing.
 */
class BplSoftrollWarehouseEntry extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_softroll_warehouse_entry';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'location_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
