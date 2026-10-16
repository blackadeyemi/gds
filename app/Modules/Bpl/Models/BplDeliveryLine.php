<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy `bpl.bpl_delivery_details` — one row per roll on a truck, carrying
 * both the delivery's id and the waybill number it went out on.
 *
 * `bpl_delivery_id`, `product_id` and the dates are all varchars here; that is
 * the legacy shape and the legacy report reads it, so it is kept.
 */
class BplDeliveryLine extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_delivery_details';
    public $timestamps = false;

    protected $fillable = ['bpl_delivery_id', 'barcode', 'product_id', 'delivery_barcode',
        'created_at', 'updated_at'];
}
