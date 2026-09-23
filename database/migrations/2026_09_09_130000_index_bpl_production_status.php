<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two indexes the Production screens need.
 *
 * 1. `status` — the "On the floor" view counts `status IS NULL`, and the only
 *    index carrying the column was `(customer_id, status)`, whose leading
 *    column the query does not filter on. EXPLAIN said `type: ALL`, 268,134
 *    rows: a full table scan on every render of that view, ~123 ms.
 *
 * 2. `(deleted_at, dateofmanufacture)` — the default view's 12-month count
 *    could use the date index for the range but still had to read rows to test
 *    `deleted_at IS NULL` ("Using index condition; Using where", ~146 ms).
 *    Leading with `deleted_at` makes the pair an exact match for
 *    `deleted_at IS NULL AND dateofmanufacture >= ?`, and covering for a count.
 *
 * Both tables get both, so the two tabs behave the same way.
 */
return new class extends Migration
{
    private const INDEXES = [
        'bpl_production' => [
            'bpl_production_status_idx' => '(`status`)',
            'bpl_production_live_dom_idx' => '(`deleted_at`, `dateofmanufacture`)',
        ],
        'bpl_softroll_production' => [
            'bpl_softroll_production_status_idx' => '(`status`)',
            'bpl_softroll_production_live_dom_idx' => '(`deleted_at`, `dateofmanufacture`)',
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
