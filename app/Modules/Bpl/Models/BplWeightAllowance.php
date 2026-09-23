<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One hardroll weight-allowance rule: for this customer, this grade type and
 * this ply, deduct this many kilograms from the reel's scale weight.
 *
 * gds-only (bpl.bpl_weight_allowances) — the flat PHP app keeps its hard-coded
 * copy in Bil\Bpl\production::merge() until it is retired. Every change is
 * appended to BplWeightAllowanceHistory by the screen that makes it.
 */
class BplWeightAllowance extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_weight_allowances';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'customer_id' => 'integer',
        'ply' => 'integer',
        'allowance' => 'float',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BplCustomer::class, 'customer_id');
    }
}
