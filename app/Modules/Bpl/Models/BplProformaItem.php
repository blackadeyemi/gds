<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy `bpl.bpl_proforma_items` — the price of one line of a sales order.
 *
 * It names the ORDER LINE (`order_item_id` → `bpl_sales_items.id`), not the
 * proforma, so the price follows the line it was quoted for. The price is per
 * TONNE; the line's weight is in kilograms, so the amount is
 * `price * weight / 1000` — see Support\ProformaInvoice.
 *
 * A priced line is why BPL Sales Orders refuses to remove or re-product it.
 */
class BplProformaItem extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_proforma_items';
    public $timestamps = false;

    protected $fillable = ['order_item_id', 'price'];

    protected $casts = ['price' => 'float'];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(BplSalesOrderItem::class, 'order_item_id');
    }
}
