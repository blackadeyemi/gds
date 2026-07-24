<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A raw-material warehouse-exit record — one row per barcode issued out of the
 * warehouse. Written by Warehouse Exit alongside flipping the item's
 * `rawmaterials_warehouse_entry.status` to 'Exited'. This table has
 * created_at/updated_at, so Eloquent timestamps are on.
 *
 * The table was renamed from the legacy `rawmaterials_store_exit`; a
 * compatibility VIEW named `rawmaterials_store_exit` still points here so the
 * legacy app keeps working (see migration 2026_07_23_170000).
 */
class RawMaterialWarehouseExit extends Model
{
    protected $connection = 'bil';
    protected $table = 'rawmaterials_warehouse_exit';
    protected $guarded = [];
}
