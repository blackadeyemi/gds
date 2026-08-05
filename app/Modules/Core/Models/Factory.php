<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical site belonging to a company — Bil-1, Bil-2 and Gambini under
 * Belimpex; the PM2 and PM3 paper machines under Belpapyrus.
 *
 * `code` carries the string the legacy data uses (factory_production.factory,
 * bpl_production.papermachine), so the compatibility views and the name->id
 * triggers keep resolving even after the display name is changed.
 */
class Factory extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $fillable = ['company_id', 'name', 'code', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MachineLine::class);
    }
}
