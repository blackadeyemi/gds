<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the Jumbo Rolls stock position.
 *
 * The stock page asks three questions the legacy schema had no index for:
 *
 *   - which reels are still on a BIL factory floor
 *     -> factory_entrance_reel (status, is_deleted), filtering 88k rows to ~270;
 *   - how much of a part-used reel is left
 *     -> factory_usage_reel (barcode) and factory_event (barcode, event), both
 *        reached by a `barcode LIKE 'parent%'` prefix scan. `factory_usage_reel`
 *        had only UNIQUE (shift, barcode), whose leading column is the shift, so
 *        a barcode-prefix lookup meant a full scan of 287k rows — per reel. This
 *        also serves the same rollup on the Consumption screen;
 *   - which reels this company owns and has not yet shipped out of BPL
 *     -> bpl_production (customer_id, status).
 *
 * Index-only, no data change.
 */
return new class extends Migration
{
    /** connection => [table => [index name => columns]] */
    private const INDEXES = [
        'bil' => [
            'factory_entrance_reel' => ['factory_entrance_reel_status_idx' => '`status`, `is_deleted`'],
            'factory_usage_reel' => ['factory_usage_reel_barcode_idx' => '`barcode`'],
            'factory_event' => ['factory_event_barcode_event_idx' => '`barcode`, `event`'],
        ],
        'bpl' => [
            'bpl_production' => ['bpl_production_customer_status_idx' => '`customer_id`, `status`'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $connection => $tables) {
            foreach ($tables as $table => $indexes) {
                foreach ($indexes as $name => $columns) {
                    if (! $this->hasIndex($connection, $table, $name)) {
                        DB::connection($connection)
                            ->statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columns})");
                    }
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $connection => $tables) {
            foreach ($tables as $table => $indexes) {
                foreach (array_keys($indexes) as $name) {
                    if ($this->hasIndex($connection, $table, $name)) {
                        DB::connection($connection)
                            ->statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                    }
                }
            }
        }
    }

    private function hasIndex(string $connection, string $table, string $name): bool
    {
        return DB::connection($connection)
            ->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]) !== [];
    }
};
