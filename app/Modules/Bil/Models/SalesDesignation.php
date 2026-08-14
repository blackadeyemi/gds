<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy `bil.sales_designation` — the sales territories, as region → designation
 * (LAGOS → "LAGOS 1"/"LAGOS 2", NORTH → "NORTH 1".."NORTH 4", …). Ten rows,
 * with no screen to edit them in either app; they are read here to drive the
 * customer form's dependent Region/Designation pair.
 *
 * ⚠️ It is NOT a closed list for existing data. 651 customers have no
 * designation at all and several hundred carry a bare region name ("LAGOS",
 * "WEST") that predates the numbered territories, so the picker has to keep
 * whatever a customer already holds — see Livewire\Sales\Customers.
 */
class SalesDesignation extends Model
{
    protected $connection = 'bil';
    protected $table = 'sales_designation';
    public $timestamps = false;

    protected $guarded = [];

    /** region => [designation, …], in the order the pickers should show them. */
    public static function byRegion(): array
    {
        return static::query()
            ->orderBy('sales_region')->orderBy('sales_designation')
            ->get()
            ->groupBy('sales_region')
            ->map(fn ($g) => $g->pluck('sales_designation')->all())
            ->all();
    }
}
