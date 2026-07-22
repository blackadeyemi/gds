<?php

namespace Modules\Core\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Permission extends SpatiePermission
{
    // Spatie's base model uses $guarded = [], so `description` and `module_id`
    // are already mass-assignable — no $fillable needed.

    public function module(): BelongsTo
    {
        return $this->belongsTo(ApplicationModule::class, 'module_id');
    }
}
