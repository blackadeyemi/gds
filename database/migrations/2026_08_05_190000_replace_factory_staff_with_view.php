<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\LegacyFactoryViews;

/**
 * Swap bil.factory_staff for a view over core.staff, and re-apply the other
 * five (LegacyFactoryViews::apply() installs the whole set).
 *
 * As with the line/project screens, a view cannot be written to, so the legacy
 * staff editor is retired in the same commit: factory_staff.php becomes a
 * "moved to GDS" stub, its nav item is removed, and the factory_save_staff /
 * delete_factory_save_staff routes are dropped from api.yaml. Staff is now
 * maintained in GDS under Admin > Staff.
 *
 * Legacy READS keep working untouched — factory_machines.php's division
 * dropdown, includes/form.inc.php's edit form, js/machine.js option fetches and
 * Machinecontroller::staffdivisions / allfactorystaff all read this view.
 */
return new class extends Migration
{
    public function up(): void
    {
        LegacyFactoryViews::apply();
    }

    public function down(): void
    {
        $bil = DB::connection('bil');

        $select = LegacyFactoryViews::definitions()['factory_staff'];
        $rows = $bil->select($select);

        $bil->statement('DROP VIEW IF EXISTS `factory_staff`');
        $bil->statement("CREATE TABLE `factory_staff` (
            `id` int NOT NULL AUTO_INCREMENT,
            `staff_id` int DEFAULT NULL,
            `name` varchar(100) NOT NULL,
            `department` varchar(100) NOT NULL,
            `division` varchar(100) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3");

        foreach (array_chunk($rows, 200) as $chunk) {
            $bil->table('factory_staff')->insert(array_map(fn ($r) => (array) $r, $chunk));
        }

        DB::connection('bpl')->statement(
            'CREATE OR REPLACE VIEW `factory_staff` AS SELECT * FROM `bil`.`factory_staff`'
        );
    }
};
