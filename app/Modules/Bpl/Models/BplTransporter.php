<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy `bpl.bpl_transporters` — the hauliers who carry BPL rolls out.
 *
 * Named by `bpl_waybill_payment.transporter_id` (what BPL owes each haulier),
 * so a transporter that has been paid is effectively permanent: deleting it
 * would leave those payments pointing at nothing.
 *
 * `transportercode` is gds's addition (migration 2026_09_18_100000), the same
 * 8-digit, system-assigned code BIL's `SalesTransporter` carries. Minted in
 * `booted()` so every code path gets one; legacy INSERTs name only
 * `transportername`, so the column stays nullable and a legacy-created row is
 * code-less until gds saves it.
 */
class BplTransporter extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_transporters';
    public $timestamps = false;

    protected $fillable = ['transportername', 'transportercode'];

    /** Eight digits, never starting with zero — see the migration. */
    public const CODE_MIN = 10000000;
    public const CODE_MAX = 99999999;

    protected const CODE_ATTEMPTS = 20;

    protected static function booted(): void
    {
        static::creating(function (self $t) {
            $t->transportercode = $t->transportercode ?: self::generateCode();
        });
    }

    /** A free 8-digit code. The UNIQUE index `bt_code_unq` is the real guarantee. */
    public static function generateCode(): string
    {
        for ($i = 0; $i < self::CODE_ATTEMPTS; $i++) {
            $code = (string) random_int(self::CODE_MIN, self::CODE_MAX);

            if (! static::where('transportercode', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not allocate a free transporter code after ' . self::CODE_ATTEMPTS . ' attempts.');
    }

    public function waybillPayments(): HasMany
    {
        return $this->hasMany(BplWaybillPayment::class, 'transporter_id');
    }
}
