<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the delivery-staging table `rawmaterials_copy` →
 * `rawmaterials_supplier_deliveries`, and leave a compatibility VIEW named
 * `rawmaterials_copy` over it.
 *
 * The still-live legacy app reads AND inserts `rawmaterials_copy` (barcode
 * generator, store entrance, factory entrance, inter-transfer). Because the
 * view is a plain `SELECT *` over a single base table it stays insertable and
 * updatable, so legacy code keeps working unchanged while the modern app uses
 * the nicer table name. Reversible via down().
 */
return new class extends Migration
{
    protected string $conn = 'bil';

    public function up(): void
    {
        $db = DB::connection($this->conn);
        $db->statement('RENAME TABLE `rawmaterials_copy` TO `rawmaterials_supplier_deliveries`');
        $db->statement('CREATE OR REPLACE VIEW `rawmaterials_copy` AS SELECT * FROM `rawmaterials_supplier_deliveries`');
    }

    public function down(): void
    {
        $db = DB::connection($this->conn);
        $db->statement('DROP VIEW IF EXISTS `rawmaterials_copy`');
        $db->statement('RENAME TABLE `rawmaterials_supplier_deliveries` TO `rawmaterials_copy`');
    }
};
