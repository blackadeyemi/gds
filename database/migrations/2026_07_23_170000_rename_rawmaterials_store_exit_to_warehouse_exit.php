<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename `rawmaterials_store_exit` → `rawmaterials_warehouse_exit`, leaving a
 * compatibility VIEW named `rawmaterials_store_exit` over it. The legacy app
 * reads/writes `rawmaterials_store_exit` (store exit, the exited-check in
 * store entrance, exit reports); the plain `SELECT *` view stays
 * insertable/updatable so that keeps working. Reversible via down().
 */
return new class extends Migration
{
    protected string $conn = 'bil';

    public function up(): void
    {
        $db = DB::connection($this->conn);
        $db->statement('RENAME TABLE `rawmaterials_store_exit` TO `rawmaterials_warehouse_exit`');
        $db->statement('CREATE OR REPLACE VIEW `rawmaterials_store_exit` AS SELECT * FROM `rawmaterials_warehouse_exit`');
    }

    public function down(): void
    {
        $db = DB::connection($this->conn);
        $db->statement('DROP VIEW IF EXISTS `rawmaterials_store_exit`');
        $db->statement('RENAME TABLE `rawmaterials_warehouse_exit` TO `rawmaterials_store_exit`');
    }
};
