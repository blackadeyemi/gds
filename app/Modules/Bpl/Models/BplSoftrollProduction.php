<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A softroll as it comes off a BPL paper machine (bpl.bpl_softroll_production).
 *
 * Softrolls have no customer: they are made to the catalog, not to order.
 * `papermachine` is the machine NUMBER here (2/3) where the hardroll table
 * holds the name ('PM2'/'PM3') — a legacy divergence, kept because both apps
 * read the column.
 *
 * `product_id` (migration 2026_09_09_100000) points at bpl_products_softroll.
 * `grammage`/`diameter` are still written alongside it, denormalised, because
 * the flat PHP list, form and label print-out read them directly.
 */
class BplSoftrollProduction extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_softroll_production';
    public $timestamps = false;
    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(BplProductSoftroll::class, 'product_id');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(BplGrade::class, 'grade_id');
    }
}
