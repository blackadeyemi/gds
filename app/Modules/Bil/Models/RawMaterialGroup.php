<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy raw-materials product group (bil.rawmaterials_groups).
 * Read-only master data; no timestamps on the legacy table.
 */
class RawMaterialGroup extends Model
{
    protected $connection = 'bil';
    protected $table = 'rawmaterials_groups';
    public $timestamps = false;
    protected $guarded = [];

    public function subgroups(): HasMany
    {
        return $this->hasMany(RawMaterialSubgroup::class, 'groupid');
    }
}
