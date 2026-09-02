<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a truck load: a sales-order detail, and how much of it went on.
 *
 * A LOAD is a `barcode` (e.g. 26-08-07-L1L-029) shared by every line that went
 * on the same truck — about five lines on average, up to eighteen. The header
 * fields (transporter, truck, driver, loader, cageroom) are repeated on every
 * line, which is why changing them updates the whole barcode at once.
 *
 * `status` is NOT a flag. It holds the DATE the load was closed off, and 582,792
 * of 583,096 rows have one; NULL means the load is still open — which is exactly
 * what the legacy modification and return screens filter on, and the only state
 * in which a load can still be touched.
 *
 * Written on the LEGACY table on purpose. gds rebuilds the screens, not the
 * schema: the table has 583k rows, the legacy app still reads it, the sales
 * chain (delivery, waybill) joins to it, and FinishedGoodsStock reads it
 * directly to take loaded bundles off warehouse stock. A new table would have
 * to be reconciled against all four.
 */
class SalesLoading extends Model
{
    protected $connection = 'bil';
    protected $table = 'sales_loading';
    public $timestamps = false;

    protected $fillable = [
        'loadnumber', 'loader', 'barcode', 'username', 'sod_id', 'cageroomcode',
        'transporterid', 'trucknumber', 'truckdriver', 'quantityloaded',
        'dateofloading', 'status', 'timestamp', 'sales_loading_customerid',
    ];

    protected $casts = [
        'loadnumber' => 'integer',
        'sod_id' => 'integer',
        'transporterid' => 'integer',
        'quantityloaded' => 'integer',
        'timestamp' => 'integer',
    ];

    /** The header fields that belong to the load, not to one line. */
    public const HEADER_FIELDS = [
        'transporterid', 'cageroomcode', 'loader', 'trucknumber', 'truckdriver',
    ];

    public function returns(): HasMany
    {
        return $this->hasMany(SalesLoadingReturn::class, 'loading_id');
    }

    /** Still open — nothing may be changed on a closed load. */
    public function isOpen(): bool
    {
        return $this->status === null || trim((string) $this->status) === '';
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('status');
    }

    public function scopeForLoad(Builder $q, string $barcode): Builder
    {
        return $q->where('barcode', $barcode);
    }
}
