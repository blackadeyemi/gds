<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Legacy `bil.sales_order_details` — one product line of a sales order.
 *
 * `foc` (free of charge) is part of the line's IDENTITY, not just a flag: the
 * same product can appear twice on one order, once charged and once free, and
 * legacy treats (product, foc) as the pair that must be unique. Both the
 * duplicate check and the "which line is this?" matching on edit use the pair.
 *
 * A line is referenced by `sales_loading.sod_id` once goods go out against it,
 * which is why the id must survive an edit — see `loadedQuantities()`.
 */
class SalesOrderDetail extends Model
{
    protected $connection = 'bil';
    protected $table = 'sales_order_details';
    public $timestamps = false;

    protected $fillable = ['orderid', 'productid', 'quantityordered', 'foc'];

    protected $casts = [
        'productid' => 'integer',
        'quantityordered' => 'integer',
        'foc' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(FinishedGoodsProduct::class, 'productid', 'productid');
    }

    /**
     * How much has already been loaded against each line of an order.
     *
     * Returns [detail id => quantity loaded]. A line with a loading cannot be
     * removed and cannot be ordered down below what has already gone out —
     * legacy's UPDATE/DELETE pass did neither check, and would happily orphan a
     * `sales_loading` row pointing at a deleted `sod_id`.
     */
    public static function loadedQuantities(string $orderid): array
    {
        return DB::connection('bil')->table('sales_loading as l')
            ->join('sales_order_details as sod', 'l.sod_id', '=', 'sod.id')
            ->where('sod.orderid', $orderid)
            ->groupBy('l.sod_id')
            ->selectRaw('l.sod_id, SUM(l.quantityloaded) as qty')
            ->pluck('qty', 'sod_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }
}
