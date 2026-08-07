<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One changeover: the append-only log behind ConversionSetup
 * (bil.conversion_setup_history, renamed from factory_preproduction_history).
 *
 * A row is written every time a line is set to a different product, so the
 * setup table holds "now" and this holds how it got there. ~25k rows and still
 * being written by the legacy screen through its compatibility view, so the
 * column names are legacy: `quantity` here is `bundles` on the setup row, and
 * the timestamp column is `date_modified`.
 */
class ConversionSetupHistory extends Model
{
    protected $connection = 'bil';
    protected $table = 'conversion_setup_history';
    public $timestamps = false;

    protected $fillable = ['linename', 'productname', 'quantity', 'username', 'date_modified'];

    protected $casts = [
        'quantity' => 'integer',
        'date_modified' => 'datetime',
    ];
}
