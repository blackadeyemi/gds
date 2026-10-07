<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy `bpl.bpl_waybill_payment_history` — one row per payment made against
 * a waybill: when it was paid, how much, and the voucher number in
 * `payment_note`.
 *
 * Zero-amount rows exist and are meaningful: twelve of the sixteen legacy
 * records carry one, noting the voucher raised before any money moved.
 *
 * The sum of these rows IS what has been paid — see
 * BplWaybillPayment::recalculate().
 */
class BplWaybillPaymentHistory extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_waybill_payment_history';
    public $timestamps = false;

    protected $fillable = ['waybill_payment_id', 'amount', 'payment_date', 'payment_note', 'created_at', 'updated'];

    protected $casts = ['amount' => 'float', 'payment_date' => 'date'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BplWaybillPayment::class, 'waybill_payment_id');
    }
}
