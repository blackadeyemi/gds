<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataPage extends Model
{
    protected $connection = 'core';
    protected $fillable = ['key', 'label', 'per_page'];

    public function views(): HasMany
    {
        return $this->hasMany(DataView::class)->orderBy('sort_order');
    }

    public function enabledViews(): HasMany
    {
        return $this->views()->where('is_enabled', true);
    }
}
