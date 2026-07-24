<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Covering indexes for the Raw Materials Statistics dashboard.
 *
 * The wide-range (12-month) charts aggregate weight / group by product / group
 * by supplier|shift over the big MyISAM tables. A plain date-range scan then
 * fetches every matching row from the .MYD (random I/O) — ~5s for 46k delivery
 * rows, ~2s for factory usage. These covering indexes carry the aggregated
 * columns in the index itself, so the range scan is index-only (no row reads):
 * deliveries sum/group dropped ~5s → <120ms; consumption ~3.5s → ~2.4s.
 *
 * Guarded (skips if the index already exists). Reversible.
 */
return new class extends Migration
{
    protected string $conn = 'bil';

    /** table => [index name => "col list"] */
    protected array $indexes = [
        'rawmaterials_supplier_deliveries' => [
            'rmsd_cover_idx' => '`dateofcreation`, `productid`, `suppliercode`, `weight`',
        ],
        'factory_usage_rawmaterials' => [
            'fur_stats_cover' => '`dateofuse`, `is_deleted`, `barcode`, `weight`, `location`, `shift`',
        ],
    ];

    public function up(): void
    {
        $db = DB::connection($this->conn);
        foreach ($this->indexes as $table => $defs) {
            foreach ($defs as $name => $cols) {
                if (! $this->exists($db, $table, $name)) {
                    $db->statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$cols})");
                }
            }
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->conn);
        foreach ($this->indexes as $table => $defs) {
            foreach (array_keys($defs) as $name) {
                if ($this->exists($db, $table, $name)) {
                    $db->statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
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
