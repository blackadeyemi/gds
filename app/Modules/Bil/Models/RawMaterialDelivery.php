<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier-delivery barcode — the delivery/staging stage of raw-materials
 * receiving. A barcode is created here when goods arrive from a supplier;
 * Warehouse Entry later promotes it into the live `rawmaterials` stock table.
 * No Eloquent timestamps (the legacy `timestamp`/`dateofcreation` columns are
 * managed explicitly).
 *
 * The table was renamed from the legacy `rawmaterials_copy`; a compatibility
 * VIEW named `rawmaterials_copy` still points here so the legacy app keeps
 * working (see migration 2026_07_23_140000).
 */
class RawMaterialDelivery extends Model
{
    protected $connection = 'bil';
    protected $table = 'rawmaterials_supplier_deliveries';
    public $timestamps = false;
    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(RawMaterialProduct::class, 'productid');
    }
}
