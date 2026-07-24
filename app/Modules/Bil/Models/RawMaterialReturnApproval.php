<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A factory-return approval request (bil.return_approval): one row per barcode
 * a factory operator has submitted to return to the store, awaiting sign-off.
 * Written by Factory Returns (entry) and updated on approve/reject.
 *
 *   status: pending → approved | rejected
 *   type:   'Non-Consumed' (whole item never used) | 'Partially Consumed'
 *           (leftover of a consumed item comes back as a new child barcode)
 *
 * `timestamp` is a DB-managed CURRENT_TIMESTAMP column, so no Eloquent
 * timestamps. Shared with the legacy app (MyISAM, non-transactional).
 */
class RawMaterialReturnApproval extends Model
{
    protected $connection = 'bil';
    protected $table = 'return_approval';
    public $timestamps = false;
    protected $guarded = [];
}
