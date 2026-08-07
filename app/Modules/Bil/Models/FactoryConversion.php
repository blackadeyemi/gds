<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Factory;
use Modules\Core\Models\MachineLine;

/**
 * One pallet of finished goods off a converting line (bil.factory_conversion,
 * renamed from factory_production — 1.2M rows and growing daily).
 *
 * A row IS a pallet: it carries the barcode on its label, how many bundles are
 * stacked on it, and the weights that follow from the product spec. Downstream
 * screens (store entrance, sales, stock) match on `barcode`.
 *
 * `linename` / `sublinename` are the legacy name pair, kept alongside the ids:
 * for a sub-line, linename is its parent and sublinename the sub-line itself;
 * for a top-level line the two are the same. Names are written using the line's
 * legacy_alias where it has one (FACIAL, Handkerchief), so the legacy reports
 * that GROUP BY these columns don't split.
 */
class FactoryConversion extends Model
{
    protected $connection = 'bil';
    protected $table = 'factory_conversion';
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'productid' => 'integer',
        'bundles' => 'integer',
        'netweight' => 'float',
        'grossweight' => 'float',
        'factory_id' => 'integer',
        'line_id' => 'integer',
    ];

    /** Short factory code used in the barcode, by factory name. */
    public const FACTORY_CODES = [
        'Bil-1' => 'B1',
        'Bil-2' => 'B2',
        'Gambini' => 'GB',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(FinishedGoodsProduct::class, 'productid', 'productid');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(MachineLine::class, 'line_id');
    }

    public function factoryRef(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }
}
