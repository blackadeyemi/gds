<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A hardroll as it comes off a BPL paper machine (bpl.bpl_production).
 *
 * `dateofmanufacture` is legacy 'Y/m/d' text, `papermachine` is the legacy
 * machine name ('PM2'/'PM3'), and `hold` is the string 'hold' or NULL — the
 * flat PHP app still reads all three, so they keep their shapes.
 *
 * `status` is NULL while the reel is on the factory floor and 'Exited' once
 * Factory Exit has scanned it out. See [[bil-jumbo-rolls]] for what happens to
 * a Belimpex reel after that.
 */
class BplProduction extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_production';
    public $timestamps = false;
    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(BplProductHardroll::class, 'product_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BplCustomer::class, 'customer_id');
    }
}
