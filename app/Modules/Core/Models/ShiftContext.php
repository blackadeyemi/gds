<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shift-gated area (production, factory entrance, store exit, …). Holds many
 * named windows. When `is_active` is false the area is ungated — always open.
 * Runtime open/closed logic lives in {@see \Modules\Core\Support\ShiftService}.
 */
class ShiftContext extends Model
{
    protected $connection = 'core';
    protected $fillable = ['key', 'label', 'module', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function windows(): HasMany
    {
        return $this->hasMany(ShiftWindow::class)->orderBy('sort_order')->orderBy('id');
    }

    public function enabledWindows(): HasMany
    {
        return $this->windows()->where('is_enabled', true);
    }
}
