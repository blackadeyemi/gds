<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index `dateofuse` on factory_usage_rawmaterials (~120k rows). The Consumption
 * report filters by a date range on this column; unindexed that is a full scan
 * per page load. `dateofuse` stores a zero-padded `Y/m/d` string (lexically
 * ordered), so a day range is a quick range scan. Reversible.
 */
return new class extends Migration
{
    protected string $conn = 'bil';
    protected string $table = 'factory_usage_rawmaterials';
    protected string $index = 'fur_dateofuse_idx';

    public function up(): void
    {
        $db = DB::connection($this->conn);
        if (! $this->exists($db)) {
            $db->statement("ALTER TABLE `{$this->table}` ADD INDEX `{$this->index}` (`dateofuse`)");
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->conn);
        if ($this->exists($db)) {
            $db->statement("ALTER TABLE `{$this->table}` DROP INDEX `{$this->index}`");
        }
    }

    private function exists($db): bool
    {
        return (int) ($db->selectOne(
            'SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$this->table, $this->index]
        )->c ?? 0) > 0;
    }
};
