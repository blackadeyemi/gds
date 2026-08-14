<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Company;
use Modules\Core\Models\Warehouse;

/**
 * One truckload of stock moving from one warehouse to another.
 *
 * `kind` is DERIVED from the two warehouses' companies, never chosen: same
 * company is an internal transfer, different is inter-company. That is the whole
 * answer to "how do we tell the two apart without a second screen" — the
 * destination already says which it is.
 */
class StockTransfer extends Model
{
    protected $connection = 'core';
    protected $table = 'stock_transfers';

    protected $fillable = [
        'module', 'transfer_number', 'reference',
        'from_warehouse_id', 'from_company_id', 'to_warehouse_id', 'to_company_id',
        'kind', 'truck_number', 'date_of_transfer', 'status',
        'dispatched_by', 'dispatched_by_name', 'dispatched_at',
        'received_by', 'received_by_name', 'received_at',
        'approved_by', 'approved_by_name', 'approved_at',
        'note', 'is_historic',
    ];

    protected $casts = [
        'from_warehouse_id' => 'integer',
        'from_company_id' => 'integer',
        'to_warehouse_id' => 'integer',
        'to_company_id' => 'integer',
        'date_of_transfer' => 'date',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'approved_at' => 'datetime',
        'is_historic' => 'boolean',
    ];

    public const INTERNAL = 'internal';
    public const INTER_COMPANY = 'inter_company';

    public const DISPATCHED = 'dispatched';
    public const RECEIVED = 'received';
    public const CANCELLED = 'cancelled';

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id')->withTrashed();
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id')->withTrashed();
    }

    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'from_company_id');
    }

    public function toCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'to_company_id');
    }

    /* ---------------- State ---------------- */

    public function isInterCompany(): bool
    {
        return $this->kind === self::INTER_COMPANY;
    }

    public function isReceived(): bool
    {
        return $this->status === self::RECEIVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /** Dispatched but not yet received — the goods are on a truck. */
    public function inTransit(): bool
    {
        return $this->status === self::DISPATCHED;
    }

    public function scopeInTransit(Builder $q): Builder
    {
        return $q->where('status', self::DISPATCHED)->where('is_historic', false);
    }

    public function scopeModule(Builder $q, string $module): Builder
    {
        return $q->where('module', $module);
    }

    /* ---------------- Totals ---------------- */

    public function totalBundles(): int
    {
        return (int) $this->lines()->sum('bundles');
    }

    public function totalReceived(): int
    {
        return (int) $this->lines()->sum('received_bundles');
    }

    /**
     * Bundles dispatched but not accounted for on receipt. Non-zero after a
     * short delivery, which is recorded rather than smoothed over.
     */
    public function shortfall(): int
    {
        return $this->isReceived() ? $this->totalBundles() - $this->totalReceived() : 0;
    }

    /** A one-line description for lists, messages and exports. */
    public function label(): string
    {
        return sprintf(
            '%s → %s%s',
            $this->fromWarehouse?->name ?? ($this->fromCompany?->name ?? 'unknown'),
            $this->toWarehouse?->name ?? ($this->toCompany?->name ?? 'unknown'),
            $this->transfer_number ? ' (#' . $this->transfer_number . ')' : ''
        );
    }
}
