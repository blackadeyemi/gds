<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy `bpl.bpl_invoice_payments` — what the CUSTOMER has paid against one
 * shipment's final invoice.
 *
 * One row per packing list (332 rows, 332 distinct packing lists): the legacy
 * screen updated the row in place rather than adding another, so the amount is
 * the running total received, not a single receipt. gds keeps that shape so
 * both apps read the same figure.
 *
 * The other direction — what BPL owes a haulier for a trip — is
 * BplWaybillPayment, which does keep a receipt per payment.
 */
class BplInvoicePayment extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_invoice_payments';
    public $timestamps = false;

    protected $fillable = ['packing_id', 'amount', 'date'];

    protected $casts = ['packing_id' => 'integer', 'amount' => 'float'];

    public function packingList(): BelongsTo
    {
        return $this->belongsTo(BplPackingList::class, 'packing_id');
    }
}
