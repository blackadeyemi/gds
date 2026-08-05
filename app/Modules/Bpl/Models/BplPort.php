<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BPL sea port (bpl.bpl_ports) — a curated port keyed to a country
 * (country_iso = countries.iso). Drives the country → port dropdown on
 * the Customers form (Export customers only).
 */
class BplPort extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_ports';
    public $timestamps = false;
    protected $guarded = [];
}
