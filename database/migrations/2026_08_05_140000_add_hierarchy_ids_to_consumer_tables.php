<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the tables that record what happened on a machine a real foreign key to
 * it, instead of a copy of its name.
 *
 * Today every one of these stores `linename` / `project` / `factory` as text, so
 * renaming a machine silently orphans its history — which is the whole reason
 * for this work. The name columns STAY (the legacy PHP app is still live and
 * still writes them), and the ids are added beside them and backfilled.
 *
 * Because legacy keeps inserting names only, each table also gets BEFORE
 * INSERT/UPDATE triggers that resolve name -> id at write time. That keeps the
 * ids correct without touching a line of legacy code. Drop the triggers once
 * the legacy app is retired and everything writes ids directly.
 *
 * Name resolution goes through `legacy_alias` as well as `name`, which is how
 * the factory_details spellings (FACIAL, Handkerchief, Aluminium Foil) still
 * find their node. Note `linename` is genuinely ambiguous in the legacy data —
 * it holds a line name in ~80k usage rows and a sub-line name in ~20k — but
 * both live in machine_lines, so one lookup covers both.
 */
return new class extends Migration
{
    /**
     * table => [new id column => [lookup kind, legacy text column it reads]].
     *
     * The text column is named per table, not per kind: factory_production calls
     * it `factory` while factory_waste calls it `factoryname`.
     */
    private array $targets = [
        'factory_usage_rawmaterials' => [
            'line_id' => ['line', 'linename'],
            'project_id' => ['project', 'project'],
        ],
        'factory_usage_reel' => [
            'line_id' => ['line', 'linename'],
        ],
        'factory_production' => [
            'factory_id' => ['factory', 'factory'],
            'line_id' => ['line', 'linename'],
        ],
        'factory_machine_maintenance' => [
            'line_id' => ['line', 'linename'],
            'project_id' => ['project', 'project'],
            'subproject_id' => ['subproject', 'subproject'],
        ],
        'factory_preproduction' => [
            'line_id' => ['line', 'linename'],
        ],
        'factory_waste' => [
            'factory_id' => ['factory', 'factoryname'],
            'line_id' => ['line', 'linename'],
            'project_id' => ['project', 'project'],
        ],
    ];

    public function up(): void
    {
        $bil = DB::connection('bil');

        $this->buildLookups($bil);

        foreach ($this->targets as $table => $columns) {
            foreach ($columns as $column => [$kind, $source]) {
                // Adding a column to factory_production means rewriting 1.2M
                // rows, so this skips work already done — the migration can be
                // resumed rather than restarted if it fails part-way.
                if ($this->hasColumn($bil, $table, $column)) {
                    continue;
                }

                $bil->statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` INT NULL");
                $bil->statement("ALTER TABLE `{$table}` ADD INDEX `{$table}_{$column}_idx` (`{$column}`)");

                // Backfill through the lookup table: it lives in bil in the
                // consumer's own charset, so this is an indexed join rather than
                // a per-row cross-charset scan of core.
                $map = $this->mapTable($kind);
                $bil->statement("
                    UPDATE `{$table}` t
                    JOIN `{$map}` m ON m.nm = t.`{$source}`
                    SET t.`{$column}` = m.target_id
                ");
            }

            $this->createTriggers($bil, $table, $columns);
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

    /**
     * Name -> id lookups, materialised in bil so the backfill joins stay indexed
     * and charset-clean. Dropped at the end of the migration.
     */
    private function buildLookups($bil): void
    {
        foreach (['line', 'project', 'subproject', 'factory'] as $kind) {
            $map = $this->mapTable($kind);
            $bil->statement("DROP TABLE IF EXISTS `{$map}`");
            $bil->statement("
                CREATE TABLE `{$map}` (
                    `nm` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                    `target_id` INT NOT NULL,
                    PRIMARY KEY (`nm`)
                ) ENGINE=InnoDB
            ");
        }

        // Lines and sub-lines share machine_lines, so one map serves both.
        $bil->statement("
            INSERT IGNORE INTO `" . $this->mapTable('line') . "` (nm, target_id)
            SELECT CONVERT(name USING utf8mb4), id FROM core.machine_lines WHERE deleted_at IS NULL
        ");
        $bil->statement("
            INSERT IGNORE INTO `" . $this->mapTable('line') . "` (nm, target_id)
            SELECT CONVERT(legacy_alias USING utf8mb4), id FROM core.machine_lines
            WHERE legacy_alias IS NOT NULL AND deleted_at IS NULL
        ");

        // Projects and sub-projects likewise share machine_projects, but the
        // consumer columns are separate so they get separate maps.
        $bil->statement("
            INSERT IGNORE INTO `" . $this->mapTable('project') . "` (nm, target_id)
            SELECT CONVERT(name USING utf8mb4), id FROM core.machine_projects
            WHERE parent_id IS NULL AND deleted_at IS NULL
        ");
        $bil->statement("
            INSERT IGNORE INTO `" . $this->mapTable('subproject') . "` (nm, target_id)
            SELECT CONVERT(name USING utf8mb4), id FROM core.machine_projects
            WHERE parent_id IS NOT NULL AND deleted_at IS NULL
        ");

        $bil->statement("
            INSERT IGNORE INTO `" . $this->mapTable('factory') . "` (nm, target_id)
            SELECT CONVERT(code USING utf8mb4), id FROM core.factories WHERE deleted_at IS NULL
        ");
    }

    private function createTriggers($bil, string $table, array $columns): void
    {
        foreach (['ins' => 'INSERT', 'upd' => 'UPDATE'] as $suffix => $event) {
            $name = "{$table}_{$suffix}_ids";
            $body = [];

            foreach ($columns as $column => [$kind, $source]) {
                $map = $this->mapTable($kind);
                $body[] = "SET NEW.`{$column}` = ("
                    . "SELECT m.target_id FROM `{$map}` m "
                    . "WHERE m.nm = NEW.`{$source}` LIMIT 1);";
            }

            $bil->unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            $bil->unprepared("
                CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}`
                FOR EACH ROW
                BEGIN
                    " . implode("\n                    ", $body) . "
                END
            ");
        }
    }

    private function mapTable(string $kind): string
    {
        return "machine_map_{$kind}";
    }

    public function down(): void
    {
        $bil = DB::connection('bil');

        foreach ($this->targets as $table => $columns) {
            foreach (['ins', 'upd'] as $suffix) {
                $bil->unprepared("DROP TRIGGER IF EXISTS `{$table}_{$suffix}_ids`");
            }
            foreach (array_keys($columns) as $column) {
                $bil->statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            }
        }

        foreach (['line', 'project', 'subproject', 'factory'] as $kind) {
            $bil->statement("DROP TABLE IF EXISTS `" . $this->mapTable($kind) . "`");
        }
    }
};
