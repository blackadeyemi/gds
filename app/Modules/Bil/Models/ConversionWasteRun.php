<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line converting one product, on one date, in one shift — and whether its
 * waste has been accounted for.
 *
 * The run is the unit the whole feature turns on. Waste entries hang off it,
 * and `confirmed_at` is what Conversion Output checks before letting the next
 * run start on the same line.
 *
 * Runs are DERIVED, not declared: a run exists because pallets were booked
 * against that line/date/shift/product in `factory_conversion`. A row here is
 * created lazily, the first time someone enters waste or confirms — so the
 * absence of a row means "nobody has touched this run yet", which is exactly
 * what should block the next one. See Support\ConversionWaste.
 *
 * Lives on the `core` connection (gds owns it) while the production it
 * describes lives on `bil`, which is why line_name and product_name are
 * denormalised: the two cannot be joined in one statement.
 */
class ConversionWasteRun extends Model
{
    protected $connection = 'bil';
    protected $table = 'conversion_waste_runs';

    protected $fillable = [
        'factory_id', 'line_id', 'productid', 'product_name', 'line_name',
        'production_date', 'shift', 'confirmed_at', 'confirmed_by',
        'confirmed_by_name', 'is_nil', 'note', 'opened_by',
    ];

    protected $casts = [
        'factory_id' => 'integer',
        'line_id' => 'integer',
        'productid' => 'integer',
        'production_date' => 'date',
        'confirmed_at' => 'datetime',
        'confirmed_by' => 'integer',
        'is_nil' => 'boolean',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(ConversionWasteEntry::class, 'run_id');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('confirmed_at');
    }

    public function scopeConfirmed(Builder $q): Builder
    {
        return $q->whereNotNull('confirmed_at');
    }

    /**
     * Oldest first, in production order — day before night within a date.
     *
     * A production day runs 07:00 to 06:59 the next morning (see the legacy
     * production_date.php), so both halves of a night carry the same date and
     * night always follows the day it is stamped with.
     */
    public function scopeInRunOrder(Builder $q, string $dir = 'asc'): Builder
    {
        return $q->orderBy('production_date', $dir)
            ->orderByRaw('FIELD(shift, ' . self::shiftOrderSql() . ') ' . ($dir === 'desc' ? 'desc' : 'asc'))
            ->orderBy('id', $dir);
    }

    /** Shift names in production order, as a quoted list for FIELD(). */
    public static function shiftOrderSql(): string
    {
        return "'day','night'";
    }

    /** How far through a production day a shift is. Unknown shifts sort last. */
    public static function shiftRank(?string $shift): int
    {
        return match (strtolower(trim((string) $shift))) {
            'day' => 1,
            'night' => 2,
            default => 9,
        };
    }

    /** A one-line description used in the block messages and the run picker. */
    public function label(): string
    {
        return sprintf(
            '%s — %s, %s shift, %s',
            $this->line_name ?: ('line #' . $this->line_id),
            $this->product_name ?: ('product #' . $this->productid),
            strtolower((string) $this->shift),
            $this->production_date?->format('d/m/Y') ?? ''
        );
    }

    public function totalWeight(): float
    {
        return (float) $this->entries()->sum('weight_kg');
    }
}
