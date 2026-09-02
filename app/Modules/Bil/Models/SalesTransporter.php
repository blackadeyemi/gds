<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy `bil.sales_transporters` — the hauliers who move finished goods out.
 *
 * Referenced by `sales_loading.transporterid` on every load, and grouped by
 * `report_sales_loading_transporter.php`. 141 of the 143 have carried something,
 * so a transporter is effectively permanent once used — deleting one would
 * leave those loadings pointing at nothing.
 *
 * `transportercode` is gds's addition (see the migration): an 8-digit reference
 * that is not the row id, matching the shape of `sales_customers.customercode`.
 * It is SYSTEM-ASSIGNED and never typed — `booted()` mints one on create, so a
 * transporter added by any code path gets a code, not just the one screen.
 * Legacy still inserts without it, so the column stays nullable and a
 * legacy-created row is simply code-less until gds touches it.
 */
class SalesTransporter extends Model
{
    protected $connection = 'bil';
    protected $table = 'sales_transporters';
    public $timestamps = false;

    protected $fillable = ['transportername', 'transportercode'];

    /** Eight digits, never starting with zero — see the migration for why. */
    public const CODE_MIN = 10000000;
    public const CODE_MAX = 99999999;

    /** Enough attempts that exhausting them means something is actually wrong. */
    protected const CODE_ATTEMPTS = 20;

    protected static function booted(): void
    {
        static::creating(function (self $t) {
            $t->transportercode = $t->transportercode ?: self::generateCode();
        });
    }

    /**
     * A free 8-digit code.
     *
     * Random rather than sequential so it carries no information about how many
     * transporters exist or what order they were added in — which is the whole
     * point of not using the id. Re-rolls on collision; with 143 rows in a
     * 90-million space that is vanishingly unlikely, but the UNIQUE index is
     * the real guarantee and this only avoids hitting it.
     */
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

    public function loadings(): HasMany
    {
        return $this->hasMany(SalesLoading::class, 'transporterid');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('transportername');
    }
}
