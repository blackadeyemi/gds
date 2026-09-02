<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a customer return: an order line, and how much came back.
 *
 * `quantityrejected` is a PART OF `quantityreturned`, not additional to it — of
 * 100 bundles returned, 10 rejected means 90 were sellable. Reading it as a
 * separate quantity double-counts the return, which is the mistake the legacy's
 * two stock paths made in opposite directions.
 *
 * A return is identified by (`dateofreturn`, `returnnumber`), not by id: the
 * number is shared by everything one customer sent back that day, restarts
 * daily, and is what the printout groups on.
 *
 * ⚠️ NOT `sales_loading_return`. That is goods coming back off a truck in the
 * cage room before it left; this is a customer sending goods back after
 * delivery. Different table, different screen, different stock effect.
 *
 * `timestamp` here is a real MySQL TIMESTAMP with a default, unlike the unix
 * ints on `sales_loading` and `sales_delivery` — so it is left out of
 * `$fillable` and the database sets it.
 */
class SalesReturn extends Model
{
    protected $connection = 'bil';
    protected $table = 'sales_return';
    public $timestamps = false;

    protected $fillable = [
        'username', 'returnnumber', 'sod_id',
        'quantityreturned', 'quantityrejected', 'dateofreturn',
    ];

    protected $casts = [
        'returnnumber' => 'integer',
        'sod_id' => 'integer',
        'quantityreturned' => 'integer',
        'quantityrejected' => 'integer',
    ];

    /** What went back into sellable stock — the rest was rejected. */
    public function toStock(): int
    {
        return $this->quantityreturned - $this->quantityrejected;
    }

    public function scopeOn(Builder $q, string $dateSlash): Builder
    {
        return $q->where('dateofreturn', $dateSlash);
    }

    public function scopeForReturn(Builder $q, string $dateSlash, int $number): Builder
    {
        return $q->where('dateofreturn', $dateSlash)->where('returnnumber', $number);
    }
}
