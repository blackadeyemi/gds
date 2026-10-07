<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy `bpl.bpl_packing_list` — what actually went into the containers, and
 * the document that travels with the shipment.
 *
 * `containers` is JSON, one entry per container:
 *
 *     [{ "id": "container_1", "name": "MSNU 739149-0", "weight": "1000",
 *        "goods": [{ "description": "SKT 30gsm, 2ply, …", "rolls": 18, "weight": 9939 }] }]
 *
 * `weight` on the container is its TARE; the goods' weights are net kilograms,
 * and gross = net + tare. `description` matches the proforma line's full
 * description, which is how the final invoice pairs the two up.
 *
 * `number` is stored with a PLACEHOLDER — `BPL/SN/:19/122`, where 19 is the
 * customer's id — and every screen swaps it for the customer's label when it
 * prints. It is the one number in the system that follows a renamed customer;
 * gds keeps writing it that way so the legacy screens keep agreeing with it.
 *
 * A PARTIAL shipment is a row whose number ends in `(A)`, `(B)`… — see
 * Support\PackingList::split(). Its parent carries `split = 1`.
 */
class BplPackingList extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_packing_list';
    public $timestamps = false;

    protected $fillable = ['order_id', 'number', 'containers', 'date', 'split'];

    protected $casts = ['containers' => 'array', 'order_id' => 'integer'];

    /** The default tare the legacy form offered for a container, in kilograms. */
    public const DEFAULT_TARE = 1000;

    public function order(): BelongsTo
    {
        return $this->belongsTo(BplSalesOrder::class, 'order_id');
    }

    public function isPartial(): bool
    {
        return str_ends_with((string) $this->number, ')');
    }

    public function rolls(): int
    {
        return (int) collect($this->containers ?? [])
            ->sum(fn ($c) => collect($c['goods'] ?? [])->sum('rolls'));
    }

    /** Net kilograms of paper. */
    public function netWeight(): float
    {
        return round((float) collect($this->containers ?? [])
            ->sum(fn ($c) => collect($c['goods'] ?? [])->sum('weight')), 2);
    }

    /** Net plus every container's tare. */
    public function grossWeight(): float
    {
        return round($this->netWeight() + (float) collect($this->containers ?? [])->sum(fn ($c) => (float) ($c['weight'] ?? 0)), 2);
    }

    public function containerCount(): int
    {
        return count($this->containers ?? []);
    }
}
