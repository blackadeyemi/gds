<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy `bpl.bpl_bill_of_lading` — the carrier's receipt for one shipment.
 *
 * One per packing list. It carries the carrier's own number, the dates the
 * goods shipped, the B/L was issued and the vessel arrived, and `doc`: the
 * FILENAME of the scanned document, which lives in a folder shared with the
 * legacy app (`config('bpl.bl_docs_path')` — see Support\BillOfLadingDocs).
 *
 * Its existence freezes the packing list: neither the legacy screens nor gds
 * will change or delete one once a bill of lading names it.
 */
class BplBillOfLading extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_bill_of_lading';
    public $timestamps = false;

    protected $fillable = ['packing_id', 'number', 'issued_date', 'shipped_date', 'arrival_date', 'doc', 'timestamp'];

    protected $casts = ['packing_id' => 'integer'];

    public function packingList(): BelongsTo
    {
        return $this->belongsTo(BplPackingList::class, 'packing_id');
    }

    /** Shipped but not yet arrived — what the shipping desk is waiting on. */
    public function inTransit(): bool
    {
        return trim((string) $this->arrival_date) === '';
    }
}
