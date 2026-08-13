<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\WasteCause;
use Modules\Core\Models\WasteOrigin;

/**
 * One weighed line of waste on a run: a cause, an origin, and how much.
 *
 * `origin_ref` holds the grade type or group the origin classified it against.
 * It is stored by VALUE as well as by id because both lookups live on the `bil`
 * connection and this table on `core` — a report cannot join to them, so the
 * label has to travel with the row.
 */
class ConversionWasteEntry extends Model
{
    protected $connection = 'core';
    protected $table = 'conversion_waste_entries';

    protected $fillable = [
        'run_id', 'cause_id', 'origin_id', 'origin_ref', 'origin_ref_id',
        'weight_kg', 'user_id', 'username',
    ];

    protected $casts = [
        'run_id' => 'integer',
        'cause_id' => 'integer',
        'origin_id' => 'integer',
        'origin_ref_id' => 'integer',
        // Cast as a string, not a float: the column is decimal(12,3) precisely
        // so that a total of weighed entries adds up to what was weighed.
        'weight_kg' => 'decimal:3',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConversionWasteRun::class, 'run_id');
    }

    public function cause(): BelongsTo
    {
        return $this->belongsTo(WasteCause::class, 'cause_id')->withTrashed();
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(WasteOrigin::class, 'origin_id')->withTrashed();
    }
}
