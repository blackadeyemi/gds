<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pallet received into the finished-goods warehouse (bil.store_entrance,
 * 1.17M rows).
 *
 * The last barcode-level step of a pallet's life: Conversion Output mints it,
 * Factory Exit sends it, this receives it. After here stock is counted in
 * bundles, not barcodes — `sales_loading` carries its own load barcode, not the
 * pallet's — so nothing downstream references a row here.
 *
 * `barcode` is UNIQUE: a pallet is received once. Receiving also moves the
 * `storebundle` and `storebundle_floor` totals — see FinishedGoodsStock.
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

    /** Gates are matched by name, not id — see StoreEntranceLocation. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StoreEntranceLocation::class, 'entrancelocation', 'entrancelocation');
    }
}
