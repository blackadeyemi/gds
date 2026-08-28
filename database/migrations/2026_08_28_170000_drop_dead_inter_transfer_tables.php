<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops three tables in `core` that nothing reaches any more, and the
 * compatibility views over them in `bil` and `bpl`.
 *
 * WHAT AND WHY
 *
 *   local_governments             774 rows. Referenced by NO php, js or yaml in
 *                                 either codebase — the whole legacy app and the
 *                                 whole of gds were searched. Superseded by
 *                                 geo_states / geo_cities.
 *
 *   rawmaterial_inter_transfer     28 rows, last written 2023-10-18
 *   rawmaterials_inter_received    11 rows, last written 2023-10-18
 *                                 The raw-material inter-company transfer
 *                                 screens no longer exist: no PHP page loads
 *                                 js/bpl/rm_inter_transfer/form.js or
 *                                 rm_inter_received/form.js, and neither appears
 *                                 in main_nav.php. Only the API routes were
 *                                 still registered, pointing at a feature with
 *                                 no way in; those go with this change.
 *
 * WHAT DELIBERATELY STAYS
 *
 * `transfer_company_from` / `transfer_company_to` look equally dead (1 and 2
 * rows) but are NOT: report_fg_inter_transfer.php reads both directly and is in
 * the nav, and the live `fg_transfer/getcompany` endpoint reads to_company for
 * the finished-goods transfer and receive screens. Also staying:
 * `fg_inter_transfer` / `fg_inter_received`, whose screens are still live even
 * though gds has replaced them with `stock_transfers`.
 *
 * The rows are in the backup taken with this change —
 * db_backups/pre-drop-dead-core-tables_*.sql, dumped with
 * `--default-character-set=binary`. `down()` rebuilds the tables and the views
 * but CANNOT bring the rows back; restore them from that file if ever needed.
 */
return new class extends Migration
{
    private const TABLES = ['local_governments', 'rawmaterial_inter_transfer', 'rawmaterials_inter_received'];

    /** Original DDL, so down() rebuilds them exactly as they were. */
    private const DDL = [
        'local_governments' => "CREATE TABLE `local_governments` (
            `id` int NOT NULL AUTO_INCREMENT,
            `state_id` int NOT NULL,
            `name` varchar(255) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `state_id` (`state_id`),
            CONSTRAINT `FK` FOREIGN KEY (`state_id`) REFERENCES `states` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf32 COMMENT='Local governments in Nigeria.'",

        'rawmaterial_inter_transfer' => "CREATE TABLE `rawmaterial_inter_transfer` (
            `id` int NOT NULL AUTO_INCREMENT,
            `username` varchar(50) NOT NULL,
            `barcode` varchar(20) NOT NULL,
            `dateoftransfer` date NOT NULL,
            `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `to_company` varchar(200) NOT NULL,
            `from_company` int NOT NULL,
            `productid` int NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=latin1",

        'rawmaterials_inter_received' => "CREATE TABLE `rawmaterials_inter_received` (
            `id` int NOT NULL AUTO_INCREMENT,
            `username` varchar(50) NOT NULL,
            `suppliercode` varchar(3) DEFAULT NULL,
            `productid` int NOT NULL,
            `barcode` varchar(20) NOT NULL,
            `weight` float NOT NULL,
            `dateofcreation` varchar(20) NOT NULL,
            `location` varchar(30) NOT NULL,
            `status` varchar(20) DEFAULT NULL,
            `timestamp` varchar(255) DEFAULT NULL,
            `sub_barcode` json DEFAULT NULL,
            `location_id` int DEFAULT NULL,
            `company_id` int NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `barcode_company_id_unique_ALTER` (`barcode`,`company_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=latin1",
    ];

    public function up(): void
    {
        $db = DB::connection('core');

        // Views first: they depend on the tables.
        foreach (['bil', 'bpl'] as $schema) {
            foreach (self::TABLES as $table) {
                $db->statement("DROP VIEW IF EXISTS `$schema`.`$table`");
            }
        }

        foreach (self::TABLES as $table) {
            $db->statement("DROP TABLE IF EXISTS `core`.`$table`");
        }
    }

    public function down(): void
    {
        $db = DB::connection('core');

        foreach (self::TABLES as $table) {
            if ($this->exists('core', $table)) {
                continue;
            }

            $db->statement(str_replace('CREATE TABLE `', 'CREATE TABLE `core`.`', self::DDL[$table]));
        }

        // The compatibility views the legacy app read them through.
        foreach (['bil', 'bpl'] as $schema) {
            foreach (self::TABLES as $table) {
                $db->statement("CREATE OR REPLACE VIEW `$schema`.`$table` AS SELECT * FROM `core`.`$table`");
            }
        }
    }

    private function exists(string $schema, string $table): bool
    {
        return (bool) DB::connection('core')->selectOne(
            'SELECT 1 ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$schema, $table]
        );
    }
};
