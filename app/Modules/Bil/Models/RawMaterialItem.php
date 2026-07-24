<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A raw-material item in live warehouse stock — one row per barcode once it
 * has been scanned in at Warehouse Entry (promoted from the delivery-staging
 * table). No Eloquent timestamps (the legacy `timestamp` column is
 * DB-defaulted; there are no created_at/updated_at).
 *
 * The table was renamed from the legacy `rawmaterials`; a compatibility VIEW
 * named `rawmaterials` still points here so the legacy app keeps working
 * (see migration 2026_07_23_150000).
 */
class RawMaterialItem extends Model
{
    protected $connection = 'bil';
    protected $table = 'rawmaterials_warehouse_entry';
    public $timestamps = false;
    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(RawMaterialProduct::class, 'productid');
    }
}
