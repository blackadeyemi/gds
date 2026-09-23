<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy `bpl.bpl_waybill_payment` — what BPL owes a haulier per waybill.
 *
 * Read-only here: it exists so the Transporters page can ask how many payments
 * name a transporter before letting it be deleted. The legacy waybill screens
 * still own every write.
 */
class BplWaybillPayment extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_waybill_payment';
    public $timestamps = false;
    protected $guarded = ['*'];
}
