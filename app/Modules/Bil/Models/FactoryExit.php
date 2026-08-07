<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pallet leaving the factory for the warehouse (bil.factory_exit, 1.2M rows).
 *
 * The middle step of a pallet's life: Conversion Output mints it in
 * `factory_conversion`, Factory Exit records it passing the gate, and the store
 * receives it into `store_entrance`. Every stage matches on `barcode`, which is
 * UNIQUE here — a pallet can only exit once.
 *
 * `status` is NOT the exit's own state; it mirrors whether the store has
 * received this pallet. Exit sets it to 'yes' when the barcode is already in
 * `store_entrance` (an out-of-order scan), and the legacy store-entrance screen
 * flips it to 'yes' on receipt and back to NULL when a receipt is deleted.
 */
class FactoryExit extends Model
{
    protected $connection = 'bil';
    protected $table = 'factory_exit';
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'productid' => 'integer',
        'bundles' => 'integer',
        'timestamp' => 'integer',
    ];

    /** `status` value meaning the store has received the pallet. */
    public const RECEIVED = 'yes';

    public function product(): BelongsTo
    {
        return $this->belongsTo(FinishedGoodsProduct::class, 'productid', 'productid');
    }

    /** Exit locations are matched by name, not id — see FactoryExitLocation. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(FactoryExitLocation::class, 'exitlocation', 'exitlocation');
    }

    public function isReceived(): bool
    {
        return $this->status === self::RECEIVED;
    }
}
