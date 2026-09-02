<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A delivery: the confirmation that one truck load actually went out.
 *
 * One row per LOAD, not per line — the delivery says "load 381 of 30 June left
 * the yard", and what was on it is still read from `sales_loading`. That is why
 * this table has no product, no quantity and no `sod_id`.
 *
 * HOW IT POINTS AT ITS LOAD. Only by (`loadnumber`, `dateofdelivery`); there is
 * no load id or load barcode on the row. Both barcodes are derived from the
 * same three parts, differing in one character —
 *
 *     load      26-06-30-L1L-381
 *     delivery  26-06-30-L1D-379
 *
 * — so the load's barcode can be rebuilt from the delivery, which is what
 * SalesDeliveries::loadBarcodeFor() does. The waybill hangs off the delivery
 * the same way, on (`deliverynumber`, `dateofwaybill`).
 *
 * `deliverynumber` RESTARTS EVERY DAY and is independent of the load number:
 * loads are numbered as trucks are filled, deliveries as they leave, and the
 * two orders differ.
 *
 * Written on the LEGACY table on purpose, for the same reasons as
 * SalesLoading: the legacy screens still read it, the waybill and the sales
 * reports join to it, and 129,583 rows of history are the record.
 */
class SalesDelivery extends Model
{
    protected $connection = 'bil';
    protected $table = 'sales_delivery';
    public $timestamps = false;

    protected $fillable = [
        'deliverynumber', 'barcode', 'username', 'loadnumber',
        'dateofdelivery', 'timestamp', 'deliverycustomerid',
    ];

    protected $casts = [
        'deliverynumber' => 'integer',
        'loadnumber' => 'integer',
        'timestamp' => 'integer',
    ];

    public function scopeOn(Builder $q, string $dateSlash): Builder
    {
        return $q->where('dateofdelivery', $dateSlash);
    }

    /** The delivery of one load, addressed the only way the row allows. */
    public function scopeForLoad(Builder $q, int $loadNumber, string $dateSlash): Builder
    {
        return $q->where('loadnumber', $loadNumber)->where('dateofdelivery', $dateSlash);
    }
}
