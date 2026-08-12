<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A LEGACY finished-goods receipt (bil.store_entrance, 1.17M rows).
 *
 * Superseded by FgWarehouseReceipt. gds no longer writes this table — see the
 * 2026-08-12 cut-over in docs/DEPLOYMENT.md — but the legacy app still does, so
 * it is still read in two places, both of which would be wrong to skip:
 *
 *   - the receiving screen, so a pallet the legacy app already took in cannot
 *     be received a second time in gds;
 *   - the delete guards on the Conversion Output and Factory Exit reports, so a
 *     pallet the legacy warehouse holds cannot have its history deleted.
 *
 * Its `entrancelocation` is a bare name string; the gate it refers to is now a
 * row in `warehouse_gates`, matched on `legacy_name`.
 */
class StoreEntrance extends Model
{
    protected $connection = 'bil';
    protected $table = 'store_entrance';
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'productid' => 'integer',
        'bundles' => 'integer',
        'timestamp' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(FinishedGoodsProduct::class, 'productid', 'productid');
    }
}
