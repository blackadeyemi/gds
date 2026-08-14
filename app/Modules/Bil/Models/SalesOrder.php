<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy `bil.sales_order` — the header of a customer's order for finished
 * goods. Its lines live in `sales_order_details`.
 *
 * Shape kept exactly as legacy left it, because the legacy sales screens
 * (Loading, Delivery, Waybill, the sales reports) still read these rows:
 *
 *   - `orderid` is the BUSINESS key, not `id`: a hand-typed order number,
 *     UNIQUE, varchar(20). The detail rows join on it by string, not on `id`.
 *   - `warehousecode` is the sales depot code '01'/'02'/'03' — see
 *     Core\Models\Warehouse::scopeSalesDepot(). `Sales\Loading::makeBarcode()`
 *     turns 1/2/3 into the L/K/A letter of the load barcode.
 *   - `dateoforder` is a varchar(11) holding 'Y/m/d', not a date column.
 *   - `timestamp` is a unix int, set on insert and never updated by legacy.
 */
class SalesOrder extends Model
{
    protected $connection = 'bil';
    protected $table = 'sales_order';
    public $timestamps = false;

    protected $fillable = ['username', 'orderid', 'warehousecode', 'customerid', 'dateoforder', 'timestamp'];

    protected $casts = [
        'customerid' => 'integer',
        'timestamp' => 'integer',
    ];

    /** Lines join on the order NUMBER, which is what legacy stores. */
    public function details(): HasMany
    {
        return $this->hasMany(SalesOrderDetail::class, 'orderid', 'orderid');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SalesCustomer::class, 'customerid');
    }

    /** Legacy stores 'Y/m/d' strings; scopes and writes go through these. */
    public static function toLegacyDate(string $iso): string
    {
        return str_replace('-', '/', $iso);
    }

    public static function fromLegacyDate(?string $legacy): string
    {
        return str_replace('/', '-', (string) $legacy);
    }
}
