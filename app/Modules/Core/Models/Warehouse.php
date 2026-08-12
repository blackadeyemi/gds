<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A warehouse belonging to a company — the sibling of Factory.
 *
 * A company owns factories (where goods are made) and warehouses (where they are
 * stored); each owns the gates goods move through. Warehouses are company-wide
 * structure rather than a finished-goods concern, because BPL stores goods too.
 *
 * The legacy model had no warehouse at all: `storebundle` hard-coded a single
 * `warehousecode = '01'` and `storebundle_floor` three fixed floors. This table
 * also supersedes `storelocations`, a rack-line layout abandoned in 2018.
 *
 * What is actually STORED in a warehouse stays per module, because a product id
 * means a different thing in each — see FgWarehouseStock for finished goods.
 */
class Warehouse extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $fillable = ['company_id', 'name', 'code', 'sort_order', 'is_active'];

    protected $casts = [
        'company_id' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entrances(): HasMany
    {
        return $this->hasMany(WarehouseEntrance::class, 'warehouse_id')
            ->orderBy('sort_order')->orderBy('name');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
