<?php

namespace Modules\Bpl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Legacy `bpl.bpl_banks` — one row per bank ACCOUNT BPL deals with (its own
 * and the intermediary/correspondent banks payments route through), not one
 * per bank: "Zenith Bank Plc" appears once per account number.
 *
 * UNIQUE(number) and UNIQUE(name, number). `number` is nullable — a routing
 * bank (e.g. "Citibank NY, USA") has none — and an empty string is stored as
 * NULL so two number-less banks do not collide on the unique index.
 *
 * Named by `bpl_accounts` in three roles (beneficiary, intermediary,
 * correspondent). Hard-deleted, as legacy did — so a bank any account names,
 * deleted or not, stays: proformas still print through soft-deleted accounts.
 */
class BplBank extends Model
{
    protected $connection = 'bpl';
    protected $table = 'bpl_banks';
    public $timestamps = false;

    protected $fillable = ['name', 'number', 'sortcode', 'swiftcode', 'address'];

    /** "Name (number)" — how legacy listed a bank in the account pickers. */
    public function getLabelAttribute(): string
    {
        return $this->name . ($this->number ? ' (' . $this->number . ')' : '');
    }

    /** Accounts (deleted ones included) naming this bank in any role. */
    public static function accountCountSql(): string
    {
        return '(SELECT COUNT(*) FROM `bpl_accounts` a WHERE a.`beneficiary` = `bpl_banks`.`id`'
            . ' OR a.`intermediary` = `bpl_banks`.`id` OR a.`correspondent` = `bpl_banks`.`id`)';
    }

    public static function accountsNaming(int $bankId): int
    {
        return DB::connection('bpl')->table('bpl_accounts')
            ->where(fn ($q) => $q->where('beneficiary', $bankId)->orWhere('intermediary', $bankId)->orWhere('correspondent', $bankId))
            ->count();
    }
}
