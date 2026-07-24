<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy raw-materials product (bil.rawmaterials_products).
 * Columns: id, storecode, productname, accountcode, groupid, subgroupid, common.
 * No timestamps on the legacy table.
 */
class RawMaterialProduct extends Model
{
    protected $connection = 'bil';
    protected $table = 'rawmaterials_products';
    public $timestamps = false;
    protected $guarded = [];

    public function group(): BelongsTo
    {
        return $this->belongsTo(RawMaterialGroup::class, 'groupid');
    }

    public function subgroup(): BelongsTo
    {
        return $this->belongsTo(RawMaterialSubgroup::class, 'subgroupid');
    }
}
