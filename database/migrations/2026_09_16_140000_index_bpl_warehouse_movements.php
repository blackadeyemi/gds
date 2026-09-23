<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the Warehouse Stock position.
 *
 * Held stock is "every live entry with no matching live exit". That drives off
 * the entry table and probes the exit table once per row, and neither had an
 * index the query could use: EXPLAIN showed a full 132,336-row scan of
 * `bpl_warehouse_entry` with `Using temporary` for the grouping, and the page
 * took **13-16 seconds** per view.
 *
 *  - `(deleted_at, barcode, location_id)` on the entry tables covers the whole
 *    driving scan: the filter, the join key and the grouping column.
 *  - `(barcode, deleted_at)` on the exit tables makes the NOT EXISTS covering.
 *
 * Deliberately NOT solved by driving off `warehouse_entry.status` instead,
 * which the existing `(status, deleted_at, location_id)` index would make
 * instant. `status` is a denormalised copy of the exit row and the two already
 * disagree on 3 of 2,631 rows — 2 entries left un-stamped and 1 stamped with no
 * exit behind it. Those disagreements are exactly what the page exists to show,
 * so the slow-but-true definition stays and gets an index instead.
 */
return new class extends Migration
{
    private const INDEXES = [
        'bpl_warehouse_entry' => [
            'bpl_warehouse_entry_held_idx' => '(`deleted_at`, `barcode`, `location_id`)',
        ],
        'bpl_softroll_warehouse_entry' => [
            'bpl_softroll_warehouse_entry_held_idx' => '(`deleted_at`, `barcode`, `location_id`)',
        ],
        'bpl_warehouse_exit' => [
            'bpl_warehouse_exit_barcode_live_idx' => '(`barcode`, `deleted_at`)',
        ],
        'bpl_softroll_warehouse_exit' => [
            'bpl_softroll_warehouse_exit_barcode_live_idx' => '(`barcode`, `deleted_at`)',
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! $this->hasIndex($table, $name)) {
                    DB::connection('bpl')->statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}");
                }
            }
        }
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
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::connection('bpl')
            ->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]) !== [];
    }
};
