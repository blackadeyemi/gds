<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A covering index on `bpl_delivery_details` for the Logistics reports.
 *
 * Every one of them starts from the same aggregate: the rolls on each waybill,
 * grouped by `delivery_barcode` and joined to `bpl_production` for each roll's
 * weight. `bdd_waybill_idx` held only `delivery_barcode`, so the grouping had
 * to fetch all 23,860 rows to read the barcode beside it. With both columns in
 * one index the grouping is answered from the index alone:
 *
 *   the grouping, measured on dev   285ms -> 7ms
 *
 * The other half of that aggregate — one lookup into `bpl_production` per row for
 * a weight — stays at about 240ms and is left alone DELIBERATELY. A covering
 * `(barcode, deleted_at, weight)` was tried there and the optimiser ignored
 * it: `barcode` is effectively unique, so the existing index already gives an
 * eq_ref of exactly one row, and MySQL prefers the narrower one. The composite
 * bought nothing measurable and would have cost a write on every roll BPL
 * makes, so it is not here.
 *
 * This index is only worth having since 2026_09_25_150000 made `barcode`
 * utf8mb3 to match `bpl_production`. While the charsets differed the join
 * could not use an index on either side.
 */return new class extends Migration
{
    /** index name => [table, columns] */
    private const INDEXES = [
        'bdd_waybill_barcode_idx' => ['bpl_delivery_details', '`delivery_barcode`, `barcode`'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => [$table, $columns]) {
            if (! Schema::connection('bpl')->hasTable($table) || $this->hasIndex($table, $index)) {
                continue;
            }

            DB::connection('bpl')->statement(
                "ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})"
            );
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $index => [$table]) {
            if (! Schema::connection('bpl')->hasTable($table) || ! $this->hasIndex($table, $index)) {
                continue;
            }

            DB::connection('bpl')->statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return (bool) DB::connection('bpl')->selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE()'
            . ' AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        );
    }
};
