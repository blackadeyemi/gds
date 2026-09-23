<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Legacy `bpl.bpl_accounts` — the receiving accounts a proforma tells the
 * customer to pay into: a beneficiary bank, optionally routed through an
 * intermediary and a correspondent bank, with a further-credit account and a
 * currency. `bpl_proforma.account_id` names one; the invoice prints its banks.
 *
 * Soft-deleted (legacy did the same), because a proforma keeps printing the
 * account it was raised against. UNIQUE(beneficiary, currency_id) covers the
 * deleted rows too — a deleted account still blocks a new one for the same
 * bank and currency, so it has to be restored instead.
 */
class BplAccount extends Model
{
    use SoftDeletes;

    protected $connection = 'bpl';
    protected $table = 'bpl_accounts';
    public $timestamps = false;

    protected $fillable = ['account', 'beneficiary', 'intermediary', 'correspondent', 'further_acc', 'currency_id'];

    public function beneficiaryBank(): BelongsTo
    {
        return $this->belongsTo(BplBank::class, 'beneficiary');
    }

    public function intermediaryBank(): BelongsTo
    {
        return $this->belongsTo(BplBank::class, 'intermediary');
    }

    public function correspondentBank(): BelongsTo
    {
        return $this->belongsTo(BplBank::class, 'correspondent');
    }
}
