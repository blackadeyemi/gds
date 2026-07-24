<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index `dateofcreation` on the delivery-staging and warehouse-entry tables.
 * The Supplier Deliveries and Warehouse Entry reports filter by a date range on
 * these columns; unindexed that is a full scan (~3s over 129k deliveries, ~5s
 * over 229k entries per page load). With the index a day's range is a quick
 * range scan. Both columns are safe to range-scan: deliveries store a
 * zero-padded `Y/m/d` string (lexicographically ordered) and warehouse-entry a
 * real DATE. Reversible.
 */
return new class extends Migration
{
    protected string $conn = 'bil';

    /** table => index name (all on the `dateofcreation` column). */
    protected array $targets = [
        'rawmaterials_supplier_deliveries' => 'rmsd_dateofcreation_idx',
        'rawmaterials_warehouse_entry' => 'rmwe_dateofcreation_idx',
    ];

    public function up(): void
    {
        $db = DB::connection($this->conn);
        foreach ($this->targets as $table => $index) {
            if (! $this->exists($db, $table, $index)) {
                $db->statement("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`dateofcreation`)");
            }
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->conn);
        foreach ($this->targets as $table => $index) {
            if ($this->exists($db, $table, $index)) {
                $db->statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
            }
        }
    }

    private function exists($db, string $table, string $index): bool
    {
        return (int) ($db->selectOne(
            'SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        )->c ?? 0) > 0;
    }
};
