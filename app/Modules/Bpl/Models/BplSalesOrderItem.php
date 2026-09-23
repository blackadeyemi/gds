<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A line on a BPL sales order (bpl.bpl_sales_items): a hardroll product and the
 * WEIGHT ordered, in kg — BPL sells by weight, not by count.
 *
 * `productid` is a `bpl_products_hardroll.id` (all 638 existing lines are).
 * One product may appear only once per order (the legacy form refused
 * duplicates client-side; gds refuses them server-side too).
 */
class BplSalesOrderItem extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_sales_items';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = ['weight' => 'float'];

    /**
     * Line ids on this order that a proforma has priced
     * (`bpl_proforma_items.order_item_id`). Such a line is on a document that
     * has gone to the customer: it may be re-weighed, but not removed or
     * swapped for another product.
     *
     * @return array<int, true>
     */
    public static function pricedLines(int $orderId): array
    {
        return DB::connection('bpl')->table('bpl_proforma_items as pi')
            ->join('bpl_sales_items as si', 'si.id', '=', 'pi.order_item_id')
            ->where('si.order_id', $orderId)
            ->distinct()
            ->pluck('pi.order_item_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
