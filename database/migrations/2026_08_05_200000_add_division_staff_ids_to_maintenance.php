<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give factory_machine_maintenance real foreign keys for the division and the
 * staff member, beside the name columns it already stores.
 *
 * Staff resolves on **(division, name)**, not name alone. "OTHERS" is a
 * per-division placeholder that exists four times, so a name-only join returns
 * 43,709 rows for a 43,401-row table — it fans out and would attach an
 * arbitrary division's OTHERS to every such record.
 *
 * Division resolves through divisions.legacy_name, which holds the full old
 * string ("MAINTENANCE MECHANICAL") these rows actually contain.
 *
 * The existing *_ins_ids / *_upd_ids triggers are recreated rather than added
 * to, so all five ids are set in one pass for the still-live legacy app.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bil = DB::connection('bil');

        $this->buildLookups($bil);

        foreach (['division_id', 'staff_id'] as $column) {
            if ($this->hasColumn($bil, 'factory_machine_maintenance', $column)) {
                continue;
            }
            $bil->statement("ALTER TABLE `factory_machine_maintenance` ADD COLUMN `{$column}` INT NULL");
            $bil->statement("ALTER TABLE `factory_machine_maintenance` ADD INDEX `fmm_{$column}_idx` (`{$column}`)");
        }

        $bil->statement('
            UPDATE `factory_machine_maintenance` t
            JOIN `machine_map_division` m ON m.nm = t.`division`
            SET t.`division_id` = m.target_id
        ');

        $bil->statement('
            UPDATE `factory_machine_maintenance` t
            JOIN `machine_map_staff` m ON m.division_nm = t.`division` AND m.staff_nm = t.`staff`
            SET t.`staff_id` = m.target_id
        ');

        $this->createTriggers($bil);
    }

    private function buildLookups($bil): void
    {
        $bil->statement('DROP TABLE IF EXISTS `machine_map_division`');
        $bil->statement('
            CREATE TABLE `machine_map_division` (
                `nm` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                `target_id` INT NOT NULL,
                PRIMARY KEY (`nm`)
            ) ENGINE=InnoDB
        ');
        // Both the legacy string and the canonical name resolve, so a division
        // created from the UI (which has no legacy_name) still works.
        $bil->statement('
            INSERT IGNORE INTO `machine_map_division` (nm, target_id)
            SELECT CONVERT(legacy_name USING utf8mb4), id FROM core.divisions
            WHERE legacy_name IS NOT NULL AND deleted_at IS NULL
        ');
        $bil->statement('
            INSERT IGNORE INTO `machine_map_division` (nm, target_id)
            SELECT CONVERT(name USING utf8mb4), id FROM core.divisions WHERE deleted_at IS NULL
        ');

        $bil->statement('DROP TABLE IF EXISTS `machine_map_staff`');
        $bil->statement('
            CREATE TABLE `machine_map_staff` (
                `division_nm` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                `staff_nm` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                `target_id` INT NOT NULL,
                PRIMARY KEY (`division_nm`, `staff_nm`)
            ) ENGINE=InnoDB
        ');
        $bil->statement("
            INSERT IGNORE INTO `machine_map_staff` (division_nm, staff_nm, target_id)
            SELECT CONVERT(COALESCE(v.legacy_name, v.name, '') USING utf8mb4),
                   CONVERT(s.name USING utf8mb4), s.id
            FROM core.staff s
            LEFT JOIN core.divisions v ON v.id = s.division_id
            WHERE s.deleted_at IS NULL
        ");
        $bil->statement('
            INSERT IGNORE INTO `machine_map_staff` (division_nm, staff_nm, target_id)
            SELECT CONVERT(v.name USING utf8mb4), CONVERT(s.name USING utf8mb4), s.id
            FROM core.staff s
            JOIN core.divisions v ON v.id = s.division_id
            WHERE s.deleted_at IS NULL
        ');
    }

    private function createTriggers($bil): void
    {
        foreach (['ins' => 'INSERT', 'upd' => 'UPDATE'] as $suffix => $event) {
            $name = "factory_machine_maintenance_{$suffix}_ids";
            $bil->unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            $bil->unprepared("
                CREATE TRIGGER `{$name}` BEFORE {$event} ON `factory_machine_maintenance`
                FOR EACH ROW
                BEGIN
                    SET NEW.`line_id` = (SELECT m.target_id FROM `machine_map_line` m WHERE m.nm = NEW.`linename` LIMIT 1);
                    SET NEW.`project_id` = (SELECT m.target_id FROM `machine_map_project` m WHERE m.nm = NEW.`project` LIMIT 1);
                    SET NEW.`subproject_id` = (SELECT m.target_id FROM `machine_map_subproject` m WHERE m.nm = NEW.`subproject` LIMIT 1);
                    SET NEW.`division_id` = (SELECT m.target_id FROM `machine_map_division` m WHERE m.nm = NEW.`division` LIMIT 1);
                    SET NEW.`staff_id` = (SELECT m.target_id FROM `machine_map_staff` m WHERE m.division_nm = NEW.`division` AND m.staff_nm = NEW.`staff` LIMIT 1);
                END
            ");
        }
    }

    private function hasColumn($bil, string $table, string $column): bool
    {
        return (bool) $bil->selectOne(
            'SELECT 1 AS x FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
    }

    public function down(): void
    {
        $bil = DB::connection('bil');

        // Restore the three-id version of the triggers.
        foreach (['ins' => 'INSERT', 'upd' => 'UPDATE'] as $suffix => $event) {
            $name = "factory_machine_maintenance_{$suffix}_ids";
            $bil->unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            $bil->unprepared("
                CREATE TRIGGER `{$name}` BEFORE {$event} ON `factory_machine_maintenance`
                FOR EACH ROW
                BEGIN
                    SET NEW.`line_id` = (SELECT m.target_id FROM `machine_map_line` m WHERE m.nm = NEW.`linename` LIMIT 1);
                    SET NEW.`project_id` = (SELECT m.target_id FROM `machine_map_project` m WHERE m.nm = NEW.`project` LIMIT 1);
                    SET NEW.`subproject_id` = (SELECT m.target_id FROM `machine_map_subproject` m WHERE m.nm = NEW.`subproject` LIMIT 1);
                END
            ");
        }

        foreach (['division_id', 'staff_id'] as $column) {
            $bil->statement("ALTER TABLE `factory_machine_maintenance` DROP COLUMN `{$column}`");
        }

        $bil->statement('DROP TABLE IF EXISTS `machine_map_division`');
        $bil->statement('DROP TABLE IF EXISTS `machine_map_staff`');
    }
};
