<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A gated page and the abilities it supports. Synced from config/pages.php.
 * Access is granted per role as "{key}:{ability}" permissions (see the Role
 * editor matrix); there is no page↔permission pivot anymore.
 */
class Page extends Model
{
    protected $connection = 'core';
    protected $fillable = ['key', 'label', 'module', 'abilities', 'sort_order'];
    protected $casts = ['abilities' => 'array'];

    /** Permission name for one of this page's abilities, e.g. "key:edit". */
    public function permissionName(string $ability): string
    {
        return $this->key . ':' . $ability;
    }
}
