<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make "how much of this reel is left?" an indexed lookup.
 *
 * A sliced jumbo roll is consumed under child barcodes — the reel is
 * `26-08-05-M3-025`, its slices `26-08-05-M3-025-1`, `-2`, … — so rolling a
 * reel up meant `barcode LIKE CONCAT(<reel>, '%')`. A LIKE whose prefix is a
 * COLUMN rather than a constant cannot use an index, so every reel scanned all
 * 287k usage rows: the stock position took 64 seconds.
 *
 * `reel_barcode` is the parent code, generated and stored, with an index that
 * covers the rollup (weight is in the index, so it never touches the row).
 * Same query, same numbers, 0.2s.
 *
 * Safe for the legacy app: a generated column cannot be written to, and every
 * legacy insert names its columns explicitly, so nothing there changes.
 */
return new class extends Migration
{
    /** table => [index name => index columns after reel_barcode] */
    private const TABLES = [
        'factory_usage_reel' => ['factory_usage_reel_reel_idx' => '`is_deleted`, `weight`'],
        'factory_event' => ['factory_event_reel_idx' => '`event`, `weight`'],
    ];

    public function up(): void
    {
        $bil = DB::connection('bil');

        foreach (self::TABLES as $table => $indexes) {
            if (! Schema::connection('bil')->hasColumn($table, 'reel_barcode')) {
                $bil->statement(
                    "ALTER TABLE `{$table}` ADD COLUMN `reel_barcode` VARCHAR(20)"
                    . " GENERATED ALWAYS AS (SUBSTRING_INDEX(`barcode`, '-', 5)) STORED"
                );
            }

            foreach ($indexes as $name => $rest) {
                if ($this->missingIndex($table, $name)) {
                    $bil->statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` (`reel_barcode`, {$rest})");
                }
            }
        }
    }

    public function down(): void
    {
        $bil = DB::connection('bil');

        foreach (self::TABLES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (! $this->missingIndex($table, $name)) {
                    $bil->statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
            }

            if (Schema::connection('bil')->hasColumn($table, 'reel_barcode')) {
                $bil->statement("ALTER TABLE `{$table}` DROP COLUMN `reel_barcode`");
            }
        }
    }

    private function missingIndex(string $table, string $name): bool
    {
        return DB::connection('bil')
            ->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]) === [];
    }
};
