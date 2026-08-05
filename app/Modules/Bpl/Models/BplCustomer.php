<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BPL customer (bpl.bpl_customers). Local/Export customers of jumbo rolls.
 * Columns: id, type, customername (unique), customerlabel (unique),
 * customercountry, customeraddress, customertelephone, port, fax, email,
 * products (json of hardroll product ids), created_at, deleted_at.
 * created_at is filled by the DB default; there is no updated_at.
 */
class BplCustomer extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_customers';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'products' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
    ];
}
