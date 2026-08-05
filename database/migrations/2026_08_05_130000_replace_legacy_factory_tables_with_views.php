<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\LegacyFactoryViews;

/**
 * Swap the five legacy factory tables for views over core.machine_lines /
 * machine_projects / factories, so the still-live legacy PHP app keeps reading
 * exactly what it read before while core owns the data.
 *
 * The view bodies live in LegacyFactoryViews (including the note on why the
 * name columns are NOT collation-cast).
 *
 * NOTE: views are not insertable, so the legacy screens that WROTE these tables
 * are retired alongside this migration — lineitem.php, sublineitems.php,
 * projects.php and subprojects.php become "moved to GDS" stubs, and the save
 * and delete actions on Bpl\Machinecontroller are unrouted. They are exactly
 * the screens the new BIL > Machines UI replaces. Legacy READS are unaffected,
 * so read-only pages such as factory_machines.php keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The legacy tables were copied into core by the previous migration; the
        // pre-migration snapshot in bil/_migration/machines_baseline/ is the
        // restore path if this ever needs undoing.
        LegacyFactoryViews::apply();
    }

    public function down(): void
    {
        $bil = DB::connection('bil');

        // Materialise the views back into real tables so the legacy write
        // screens could function again if this is rolled back.
        $ddl = [
            'factory_lines' => "CREATE TABLE `factory_lines` (
                `id` int NOT NULL AUTO_INCREMENT,
                `factoryname` varchar(20) DEFAULT NULL,
                `linename` varchar(255) NOT NULL,
                `linecode` varchar(5) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
            'factory_sublines' => "CREATE TABLE `factory_sublines` (
                `id` int NOT NULL AUTO_INCREMENT,
                `lineid` int NOT NULL,
                `linename` varchar(255) NOT NULL,
                `sublinename` varchar(255) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
            'factory_projects' => "CREATE TABLE `factory_projects` (
                `id` int NOT NULL AUTO_INCREMENT,
                `lineid` int NOT NULL,
                `linename` varchar(255) NOT NULL,
                `sublinename` varchar(255) NOT NULL,
                `project` varchar(255) NOT NULL,
                `code` varchar(100) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
            'factory_subprojects' => "CREATE TABLE `factory_subprojects` (
                `id` int NOT NULL AUTO_INCREMENT,
                `lineid` int NOT NULL,
                `linename` varchar(255) NOT NULL,
                `sublinename` varchar(255) NOT NULL,
                `project` varchar(255) NOT NULL,
                `projectcode` varchar(100) NOT NULL,
                `subproject` varchar(255) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
            'factory_details' => "CREATE TABLE `factory_details` (
                `id` int NOT NULL AUTO_INCREMENT,
                `location` varchar(255) NOT NULL,
                `linename` varchar(255) NOT NULL,
                `sublinename` varchar(25) DEFAULT NULL,
                `linecode` varchar(255) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ];

        foreach (LegacyFactoryViews::definitions() as $name => $select) {
            $rows = $bil->select($select);
            $bil->statement("DROP VIEW IF EXISTS `{$name}`");
            $bil->statement($ddl[$name]);
            foreach (array_chunk($rows, 200) as $chunk) {
                $bil->table($name)->insert(array_map(fn ($r) => (array) $r, $chunk));
            }
        }

        $bpl = DB::connection('bpl');
        foreach (array_keys($ddl) as $name) {
            $bpl->statement("CREATE OR REPLACE VIEW `{$name}` AS SELECT * FROM `bil`.`{$name}`");
        }
    }
};
