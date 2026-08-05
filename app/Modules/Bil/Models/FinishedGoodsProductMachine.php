<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Factory;
use Modules\Core\Models\MachineLine;
use Modules\Core\Models\MachineProject;

/**
 * One machine a finished-goods product is made on: a Factory → Line → Project
 * path into the hierarchy (see Modules\Core\Models\Factory). A product may have
 * several, which is what `products.mach` — a single name — could never express.
 *
 * Line and project are optional so an assignment can be pinned at whatever
 * depth is meaningful: a whole line, or one specific project on it.
 *
 * The relations cross databases (this table is in `bil`, the hierarchy is in
 * `core`), so the ids are plain indexed columns rather than foreign keys —
 * eager loading works, database-level cascade does not.
 */
class FinishedGoodsProductMachine extends Model
{
    protected $connection = 'bil';
    protected $table = 'product_machines';
    protected $fillable = ['product_id', 'factory_id', 'line_id', 'project_id', 'sort_order'];

    protected $casts = [
        'product_id' => 'integer',
        'factory_id' => 'integer',
        'line_id' => 'integer',
        'project_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(FinishedGoodsProduct::class, 'product_id', 'productid');
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(MachineLine::class, 'line_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MachineProject::class, 'project_id');
    }

    /**
     * The assignment as one readable string, most specific part last:
     * "Bil-1 → REW 9 → CMW 200 1 (A)". Used for the grid column, the spec
     * sheet, and the `products.mach` summary the legacy screens still read.
     */
    public function label(string $separator = ' → '): string
    {
        $parts = array_filter([
            $this->factory?->name,
            $this->line?->name,
            $this->project?->name,
        ]);

        return implode($separator, $parts);
    }

    /** The most specific machine named by this assignment. */
    public function machineName(): string
    {
        return $this->project?->name
            ?? $this->line?->name
            ?? $this->factory?->name
            ?? '';
    }
}
