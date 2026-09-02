<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cover the date-ranged rollups the Jumbo Rolls reports and dashboard run.
 *
 * `dateofentrance` and `dateofuse` carried no index at all, so every time
 * series, every count over a range and every by-day summary scanned the whole
 * table — 88k entrance rows and 287k usage rows. The statistics overview took
 * 7.6 seconds.
 *
 * Both are covering: the leading date column narrows to the range, and the
 * columns after it are the ones these queries group and sum on, so the rollups
 * are answered from the index without touching a row. Same shape as
 * `fur_stats_cover` on the raw-material usage table.
 */
return new class extends Migration
{
    /** table => [index => columns] */
    private const INDEXES = [
        'factory_entrance_reel' => [
            'fer_stats_cover' => '`dateofentrance`, `is_deleted`, `location`, `barcode`',
        ],
        'factory_usage_reel' => [
            'fur_reel_stats_cover' => '`dateofuse`, `is_deleted`, `weight`, `location`, `linename`, `project`, `shift`',
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if ($this->missing($table, $name)) {
                    DB::connection('bil')->statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columns})");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (! $this->missing($table, $name)) {
                    DB::connection('bil')->statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
            }
        }
    }

    private function missing(string $table, string $name): bool
    {
        return DB::connection('bil')->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]) === [];
    }
};
