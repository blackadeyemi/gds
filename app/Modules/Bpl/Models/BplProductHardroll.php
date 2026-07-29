<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BPL hardroll product (bpl.bpl_products_hardroll — renamed from bpl_products).
 * A hardroll product = grade type + brightness, gsm, ply, width, diameter, slice.
 * Columns: id, old, productname, gradetype, brightness, gsm, ply, width,
 * diameter, slice, deleted_at.
 */
class BplProductHardroll extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_products_hardroll';
    public $timestamps = false;
    protected $guarded = [];
}
