<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What kind of work a machine service job was — Maintenance, Repair, and
 * anything else added later from Settings > Service Types.
 */
class ServiceType extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
