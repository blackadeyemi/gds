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
    protected $fillable = ['company_id', 'module', 'legacy_location_id', 'legacy_sales_code',
        'name', 'code', 'sort_order', 'is_active'];

    protected $casts = [
        'company_id' => 'integer',
        'legacy_location_id' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function gates(): HasMany
    {
        return $this->hasMany(WarehouseGate::class, 'warehouse_id')
            ->orderBy('sort_order')->orderBy('name');
    }

    /** Human label for what this warehouse stores. */
    public function moduleLabel(): string
    {
        return config('warehouses.modules')[$this->module] ?? '—';
    }

    /**
     * Can this warehouse actually hold stock yet?
     *
     * A module with no stock table behind it is a placeholder — the warehouse
     * can be created and named, but nothing can be received into it. Better to
     * say so than to accept a scan that goes nowhere.
     */
    public function moduleIsImplemented(): bool
    {
        return in_array($this->module, config('warehouses.implemented', []), true);
    }

    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Warehouses the legacy sales tables can name — the sales depots.
     *
     * `sales_order.warehousecode` and the load barcode's L/K/A letter are both
     * derived from `legacy_sales_code`, so a warehouse without one cannot be
     * the location of an order the legacy app still has to read.
     */
    public function scopeSalesDepot($query)
    {
        return $query->whereNotNull('legacy_sales_code')->where('legacy_sales_code', '<>', '');
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
