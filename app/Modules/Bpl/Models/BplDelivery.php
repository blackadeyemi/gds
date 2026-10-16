<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy `bpl.bpl_delivery` — the delivery form: who the rolls are going to
 * and on what truck. One row per truck load; its rolls are in
 * `bpl_delivery_details` and its waybill number in `bpl_delivery_barcode`.
 *
 * Every column is a varchar, dates included (`date` is 'Y/m/d'), and
 * `customer_name` is the customer's NAME rather than its id — the legacy form
 * posted the select's text. gds keeps writing the name so the legacy delivery
 * report and printout keep working, and picks it from `bpl_customers`.
 */
class BplDelivery extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_delivery';
    public $timestamps = false;

    protected $fillable = ['customer_name', 'date', 'vechile_number', 'loader', 'driver',
        'driver_phone', 'truck_weight', 'container_number', 'created_at', 'updated_at'];

    /** The inter-company customer: rolls going to BIL, not out to a buyer. */
    public const INTERNAL_CUSTOMER = 'Belimpex Limited';

    public function lines(): HasMany
    {
        return $this->hasMany(BplDeliveryLine::class, 'bpl_delivery_id');
    }

    public function isInternal(): bool
    {
        return trim((string) $this->customer_name) === self::INTERNAL_CUSTOMER;
    }
}
