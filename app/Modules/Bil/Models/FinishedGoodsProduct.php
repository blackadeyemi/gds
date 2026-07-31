<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Finished-goods product, i.e. one QC specification sheet (bil.products).
 *
 * The master record behind the legacy Quality Control dashboard: product
 * identity (code, name, group), how it is made (ply, machine, hardroll grade
 * types, embossing), how it is packed (rolls/packs/bundles) and its weights and
 * measurements — plus the revision number the spec is currently on.
 *
 * Deletes are soft (`is_deleted`), and every edit archives the previous spec
 * into `qc_revision` — see Modules\Bil\Support\QcRevisionArchive.
 */
class FinishedGoodsProduct extends Model
{
    protected $connection = 'bil';
    protected $table = 'products';
    protected $primaryKey = 'productid';
    public $timestamps = false;
    protected $guarded = [];

    /** Live products (the grid's default). */
    public function scopeActive($query)
    {
        return $query->where('products.is_deleted', 0);
    }

    /** Products in the trash, restorable from the Trash view. */
    public function scopeTrashed($query)
    {
        return $query->where('products.is_deleted', 1);
    }
}
