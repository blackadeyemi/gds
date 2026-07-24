<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add a (non-unique) index on `barcode` to the core raw-materials lookup
 * tables. Every scan/entry/exit looks a row up by barcode, and these tables
 * had NO barcode index — so each lookup was a full table scan (the delivered-
 * but-not-entered query took ~8s on 229k rows). Barcodes aren't unique, hence
 * a plain index. Benefits the legacy app too. Reversible.
 */
return new class extends Migration
{
    protected string $conn = 'bil';

    protected array $targets = [
        'rawmaterials_warehouse_entry' => 'rmwe_barcode_idx',
        'rawmaterials_supplier_deliveries' => 'rmsd_barcode_idx',
        'rawmaterials_store_exit' => 'rmse_barcode_idx',
    ];

    public function up(): void
    {
        $db = DB::connection($this->conn);
        foreach ($this->targets as $table => $index) {
            if (! $this->indexExists($db, $table, $index)) {
                $db->statement("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`barcode`)");
            }
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->conn);
        foreach ($this->targets as $table => $index) {
            if ($this->indexExists($db, $table, $index)) {
                $db->statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
            }
        }
    }

    private function indexExists($db, string $table, string $index): bool
    {
        return (int) ($db->selectOne(
            'SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        )->c ?? 0) > 0;
    }
};
