<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A raw-material consumption record (bil.factory_usage_rawmaterials): one row
 * per barcode consumed on a factory line during a shift. Written by
 * Consumption alongside flipping the factory-entrance row to 'consumed'.
 * No Eloquent timestamps (legacy `timestamp` is a unix int, set explicitly).
 */
class RawMaterialFactoryUsage extends Model
{
    protected $connection = 'bil';
    protected $table = 'factory_usage_rawmaterials';
    public $timestamps = false;
    protected $guarded = [];
}
