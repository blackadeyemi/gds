<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Date indexes for the Warehouse Exit and Factory Entrance reports, which filter
 * by a date range on large tables (warehouse_exit ~311k, factory_entrance ~178k).
 * Unindexed each report is a full scan per page load. Both columns are real
 * DATE. Reversible.
 */
return new class extends Migration
{
    protected string $conn = 'bil';

    /** table => [index name, column]. */
    protected array $targets = [
        'rawmaterials_warehouse_exit' => ['rmse_dateofcreation_idx', 'dateofcreation'],
        'factory_entrance_rawmaterials' => ['fer_entrance_date_idx', 'entrance_date'],
    ];

    public function up(): void
    {
        $db = DB::connection($this->conn);
        foreach ($this->targets as $table => [$index, $column]) {
            if (! $this->exists($db, $table, $index)) {
                $db->statement("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`{$column}`)");
            }
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->conn);
        foreach ($this->targets as $table => [$index, $column]) {
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
