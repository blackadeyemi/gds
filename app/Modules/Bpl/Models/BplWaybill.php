<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy `bpl.bpl_delivery_barcode` — the waybill a truck leaves with.
 *
 * `status` is 'pending' until the loaded truck has been weighed (the delivery
 * report writes `full_weight` and flips it to 'completed'). A load going to
 * BIL is weighed nowhere, so the legacy marked it completed on the spot; gds
 * keeps that.
 *
 * `bpl_waybill_payment.barcode` is this number: what the transporter is owed
 * for the trip hangs off it.
 */
class BplWaybill extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_delivery_barcode';
    public $timestamps = false;

    protected $fillable = ['delivery_barcode', 'status', 'deleted_at', 'created_at',
        'updated_at', 'date', 'full_weight'];

    public const PENDING = 'pending';
    public const COMPLETED = 'completed';

    public function lines(): HasMany
    {
        return $this->hasMany(BplDeliveryLine::class, 'delivery_barcode', 'delivery_barcode');
    }
}
