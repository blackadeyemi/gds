<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes on rawmaterials_supplier_deliveries.productid and .suppliercode
 * (~129k rows, MyISAM). The Products and Suppliers grids disable delete for a
 * row that already appears in supplier deliveries; the guard loads the set of
 * delivered product ids / supplier codes once per render. Without an index that
 * `SELECT DISTINCT productid` is a full scan + temp table (~2.7s) run on EVERY
 * render (incl. every live-search keystroke) → intermittent 500s under load.
 * With the index it's an index scan (~ms). Reversible.
 */
return new class extends Migration
{
    protected string $conn = 'bil';
    protected string $table = 'rawmaterials_supplier_deliveries';

    /** index name => column */
    protected array $indexes = [
        'rmsd_productid_idx' => 'productid',
        'rmsd_suppliercode_idx' => 'suppliercode',
    ];

    public function up(): void
    {
        $db = DB::connection($this->conn);
        foreach ($this->indexes as $name => $column) {
            if (! $this->exists($db, $name)) {
                $db->statement("ALTER TABLE `{$this->table}` ADD INDEX `{$name}` (`{$column}`)");
            }
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->conn);
        foreach (array_keys($this->indexes) as $name) {
            if ($this->exists($db, $name)) {
                $db->statement("ALTER TABLE `{$this->table}` DROP INDEX `{$name}`");
            }
        }
    }

    private function exists($db, string $index): bool
    {
        return (int) ($db->selectOne(
            'SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$this->table, $index]
        )->c ?? 0) > 0;
    }
};
