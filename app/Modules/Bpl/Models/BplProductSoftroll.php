<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BPL softroll product (bpl.bpl_products_softroll — new in the BPL rebuild).
 * A softroll product = grade + grammage + diameter (brightness stays per-roll
 * on bpl_softroll_production). Columns: id, productname, grade_id, grammage,
 * diameter, deleted_at.
 */
class BplProductSoftroll extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_products_softroll';
    public $timestamps = false;
    protected $guarded = [];

    public function grade(): BelongsTo
    {
        return $this->belongsTo(BplGrade::class, 'grade_id');
    }
}
