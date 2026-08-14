<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A jumbo roll (or one slice of one) consumed on a converting machine
 * (bil.factory_usage_reel).
 *
 * `location` is the factory, `linename` the line, `project` the machine, and
 * `pre_productname` what that machine was running — the legacy name quartet.
 * `line_id` is NOT written here: an INSERT/UPDATE trigger resolves it from
 * `linename` through `machine_map_line`.
 *
 * Unique on (shift, barcode). A sliced reel appears once per slice, with the
 * slice number appended to the parent barcode as a sixth segment.
 */
class JumboRollFactoryUsage extends Model
{
    protected $connection = 'bil';
    protected $table = 'factory_usage_reel';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'weight' => 'float',
        'is_deleted' => 'integer',
        'timestamp' => 'integer',
    ];
}
