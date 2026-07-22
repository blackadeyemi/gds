<?php

namespace Modules\Core\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    // Spatie's base model uses $guarded = [], so `description` and
    // `legacy_level` are already mass-assignable — no $fillable needed.
}
