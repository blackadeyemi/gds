<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Aggregated raw-material stock per product (bil.rawmaterials_stock):
 * running quantity + total weight per productid at a location. Maintained by
 * Warehouse Entry (and other movements). No Eloquent timestamps.
 */
class RawMaterialStock extends Model
{
    protected $connection = 'bil';
    protected $table = 'rawmaterials_stock';
    public $timestamps = false;
    protected $guarded = [];
}
