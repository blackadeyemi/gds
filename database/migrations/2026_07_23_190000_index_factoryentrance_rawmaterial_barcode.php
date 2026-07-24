<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index `barcode` on factoryentrance_rawmaterial (178k rows, non-unique —
 * barcodes repeat). Factory Entrance looks a barcode up here on every scan;
 * without the index that's a full table scan. Reversible.
 */
return new class extends Migration
{
    protected string $conn = 'bil';
    protected string $table = 'factoryentrance_rawmaterial';
    protected string $index = 'fer_barcode_idx';

    public function up(): void
    {
        $db = DB::connection($this->conn);
        if (! $this->exists($db)) {
            $db->statement("ALTER TABLE `{$this->table}` ADD INDEX `{$this->index}` (`barcode`)");
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
