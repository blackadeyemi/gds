<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes on factory_entrance_rawmaterials.product_id and .location_id
 * (~178k rows, ~57k on-floor). The Factory Floor Stock report shows a fast
 * join-free total (the display left-joins don't change the count), but its
 * Factory/Product filters count on these base columns — un-indexed that count
 * was ~0.9s. With the index it's ~ms. Reversible.
 */
return new class extends Migration
{
    protected string $conn = 'bil';
    protected string $table = 'factory_entrance_rawmaterials';

    /** index name => column */
    protected array $indexes = [
        'fer_product_id_idx' => 'product_id',
        'fer_location_id_idx' => 'location_id',
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
