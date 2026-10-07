<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prepare `bpl_waybill_payment` for the gds screen.
 *
 * 1. **One payment record per waybill.** There was no index saying so, and the
 *    legacy screen checked nothing before inserting — two records for the same
 *    waybill would each carry their own balance and neither would be wrong.
 *    None of the 16 existing rows repeat, so the unique index goes on cleanly.
 *
 * 2. **The stored `status` is repaired.** The legacy insert never set it and
 *    nothing updated it afterwards: all 16 rows say 'Paid', including twelve
 *    with the full amount outstanding. Every legacy list derives its own status
 *    from the amounts and ignores the column, so nothing depended on the junk.
 *    gds keeps the column true — Outstanding / Partial / Paid — from the
 *    amounts, exactly as the lists derive it.
 *
 * 3. Indexes on the two foreign keys: a payment's history was found by scanning
 *    it, and so was "what has this transporter been paid".
 *
 * A dump refresh undoes 2 (it is a data backfill) and leaves this migration
 * marked as run — re-run the UPDATE by hand if the column matters again.
 */
return new class extends Migration
{
    private const INDEXES = [
        'bpl_waybill_payment' => [
            'bwp_barcode_unq' => ['UNIQUE', '(`barcode`)'],
            'bwp_transporter_idx' => ['', '(`transporter_id`)'],
        ],
        'bpl_waybill_payment_history' => [
            'bwph_payment_idx' => ['', '(`waybill_payment_id`)'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => [$type, $columns]) {
                if (! $this->hasIndex($table, $name)) {
                    DB::connection('bpl')->statement("ALTER TABLE `{$table}` ADD {$type} INDEX `{$name}` {$columns}");
                }
            }
        }

        DB::connection('bpl')->statement(
            "UPDATE `bpl_waybill_payment`
                SET `status` = CASE
                    WHEN COALESCE(`amount_paid`, 0) <= 0 THEN 'Outstanding'
                    WHEN COALESCE(`balance`, 0) > 0 THEN 'Partial'
                    ELSE 'Paid' END"
        );
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if ($this->hasIndex($table, $name)) {
                    DB::connection('bpl')->statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
            }
        }

        // The statuses are deliberately NOT put back to 'Paid': they were wrong.
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::connection('bpl')->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]) !== [];
    }
};
