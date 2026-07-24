<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the live warehouse-stock table `rawmaterials` →
 * `rawmaterials_warehouse_entry`, leaving a compatibility VIEW named
 * `rawmaterials` over it.
 *
 * `rawmaterials` is read/written by many still-live legacy pages (store
 * exit, consumption, factory entrance, transfer, stock reports…). Because
 * the view is a plain `SELECT *` over a single base table it stays
 * insertable/updatable, so legacy code keeps working unchanged while the
 * modern app uses the clearer name. Reversible via down().
 */
return new class extends Migration
{
    protected string $conn = 'bil';

    public function up(): void
    {
        $db = DB::connection($this->conn);
        $db->statement('RENAME TABLE `rawmaterials` TO `rawmaterials_warehouse_entry`');
        $db->statement('CREATE OR REPLACE VIEW `rawmaterials` AS SELECT * FROM `rawmaterials_warehouse_entry`');
    }

    public function down(): void
    {
        $db = DB::connection($this->conn);
        $db->statement('DROP VIEW IF EXISTS `rawmaterials`');
        $db->statement('RENAME TABLE `rawmaterials_warehouse_entry` TO `rawmaterials`');
    }
};
