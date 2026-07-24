<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy raw-materials product sub-group (bil.rawmaterials_subgroups).
 * Belongs to a group; carries its own sub_barcode. No timestamps.
 */
class RawMaterialSubgroup extends Model
{
    protected $connection = 'bil';
    protected $table = 'rawmaterials_subgroups';
    public $timestamps = false;
    protected $guarded = [];

    public function group(): BelongsTo
    {
        return $this->belongsTo(RawMaterialGroup::class, 'groupid');
    }
}
