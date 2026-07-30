<?php

namespace Modules\Core\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends SpatiePermission
{
    // Spatie's base model uses $guarded = [], so `description` and `module_id`
    // are already mass-assignable — no $fillable needed.

    public function module(): BelongsTo
    {
        return $this->belongsTo(ApplicationModule::class, 'module_id');
    }

    /** Pages this permission grants access to. */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class, 'permission_page');
    }
}
