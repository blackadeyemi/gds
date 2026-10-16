<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Legacy `bpl.bpl_proforma` — the priced version of a sales order, and the
 * document the customer is sent.
 *
 * KEYED BY THE ORDER: `order_id` is the primary key, so an order has at most
 * one proforma and the proforma has no id of its own. Its number is the
 * order's (`bpl_sales.orderno`), optionally with the customer's own reference
 * appended — see Support\ProformaInvoice.
 *
 * The prices live on `bpl_proforma_items`, one per line of the order; this row
 * carries what the document says around them: freight, terms, shipment,
 * payment term, currency, receiving account and the NXP number.
 *
 * Soft-deleted, as legacy did; saving an edit over a deleted one brings it
 * back, which is how the legacy screen "restored" a proforma.
 */
class BplProforma extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_proforma';
    protected $primaryKey = 'order_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['order_id', 'customer_ref', 'freight', 'container', 'freight_price',
        'terms', 'shipment', 'payment_term_id', 'nxp', 'currency_id', 'account_id', 'date',
        'created_at', 'updated_at', 'deleted_at'];

    protected $casts = ['container' => 'integer', 'freight_price' => 'float'];

    /** The three the legacy form offered, unchanged. */
    public const FREIGHTS = ['40ft High Cube Container', '20ft High Cube Container', 'Inland Transport'];

    public const TERMS = ['CFR', 'Door to Door', 'FOB'];

    public const SHIPMENTS = ['Vessel', 'Truck'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(BplSalesOrder::class, 'order_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BplAccount::class, 'account_id');
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(BplPaymentTerm::class, 'payment_term_id');
    }

    /** Freight is quoted per container, so the charge is the two multiplied. */
    public function freightCharge(): float
    {
        return round((int) $this->container * (float) $this->freight_price, 2);
    }
}
