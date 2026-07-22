<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationModule extends Model
{
    protected $connection = 'core';
    protected $fillable = ['name', 'slug', 'icon', 'sort_order', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'module_id');
    }
}
