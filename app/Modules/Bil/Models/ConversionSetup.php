<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\MachineLine;

/**
 * What a line is currently set up to convert (bil.conversion_setup, renamed
 * from factory_preproduction).
 *
 * Exactly one row per line — `linename` is UNIQUE — holding the finished-goods
 * product the line is running and the bundle target for the run. Changing it is
 * a changeover, and every change is appended to conversion_setup_history.
 *
 * `linename` stays alongside `line_id` because the legacy app still writes here
 * through the factory_preproduction compatibility view, names only; a trigger
 * resolves the id.
 */
class ConversionSetup extends Model
{
    protected $connection = 'bil';
    protected $table = 'conversion_setup';
    public $timestamps = false;

    protected $fillable = ['username', 'productname', 'linename', 'bundles', 'line_id', 'timestamp'];

    protected $casts = [
        'bundles' => 'integer',
        'line_id' => 'integer',
        'timestamp' => 'datetime',
    ];

    /** The value legacy stores when a line is idle. */
    public const IDLE = 'None';

    public function line(): BelongsTo
    {
        return $this->belongsTo(MachineLine::class, 'line_id');
    }

    public function isIdle(): bool
    {
        return trim((string) $this->productname) === '' || $this->productname === self::IDLE;
    }
}
