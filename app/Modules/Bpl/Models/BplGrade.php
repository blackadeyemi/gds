<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BPL grade (bpl.bpl_grades) — the parent of both product types.
 * Columns: id, gradename, type, grade, deleted_at. No created/updated timestamps.
 */
class BplGrade extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_grades';
    public $timestamps = false;
    protected $guarded = [];
}
