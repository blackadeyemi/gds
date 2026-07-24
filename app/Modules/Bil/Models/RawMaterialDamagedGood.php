<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A damaged/written-off raw-material record (bil.damagedgoods_rawmaterial):
 * one row per in-store barcode reported as damaged. Written by Damaged Goods
 * (entry) and confirmed on approval.
 *
 *   status: pending → approved | rejected  (gds adds the approval gate; legacy
 *           wrote the row straight to stock with a NULL status — those NULL
 *           rows are treated as already-final).
 *
 * No Eloquent timestamps (the legacy table has none). Shared with the legacy
 * app (MyISAM, non-transactional).
 */
class RawMaterialDamagedGood extends Model
{
    protected $connection = 'bil';
    protected $table = 'damagedgoods_rawmaterial';
    public $timestamps = false;
    protected $guarded = [];
}
