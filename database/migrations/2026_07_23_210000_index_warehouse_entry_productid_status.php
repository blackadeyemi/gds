<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Composite index (status, productid, location_id) on rawmaterials_warehouse_entry
 * (~230k rows). The Warehouse Stock report's delete-guard needs, per render, the
 * set of products that still have in-store barcodes (status IS NULL) grouped by
 * product/location; without this index that's a full table scan. With it the
 * GROUP BY is served straight from the index. Reversible.
 */
return new class extends Migration
{
    protected string $conn = 'bil';
    protected string $table = 'rawmaterials_warehouse_entry';
    protected string $index = 'rmwe_status_product_loc_idx';

    public function up(): void
    {
        $db = DB::connection($this->conn);
        if (! $this->exists($db)) {
            $db->statement("ALTER TABLE `{$this->table}` ADD INDEX `{$this->index}` (`status`, `productid`, `location_id`)");
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
