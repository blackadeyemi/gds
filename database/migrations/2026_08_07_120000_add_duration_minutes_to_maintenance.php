<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Materialises a service job's stop time as minutes.
 *
 * `duration` is the legacy {"d":_,"h":_,"m":_} JSON, so every total means three
 * JSON_EXTRACTs per row. That is affordable for one report page, but the
 * statistics dashboard aggregates the whole table several times per section and
 * it dominated: ~500ms per grouped query, ~10s for the all-time Downtime tab.
 * Reading a plain INT instead takes it to ~30ms.
 *
 * Kept as a normal column set by the existing BEFORE INSERT/UPDATE triggers
 * rather than a STORED GENERATED column, deliberately: a generated column would
 * make MySQL reject any INSERT whose duration isn't valid JSON, and the legacy
 * app still writes this table. A trigger degrades to NULL instead of failing
 * the write.
 *
 * `duration` stays authoritative — this is a cache of it, and down() drops it.
 *
 * It also swaps the five single-column id indexes for covering ones carrying
 * `date` and the new column, so the dashboard's GROUP BYs are answered from the
 * index without touching a row: ~170ms -> ~20ms each. The composites lead with
 * the same column, so every lookup the old indexes served is still served.
 */
return new class extends Migration
{
    /**
     * The id columns the dashboard groups by, mapped to the single-column index
     * each one had before (restored by down()).
     */
    private const GROUPED = [
        'line_id' => 'factory_machine_maintenance_line_id_idx',
        'project_id' => 'factory_machine_maintenance_project_id_idx',
        'subproject_id' => 'factory_machine_maintenance_subproject_id_idx',
        'division_id' => 'fmm_division_id_idx',
        'staff_id' => 'fmm_staff_id_idx',
    ];

    /** Minutes from the duration JSON, as an expression over a row alias. */
    private function minutesSql(string $ref): string
    {
        return "COALESCE(JSON_EXTRACT($ref, '$.d'), 0) * 1440
              + COALESCE(JSON_EXTRACT($ref, '$.h'), 0) * 60
              + COALESCE(JSON_EXTRACT($ref, '$.m'), 0)";
    }

    public function up(): void
    {
        $db = DB::connection('bil');

        $exists = $db->selectOne(
            "SELECT 1 ok FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factory_machine_maintenance'
               AND COLUMN_NAME = 'duration_minutes'"
        );

        if (! $exists) {
            $db->statement('ALTER TABLE `factory_machine_maintenance` ADD COLUMN `duration_minutes` INT NULL AFTER `duration`');
        }

        // Backfill. JSON_VALID guards the handful of rows that could hold
        // anything else; they stay NULL and read as zero.
        $db->statement('UPDATE `factory_machine_maintenance`
            SET `duration_minutes` = CASE WHEN JSON_VALID(`duration`) THEN ' . $this->minutesSql('`duration`') . ' ELSE NULL END');

        $this->syncTriggers(true);

        // Covering indexes: (id, date, minutes) answers "group jobs or stop time
        // by X over a date range" from the index alone.
        foreach (self::GROUPED as $col => $old) {
            $this->dropIndex($old);
            $this->addIndex("fmm_stats_$col", "`$col`, `date`, `duration_minutes`");
        }
    }

    public function down(): void
    {
        $this->syncTriggers(false);

        foreach (self::GROUPED as $col => $old) {
            $this->dropIndex("fmm_stats_$col");
            $this->addIndex($old, "`$col`");
        }

        DB::connection('bil')->statement('ALTER TABLE `factory_machine_maintenance` DROP COLUMN `duration_minutes`');
    }

    private function dropIndex(string $name): void
    {
        $db = DB::connection('bil');

        $exists = $db->selectOne(
            "SELECT 1 ok FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factory_machine_maintenance' AND INDEX_NAME = ?",
            [$name]
        );

        if ($exists) {
            $db->statement("ALTER TABLE `factory_machine_maintenance` DROP INDEX `$name`");
        }
    }

    private function addIndex(string $name, string $cols): void
    {
        $db = DB::connection('bil');

        $exists = $db->selectOne(
            "SELECT 1 ok FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factory_machine_maintenance' AND INDEX_NAME = ?",
            [$name]
        );

        if (! $exists) {
            $db->statement("ALTER TABLE `factory_machine_maintenance` ADD INDEX `$name` ($cols)");
        }
    }

    /**
     * Recreates both id-resolving triggers, with or without the duration line.
     * They have to be rewritten whole — MySQL has no "add a statement to an
     * existing trigger" — so the id resolution is repeated here verbatim from
     * 2026_08_05_200000. Change it in both places or the ids stop resolving.
     */
    private function syncTriggers(bool $withDuration): void
    {
        $db = DB::connection('bil');

        $ids = "
            SET NEW.`line_id` = (SELECT m.target_id FROM `machine_map_line` m WHERE m.nm = NEW.`linename` LIMIT 1);
            SET NEW.`project_id` = (SELECT m.target_id FROM `machine_map_project` m WHERE m.nm = NEW.`project` LIMIT 1);
            SET NEW.`subproject_id` = (SELECT m.target_id FROM `machine_map_subproject` m WHERE m.nm = NEW.`subproject` LIMIT 1);
            SET NEW.`division_id` = (SELECT m.target_id FROM `machine_map_division` m WHERE m.nm = NEW.`division` LIMIT 1);
            SET NEW.`staff_id` = (SELECT m.target_id FROM `machine_map_staff` m WHERE m.division_nm = NEW.`division` AND m.staff_nm = NEW.`staff` LIMIT 1);";

        if ($withDuration) {
            $ids .= "
            SET NEW.`duration_minutes` = CASE WHEN JSON_VALID(NEW.`duration`)
                THEN " . $this->minutesSql('NEW.`duration`') . ' ELSE NULL END;';
        }

        foreach (['ins' => 'INSERT', 'upd' => 'UPDATE'] as $suffix => $event) {
            $name = "factory_machine_maintenance_{$suffix}_ids";
            $db->unprepared("DROP TRIGGER IF EXISTS `$name`");
            $db->unprepared("CREATE TRIGGER `$name` BEFORE $event ON `factory_machine_maintenance`
                FOR EACH ROW BEGIN $ids END");
        }
    }
};
