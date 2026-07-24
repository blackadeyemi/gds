<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A raw-material stock-transfer record (bil.rawmaterials_transfer): one row per
 * barcode moved between stores. Written by Stock Transfer alongside moving the
 * item's location and shifting the per-location stock aggregate. No Eloquent
 * timestamps (the legacy `timestamp` column is DB-defaulted).
 */
class RawMaterialTransfer extends Model
{
    protected $connection = 'bil';
    protected $table = 'rawmaterials_transfer';
    public $timestamps = false;
    protected $guarded = [];
}
