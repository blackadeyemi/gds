<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Warehouse;
use Modules\Core\Models\WarehouseGate;

/**
 * A finished-goods pallet received into a warehouse
 * (core.finished_goods_warehouse_receipts).
 *
 * Replaces the legacy `store_entrance`. Module-specific — not a generic
 * warehouse receipt — because `productid` points at bil.products; BPL will have
 * its own receipts over the same warehouses and entrances.
 *
 * `barcode` is UNIQUE (a pallet is received once) and `warehouse_id` is
 * denormalised off the entrance, so a receipt keeps pointing at the right stock
 * even if the gate later moves warehouse or is retired.
 *
 * These rows are the source of truth for FgWarehouseStock: the totals are
 * exactly SUM(bundles) per warehouse per product, which is what makes
 * `bil:reconcile-fg-stock` possible.
 */
class FgWarehouseReceipt extends Model
{
    protected $connection = 'core';
    protected $table = 'finished_goods_warehouse_receipts';

    protected $guarded = [];

    protected $casts = [
        'entrance_id' => 'integer',
        'warehouse_id' => 'integer',
        'productid' => 'integer',
        'bundles' => 'integer',
        'user_id' => 'integer',
        'date_of_entrance' => 'date',
    ];

    public function entrance(): BelongsTo
    {
        return $this->belongsTo(WarehouseGate::class, 'entrance_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /** Cross-database: bil.products, so a plain id rather than a constraint. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(FinishedGoodsProduct::class, 'productid', 'productid');
    }
}
