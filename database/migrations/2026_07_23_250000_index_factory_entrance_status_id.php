<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Composite index (status, id) on factory_entrance_rawmaterials (~178k rows,
 * ~58k with status NULL). The Factory Remaining report lists on-floor items
 * (status IS NULL) newest-first; without this index that means filtering 58k
 * rows and a filesort per page (~9s). With it the newest on-floor rows come
 * straight off the index. Reversible.
 */
return new class extends Migration
{
    protected string $conn = 'bil';
    protected string $table = 'factory_entrance_rawmaterials';
    protected string $index = 'fer_status_id_idx';

    public function up(): void
    {
        $db = DB::connection($this->conn);
        if (! $this->exists($db)) {
            $db->statement("ALTER TABLE `{$this->table}` ADD INDEX `{$this->index}` (`status`, `id`)");
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
