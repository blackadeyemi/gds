<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The waybill raised against a delivery: what the haulier is paid, and the
 * paperwork the money follows.
 *
 * It carries no products and no quantities. Everything on the sheet comes from
 * the load the delivery closed; what the waybill ADDS is two figures — the
 * `receiptnumber` and the `transportcost` — which is why it exists as its own
 * record rather than two more columns on the delivery.
 *
 * ONE PER DELIVERY, keyed by (`deliverynumber`, `dateofwaybill`) — the same
 * pair the delivery is addressed by, and unique across all 54,894 rows. Its
 * barcode is the delivery's with one character changed:
 *
 *     delivery  26-06-30-L1D-379
 *     waybill   26-06-30-L1W-379
 *
 * so the serial and the number are shared and only the D becomes a W.
 *
 * NOT EVERY DELIVERY HAS ONE, and that is normal rather than a backlog: 54,894
 * waybills against 129,583 deliveries. A customer collecting in their own truck
 * is delivered but never waybilled, because there is no haulier to pay.
 *
 * `dateofwaybill` is the DELIVERY's date, not the day it was keyed — the join
 * back to the delivery is on that pair, so dating it today would orphan it.
 */
class SalesWaybill extends Model
{
    protected $connection = 'bil';
    protected $table = 'sales_waybill';
    public $timestamps = false;

    protected $fillable = [
        'barcode', 'username', 'deliverynumber', 'receiptnumber',
        'transportcost', 'dateofwaybill', 'timestamp',
    ];

    protected $casts = [
        'receiptnumber' => 'integer',
        'transportcost' => 'float',
        'timestamp' => 'integer',
    ];

    public function scopeOn(Builder $q, string $dateSlash): Builder
    {
        return $q->where('dateofwaybill', $dateSlash);
    }

    /**
     * The waybill of one delivery. `deliverynumber` is a varchar here and an
     * int on `sales_delivery`, so it is compared as a string on purpose.
     */
    public function scopeForDelivery(Builder $q, int $deliveryNumber, string $dateSlash): Builder
    {
        return $q->where('deliverynumber', (string) $deliveryNumber)
            ->where('dateofwaybill', $dateSlash);
    }
}
