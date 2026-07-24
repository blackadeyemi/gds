<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A raw-material factory-entrance record — one row per barcode scanned into a
 * factory location after it was exited from the warehouse. `status` NULL = on
 * the factory floor, 'consumed' = used, 'return' = returned to store.
 *
 * The table was renamed from the legacy `factoryentrance_rawmaterial`; a
 * compatibility VIEW of that name still points here (migration
 * 2026_07_23_200000). No Eloquent timestamps.
 */
class RawMaterialFactoryEntrance extends Model
{
    protected $connection = 'bil';
    protected $table = 'factory_entrance_rawmaterials';
    public $timestamps = false;
    protected $guarded = [];
}
