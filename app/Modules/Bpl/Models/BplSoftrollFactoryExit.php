<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A softroll leaving the BPL paper-machine floor
 * (bpl.bpl_softroll_factoryexit) — the softroll twin of BplFactoryExit.
 *
 * Two tables rather than one because the two streams have separate production
 * tables and separate downstream movements; see
 * Modules\Bpl\Support\RollBarcode for why they were ever hard to tell apart.
 * No `received_at`: softrolls do not go to BIL.
 */
class BplSoftrollFactoryExit extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_softroll_factoryexit';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'location_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
