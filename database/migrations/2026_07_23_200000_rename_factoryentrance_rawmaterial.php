<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename `factoryentrance_rawmaterial` → `factory_entrance_rawmaterials`,
 * leaving a compatibility VIEW named `factoryentrance_rawmaterial` over it.
 * The legacy app reads/writes this table (factory entrance scan/save,
 * consumption, returns, reports); the plain `SELECT *` view stays
 * insertable/updatable so legacy keeps working. Reversible via down().
 */
return new class extends Migration
{
    protected string $conn = 'bil';

    public function up(): void
    {
        $db = DB::connection($this->conn);
        $db->statement('RENAME TABLE `factoryentrance_rawmaterial` TO `factory_entrance_rawmaterials`');
        $db->statement('CREATE OR REPLACE VIEW `factoryentrance_rawmaterial` AS SELECT * FROM `factory_entrance_rawmaterials`');
    }

    public function down(): void
    {
        $db = DB::connection($this->conn);
        $db->statement('DROP VIEW IF EXISTS `factoryentrance_rawmaterial`');
        $db->statement('RENAME TABLE `factory_entrance_rawmaterials` TO `factoryentrance_rawmaterial`');
    }
};
