<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Warehouse;

/**
 * Bundles of a finished-goods product held in a warehouse
 * (core.finished_goods_warehouse_stock).
 *
 * Replaces `storebundle` (one hard-coded warehouse code) and `storebundle_floor`
 * (three hard-coded floors) with one row per warehouse per product.
 *
 * Module-specific for the same reason as FgWarehouseReceipt: `productid` means
 * bil.products here and bpl_products for BPL, so one shared stock table would
 * need a discriminator and would be wrong the first time someone omitted it.
 *
 * Only ever moved through Modules\Bil\Support\FinishedGoodsStock — never write
 * `bundles` directly, or receiving and un-receiving will drift apart.
 */
class FgWarehouseStock extends Model
{
    protected $connection = 'bil';
    protected $table = 'finished_goods_warehouse_stock';

    protected $guarded = [];

    protected $casts = [
        'warehouse_id' => 'integer',
        'productid' => 'integer',
        'bundles' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(FinishedGoodsProduct::class, 'productid', 'productid');
    }
}
