<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationModule extends Model
{
    protected $connection = 'core';
    protected $fillable = ['name', 'company', 'slug', 'icon', 'sort_order', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'module_id');
    }

    /**
     * Display label qualified by company so same-named areas across companies
     * are distinguishable — "BIL / Raw Materials" vs a future "BIL / Jumbo Rolls"
     * and "BPL / Jumbo Rolls". Cross-cutting modules (Admin, Reports) have no
     * company and show their name alone.
     */
    public function label(): string
    {
        return $this->company ? "{$this->company} / {$this->name}" : $this->name;
    }
}
