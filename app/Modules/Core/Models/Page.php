<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A gated page — the unit of access control. Synced from config/pages.php.
 * Reachable when any of the current user's permissions include it.
 */
class Page extends Model
{
    protected $connection = 'core';
    protected $fillable = ['key', 'label', 'module', 'sort_order'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_page');
    }
}
