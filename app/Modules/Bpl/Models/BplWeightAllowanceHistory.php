<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only log of every change to a hardroll weight allowance
 * (bpl.bpl_weight_allowance_history) — who changed which rule, from what to
 * what, and when.
 *
 * The rule used to live in PHP, so when the live values drifted from the
 * checked-in ones there was no way to tell whether that was a decision or an
 * accident. This is the answer to that question from here on.
 *
 * `allowance` is null on a removal; `previous_allowance` is null on an add.
 */
class BplWeightAllowanceHistory extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_weight_allowance_history';
    public $timestamps = false;
    protected $guarded = [];

    public const ADDED = 'added';
    public const CHANGED = 'changed';
    public const REMOVED = 'removed';

    protected $casts = [
        'customer_id' => 'integer',
        'ply' => 'integer',
        'allowance' => 'float',
        'previous_allowance' => 'float',
        'date_modified' => 'datetime',
    ];
}
