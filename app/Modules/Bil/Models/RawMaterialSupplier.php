<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy raw-materials supplier (bil.rawmaterials_supplier).
 * Columns: id, supplierid, suppliername, suppliercode. No timestamps.
 */
class RawMaterialSupplier extends Model
{
    protected $connection = 'bil';
    protected $table = 'rawmaterials_supplier';
    public $timestamps = false;
    protected $guarded = [];
}
