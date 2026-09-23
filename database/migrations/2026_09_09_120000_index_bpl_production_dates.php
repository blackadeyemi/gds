<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index the manufacture date on both production tables.
 *
 * `dateofmanufacture` carried no index at all, and three things read it:
 *
 *  1. the Production grids, now windowed to the last 12 months — a range scan
 *     over 279,282 rows without this;
 *  2. `RollBarcode::nextSequence()`, which finds the day's highest barcode
 *     ordinal on every single roll entered (`dateofmanufacture = ? AND
 *     papermachine = ?`) — a full scan per save;
 *  3. `RollBarcode::nextMonthlySerial()`, `dateofmanufacture LIKE 'Y/m%'`,
 *     which uses the same leading column.
 *
 * The column is legacy 'Y/m/d' TEXT, so it sorts lexicographically — which for
 * a zero-padded ISO-ish format is also chronological. That is why a plain BTREE
 * range works and no column change is needed.
 */
return new class extends Migration
{
    private const INDEXES = [
        'bpl_production' => 'bpl_production_dom_machine_idx',
        'bpl_softroll_production' => 'bpl_softroll_production_dom_machine_idx',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if (! $this->hasIndex($table, $index)) {
                DB::connection('bpl')->statement(
                    "ALTER TABLE `{$table}` ADD INDEX `{$index}` (`dateofmanufacture`, `papermachine`)"
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if ($this->hasIndex($table, $index)) {
                DB::connection('bpl')->statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
            }
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::connection('bpl')
            ->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]) !== [];
    }
};
