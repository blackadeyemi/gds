<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One roll moved from one BPL warehouse to another
 * (bpl.bpl_warehouse_transfer).
 *
 * gds-only: the legacy transfer screen kept no record at all, it just
 * overwrote the receipt's location. See the migration for why that is worth
 * fixing rather than reproducing.
 *
 * Covers both streams via `stream`; `barcode` is not unique because a roll can
 * be moved repeatedly.
 */
class BplWarehouseTransfer extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_warehouse_transfer';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'from_location_id' => 'integer',
        'to_location_id' => 'integer',
        'weight' => 'float',
        'created_at' => 'datetime',
    ];
}
