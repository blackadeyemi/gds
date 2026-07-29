<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Records how each in-store barcode entered the warehouse: 'supplier' (the
 * normal delivery → warehouse-entry path) or 'factory' (an approved Factory
 * Return that put the item — or a partial-return child barcode — back in store).
 *
 * A `source` column on `rawmaterials_warehouse_entry` (renamed from legacy
 * `rawmaterials`, MyISAM ~229k rows). Additive and NULL-safe for the still-live
 * legacy app: the `rawmaterials` compatibility VIEW enumerates its columns
 * explicitly, so it does NOT expose `source` — legacy inserts through the view
 * omit it and the base table's DEFAULT 'supplier' applies. Existing rows default
 * to 'supplier'; the backfill flips those whose barcode has an APPROVED
 * return_approval row to 'factory' (covers both return kinds: Non-Consumed
 * reuses the original barcode, Partially Consumed makes a `<parent>-A` child).
 *
 * Also indexes `return_approval.barcode` (only a PK before) — needed by the
 * backfill, the Factory Returns report, and the return reprint. Reversible.
 */
return new class extends Migration
{
    protected string $conn = 'bil';
    protected string $table = 'rawmaterials_warehouse_entry';

    public function up(): void
    {
        $db = DB::connection($this->conn);

        if (! $this->hasColumn($db, $this->table, 'source')) {
            $db->statement("ALTER TABLE `{$this->table}` ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'supplier'");
        }
        if (! $this->hasIndex($db, $this->table, 'rmwe_source_idx')) {
            $db->statement("ALTER TABLE `{$this->table}` ADD INDEX `rmwe_source_idx` (`source`)");
        }
        if (! $this->hasIndex($db, 'return_approval', 'ra_barcode_idx')) {
            $db->statement('ALTER TABLE `return_approval` ADD INDEX `ra_barcode_idx` (`barcode`)');
        }

        // Backfill: mark every warehouse-entry barcode that was ever an approved
        // return as 'factory'. Both barcodes are latin1, so the join uses the
        // fresh index (no CONVERT). Idempotent.
        $db->statement(
            "UPDATE `{$this->table}` w
             JOIN `return_approval` r ON r.`barcode` = w.`barcode` AND r.`status` = 'approved'
             SET w.`source` = 'factory'"
        );
    }

    public function down(): void
    {
        $db = DB::connection($this->conn);

        if ($this->hasIndex($db, $this->table, 'rmwe_source_idx')) {
            $db->statement("ALTER TABLE `{$this->table}` DROP INDEX `rmwe_source_idx`");
        }
        if ($this->hasColumn($db, $this->table, 'source')) {
            $db->statement("ALTER TABLE `{$this->table}` DROP COLUMN `source`");
        }
        if ($this->hasIndex($db, 'return_approval', 'ra_barcode_idx')) {
            $db->statement('ALTER TABLE `return_approval` DROP INDEX `ra_barcode_idx`');
        }
    }

    private function hasColumn($db, string $table, string $column): bool
    {
        return (int) ($db->selectOne(
            'SELECT COUNT(*) c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )->c ?? 0) > 0;
    }

    private function hasIndex($db, string $table, string $index): bool
    {
        return (int) ($db->selectOne(
            'SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        )->c ?? 0) > 0;
    }
};
