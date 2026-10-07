<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy `bpl.bpl_waybill_payment` — what BPL owes a haulier for one trip.
 *
 * Keyed by the waybill number (`barcode` = `bpl_delivery_barcode`.
 * `delivery_barcode`), unique since migration 2026_09_23_120000. `amount` is
 * what was agreed for the trip; what has actually been paid is the sum of
 * `bpl_waybill_payment_history`, and `amount_paid` / `balance` / `status` are
 * derived from it — see recalculate(), which is the ONLY thing that writes
 * them. Across all 16 legacy rows the two already agree, so nothing moves.
 *
 * Amounts are naira; the history column is an INT, so they are whole naira.
 */
class BplWaybillPayment extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_waybill_payment';
    public $timestamps = false;

    protected $fillable = ['barcode', 'transporter_id', 'amount', 'amount_paid', 'balance',
        'status', 'customer_name', 'vechicle_number', 'created_at', 'updated_at'];

    protected $casts = ['amount' => 'float', 'amount_paid' => 'float', 'balance' => 'float'];

    public const OUTSTANDING = 'Outstanding';
    public const PARTIAL = 'Partial';
    public const PAID = 'Paid';

    public function history(): HasMany
    {
        return $this->hasMany(BplWaybillPaymentHistory::class, 'waybill_payment_id')->orderByDesc('payment_date');
    }

    public function transporter(): BelongsTo
    {
        return $this->belongsTo(BplTransporter::class, 'transporter_id');
    }

    /** What the amounts say this record is: the rule every legacy list used. */
    public static function statusFor(float $paid, float $balance): string
    {
        if ($paid <= 0) {
            return self::OUTSTANDING;
        }

        return $balance > 0 ? self::PARTIAL : self::PAID;
    }

    /**
     * Re-derive paid, balance and status from the history rows.
     *
     * Called after every change to a payment's history or agreed amount. The
     * legacy adjusted `amount_paid` by hand at each of four call sites, which
     * is how a record could drift from its own receipts.
     */
    public function recalculate(): void
    {
        $paid = (float) $this->history()->sum('amount');
        $balance = round((float) $this->amount - $paid, 2);

        $this->forceFill([
            'amount_paid' => $paid,
            'balance' => $balance,
            'status' => self::statusFor($paid, $balance),
            'updated_at' => now(),
        ])->save();
    }
}
