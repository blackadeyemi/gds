<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on a transfer.
 *
 * The product's code and name are copied onto the line because the master lives
 * on another connection (bil.products) and cannot be joined — and because a
 * transfer from 2023 should still read as what was actually sent, whatever the
 * product has been renamed to since.
 *
 * `received_bundles` is null until the transfer is received, and is allowed to
 * differ from `bundles`. A short delivery is a fact worth recording.
 */
class StockTransferLine extends Model
{
    protected $connection = 'core';
    protected $table = 'stock_transfer_lines';

    protected $fillable = [
        'transfer_id', 'productid', 'product_code', 'product_name',
        'bundles', 'received_bundles',
    ];

    protected $casts = [
        'transfer_id' => 'integer',
        'productid' => 'integer',
        'bundles' => 'integer',
        'received_bundles' => 'integer',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
    }

    public function shortfall(): int
    {
        return $this->received_bundles === null ? 0 : $this->bundles - $this->received_bundles;
    }
}
