<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Goods taken back off a truck before it left.
 *
 * `quantityloaded` on the loading row is stored NET of returns, and the return
 * row records what came off — so the quantity originally put on the truck is
 * loaded + returned. Every screen that shows "loaded" has to add them back, and
 * FinishedGoodsStock nets returns against loadings for the same reason.
 */
class SalesLoadingReturn extends Model
{
    protected $connection = 'bil';
    protected $table = 'sales_loading_return';
    public $timestamps = false;

    protected $fillable = [
        'barcode', 'username', 'loading_id', 'sod_id', 'quantityunloaded', 'timestamp',
    ];

    protected $casts = [
        'loading_id' => 'integer',
        'sod_id' => 'integer',
        'quantityunloaded' => 'integer',
        'timestamp' => 'integer',
    ];

    public function loading(): BelongsTo
    {
        return $this->belongsTo(SalesLoading::class, 'loading_id');
    }
}
