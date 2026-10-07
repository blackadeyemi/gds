<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two invoice payments carry a `d/m/y` date in a column where every other row
 * holds `Y/m/d`.
 *
 *   id 136  '12/12/21'   id 137  '17/12/21'
 *
 * `bpl_invoice_payments`.`date` is a varchar, so nothing stopped them, and
 * because the column is only ever compared AS TEXT the damage is quiet but
 * total: '12/12/21' sorts before '2020/05/17' (the '1' beats the '2'), so
 * those two rows lead every chronological list, and a date range for December
 * 2021 — the month they belong to — excludes them.
 *
 * The reading is not a guess. Payment ids are chronological, and these two sit
 * between id 133 (2021/12/01) and id 138 (2021/12/17); their shipments were
 * packed in July and September 2021 and billed in September and November. Both
 * are December 2021, day first.
 *
 * Only these two shapes are touched, and only where the day cannot be a month
 * — the repair is written as a general `d/m/y` conversion but guarded to the
 * two ids, so a future row in a different wrong shape is not silently
 * reinterpreted.
 */
return new class extends Migration
{
    /** id => [the stored text, what it means] */
    private const REPAIRS = [
        136 => ['12/12/21', '2021/12/12'],
        137 => ['17/12/21', '2021/12/17'],
    ];

    public function up(): void
    {
        if (! Schema::connection('bpl')->hasTable('bpl_invoice_payments')) {
            return;
        }

        foreach (self::REPAIRS as $id => [$was, $now]) {
            // Matched on the old value as well as the id, so a row that has
            // already been corrected by hand — or replaced — is left alone.
            DB::connection('bpl')->table('bpl_invoice_payments')
                ->where('id', $id)->where('date', $was)->update(['date' => $now]);
        }
    }

    public function down(): void
    {
        if (! Schema::connection('bpl')->hasTable('bpl_invoice_payments')) {
            return;
        }

        foreach (self::REPAIRS as $id => [$was, $now]) {
            DB::connection('bpl')->table('bpl_invoice_payments')
                ->where('id', $id)->where('date', $now)->update(['date' => $was]);
        }
    }
};
