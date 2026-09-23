<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expose the renamed BPL warehouse tables on the `bil` connection too.
 *
 * `2026_09_16_130000` renamed the four movement tables and left the OLD names
 * as compat views on both schemas — which kept the legacy app working, but
 * created a gap: gds code that reads BPL data through the **bil** connection
 * (the Jumbo Rolls Stock page does, because it unions BIL and BPL legs in one
 * query) had no `bil.bpl_warehouse_entry` to read.
 *
 * It failed loudly rather than silently — "Base table or view not found" on
 * eleven Stock page tests — but the lesson is the general one: **a rename is
 * not finished when the base table moves. Every schema that reads it needs a
 * name to read it by.**
 *
 * So `bil` now carries BOTH names for each table: the old one for the legacy
 * app, the new one for gds. Both are `SELECT *` views over the single `bpl`
 * base table, so there is still exactly one copy of the data.
 */
return new class extends Migration
{
    private const TABLES = [
        'bpl_warehouse_entry',
        'bpl_softroll_warehouse_entry',
        'bpl_warehouse_exit',
        'bpl_softroll_warehouse_exit',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::connection('bil')->statement("DROP VIEW IF EXISTS `bil`.`{$table}`");
            DB::connection('bil')->statement(
                "CREATE VIEW `bil`.`{$table}` AS SELECT * FROM `bpl`.`{$table}`"
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::connection('bil')->statement("DROP VIEW IF EXISTS `bil`.`{$table}`");
        }
    }
};
