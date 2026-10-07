<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Legacy `bpl.bpl_payment_terms` — the terms a proforma is raised on
 * ("30 Days After BL", "50% Advance, 50% Against Documents"), each with the
 * number of days that sets when payment falls due.
 *
 * `bpl_proforma.payment_term_id` names one, and the invoice prints the words.
 * Soft-deleted, as legacy did: term 8 ("1 Month After BL") was deleted in 2021
 * and a proforma still carries it.
 *
 * UNIQUE(payment_terms) covers deleted rows too — see Finance\PaymentTerms.
 */
class BplPaymentTerm extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_payment_terms';
    public $timestamps = false;

    protected $fillable = ['payment_terms', 'days'];

    protected $casts = ['days' => 'integer'];
}
