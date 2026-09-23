<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A BPL sales order header (bpl.bpl_sales).
 *
 * Legacy columns, unchanged, because the proforma, packing list, invoice and
 * metrics screens all still read them:
 *   - `ref`       per-CUSTOMER sequence, zero-padded to 3 ("063");
 *                 UNIQUE together with `customerid`.
 *   - `company`   the selling company, 'Belpapyrus' or 'Belimpex'.
 *   - `date`      legacy 'Y/m/d' text.
 *
 * `orderno` is gds's addition (migration 2026_09_18_110000) — the order number
 * the legacy screens recompute every time from the customer's CURRENT label and
 * country. Stored once at placement so renaming a customer cannot renumber
 * orders already printed. See Modules\Bpl\Support\SalesOrderNumber.
 *
 * Downstream, `bpl_proforma.order_id` and `bpl_packing_list.order_id` point at
 * `id`, and `bpl_proforma_items.order_item_id` at a line.
 */
class BplSalesOrder extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_sales';
    protected $guarded = [];

    public const COMPANIES = ['Belpapyrus', 'Belimpex'];

    public function items(): HasMany
    {
        return $this->hasMany(BplSalesOrderItem::class, 'order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BplCustomer::class, 'customerid');
    }

    public static function toLegacyDate(string $iso): string
    {
        return Carbon::parse($iso)->format('Y/m/d');
    }

    public static function fromLegacyDate(?string $legacy): string
    {
        return $legacy ? Carbon::parse(str_replace('/', '-', $legacy))->format('Y-m-d') : now()->format('Y-m-d');
    }

    /** Proformas and packing lists raised against this order — the things a change would break. */
    public static function downstreamOf(int $orderId): array
    {
        $bpl = \Illuminate\Support\Facades\DB::connection('bpl');

        return [
            'proforma' => $bpl->table('bpl_proforma')->where('order_id', $orderId)->whereNull('deleted_at')->exists(),
            'packing' => $bpl->table('bpl_packing_list')->where('order_id', $orderId)->exists(),
        ];
    }
}
