<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A (deleted_at, date) index on every BPL jumbo-roll movement table.
 *
 * The Jumbo Rolls reports all open on a single day and every one of them asks
 * the same thing — `deleted_at IS NULL AND date BETWEEN x AND y` — against
 * tables of 130,000 rows that had no index on `date` at all. Each leg of each
 * report was a full scan, four legs to a page load.
 *
 * `deleted_at` first and `date` second is the order that works: the live check
 * is an equality (IS NULL), so MySQL can seek to the live rows and then walk
 * the date range inside them. The reverse order would range-scan the dates and
 * filter the deleted ones out afterwards.
 *
 * `bpl_production` and `bpl_softroll_production` already carry the equivalent
 * (`*_live_dom_idx` on deleted_at + dateofmanufacture), so they are left alone.
 *
 * Measured on dev, one day of Warehouse Entry: 190ms -> 2ms.
 */
return new class extends Migration
{
    /** table => the date column to index beside `deleted_at`. */
    private const TABLES = [
        'bpl_factoryexit' => 'date',
        'bpl_softroll_factoryexit' => 'date',
        'bpl_warehouse_entry' => 'date',
        'bpl_softroll_warehouse_entry' => 'date',
        'bpl_warehouse_exit' => 'date',
        'bpl_softroll_warehouse_exit' => 'date',
        'bpl_warehouse_transfer' => 'date',
        'bpl_waiting_area_entry' => 'date',
        'bpl_waiting_area_exit' => 'date',
        'bpl_store_count' => 'date',
    ];

    private function indexName(string $table): string
    {
        return $table . '_live_date_idx';
    }

    public function up(): void
    {
        foreach (self::TABLES as $table => $column) {
            if (! Schema::connection('bpl')->hasTable($table)) {
                continue;
            }

            $index = $this->indexName($table);

            if ($this->hasIndex($table, $index)) {
                continue;
            }

            DB::connection('bpl')->statement(
                "ALTER TABLE `{$table}` ADD INDEX `{$index}` (`deleted_at`, `{$column}`)"
            );
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::connection('bpl')->hasTable($table)) {
                continue;
            }

            $index = $this->indexName($table);

            if ($this->hasIndex($table, $index)) {
                DB::connection('bpl')->statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
            }
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
