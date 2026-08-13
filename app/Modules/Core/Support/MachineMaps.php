<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the `machine_map_*` lookup tables from the machines hierarchy.
 *
 * The legacy app writes NAMES; BEFORE INSERT/UPDATE triggers on the BIL tables
 * resolve them to core ids through these maps. The maps were built once by the
 * split migration and never refreshed — so a line, project or staff member added
 * in gds afterwards was unknown to the trigger, and every legacy row naming them
 * landed with a NULL id. Silently, because the trigger overwrites whatever id
 * the writer supplied: gds could set `staff_id` correctly and have the trigger
 * throw it away.
 *
 * So the maps have to track the hierarchy. They are refreshed automatically when
 * a Factory, MachineLine, MachineProject, Division or Staff is saved or deleted
 * (see Core\Providers\MachineMapsServiceProvider), and can be rebuilt by hand
 * with `gds:rebuild-machine-maps` — which is also the "re-run the map build"
 * step after a production data refresh.
 *
 * REBUILT VIA AN ATOMIC SWAP, not DROP + CREATE. The legacy app inserts into
 * these tables' consumers continuously, and a trigger whose subselect hits a
 * table that momentarily does not exist fails the whole insert. `RENAME TABLE`
 * is atomic, so no writer ever sees a missing map.
 *
 * Both the canonical name and the legacy alias resolve, so a record created from
 * the gds UI (which has no legacy name) works straight away.
 */
class MachineMaps
{
    /** kind => the columns its map is keyed on. */
    public const KINDS = ['line', 'project', 'subproject', 'factory', 'division', 'staff'];

    /** The `core` schema, whatever it is called in this environment. */
    protected static function core(): string
    {
        return DB::connection('core')->getDatabaseName();
    }

    protected static function bil()
    {
        return DB::connection('bil');
    }

    public static function table(string $kind): string
    {
        return 'machine_map_' . $kind;
    }

    /**
     * Rebuild one map, or all of them. Returns rows written per kind.
     *
     * @param  string|array|null  $kinds  null = all
     */
    public static function rebuild(string|array|null $kinds = null): array
    {
        $kinds = $kinds === null
            ? self::KINDS
            : array_values(array_intersect(self::KINDS, (array) $kinds));

        $written = [];

        foreach ($kinds as $kind) {
            $written[$kind] = self::rebuildOne($kind);
        }

        return $written;
    }

    /** Which maps a model's changes affect. */
    public static function kindsFor(string $model): array
    {
        return match ($model) {
            \Modules\Core\Models\MachineLine::class => ['line'],
            // One table feeds both maps, split on parent_id.
            \Modules\Core\Models\MachineProject::class => ['project', 'subproject'],
            \Modules\Core\Models\Factory::class => ['factory'],
            // Staff rows carry their division's name, so a renamed division
            // invalidates the staff map too.
            \Modules\Core\Models\Division::class => ['division', 'staff'],
            \Modules\Core\Models\Staff::class => ['staff'],
            default => [],
        };
    }

    /* ---------------- Building ---------------- */

    protected static function rebuildOne(string $kind): int
    {
        $bil = self::bil();
        $live = self::table($kind);
        $next = $live . '_next';
        $old = $live . '_old';

        $bil->statement("DROP TABLE IF EXISTS `{$next}`");
        $bil->statement(self::createSql($kind, $next));

        foreach (self::fillSql($kind, $next) as $sql) {
            $bil->statement($sql);
        }

        $count = (int) $bil->table($next)->count();

        // Atomic: no writer ever observes a missing map.
        $bil->statement("DROP TABLE IF EXISTS `{$old}`");
        $bil->statement("RENAME TABLE `{$live}` TO `{$old}`, `{$next}` TO `{$live}`");
        $bil->statement("DROP TABLE IF EXISTS `{$old}`");

        return $count;
    }

    /**
     * The map's shape. `staff` is keyed on two columns because a name is only
     * unique within a division; everything else is keyed on the name alone.
     *
     * utf8mb4_general_ci throughout — the legacy columns are a mix of charsets,
     * and a case-sensitive map would fail to resolve "Aluminium Foil" against
     * "ALUMINIUM FOIL".
     */
    protected static function createSql(string $kind, string $table): string
    {
        if ($kind === 'staff') {
            return "
                CREATE TABLE `{$table}` (
                    `division_nm` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                    `staff_nm` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                    `target_id` INT NOT NULL,
                    PRIMARY KEY (`division_nm`, `staff_nm`)
                ) ENGINE=InnoDB
            ";
        }

        return "
            CREATE TABLE `{$table}` (
                `nm` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                `target_id` INT NOT NULL,
                PRIMARY KEY (`nm`)
            ) ENGINE=InnoDB
        ";
    }

    /**
     * Reproduces the split migration's lookups exactly, but against the
     * CONFIGURED core schema rather than a hardcoded `core.` — the database is
     * named by env and is not always "core".
     *
     * INSERT IGNORE order matters: the canonical name goes in first where both
     * forms exist, so a collision keeps the canonical target.
     */
    protected static function fillSql(string $kind, string $t): array
    {
        $core = self::core();

        return match ($kind) {
            'line' => [
                "INSERT IGNORE INTO `{$t}` (nm, target_id)
                 SELECT CONVERT(name USING utf8mb4), id FROM `{$core}`.machine_lines WHERE deleted_at IS NULL",
                "INSERT IGNORE INTO `{$t}` (nm, target_id)
                 SELECT CONVERT(legacy_alias USING utf8mb4), id FROM `{$core}`.machine_lines
                 WHERE legacy_alias IS NOT NULL AND deleted_at IS NULL",
            ],

            // Projects and sub-projects share one table, split on parent_id,
            // because the consumer columns are separate.
            'project' => [
                "INSERT IGNORE INTO `{$t}` (nm, target_id)
                 SELECT CONVERT(name USING utf8mb4), id FROM `{$core}`.machine_projects
                 WHERE parent_id IS NULL AND deleted_at IS NULL",
            ],
            'subproject' => [
                "INSERT IGNORE INTO `{$t}` (nm, target_id)
                 SELECT CONVERT(name USING utf8mb4), id FROM `{$core}`.machine_projects
                 WHERE parent_id IS NOT NULL AND deleted_at IS NULL",
            ],

            'factory' => [
                "INSERT IGNORE INTO `{$t}` (nm, target_id)
                 SELECT CONVERT(code USING utf8mb4), id FROM `{$core}`.factories WHERE deleted_at IS NULL",
            ],

            'division' => [
                "INSERT IGNORE INTO `{$t}` (nm, target_id)
                 SELECT CONVERT(legacy_name USING utf8mb4), id FROM `{$core}`.divisions
                 WHERE legacy_name IS NOT NULL AND deleted_at IS NULL",
                "INSERT IGNORE INTO `{$t}` (nm, target_id)
                 SELECT CONVERT(name USING utf8mb4), id FROM `{$core}`.divisions WHERE deleted_at IS NULL",
            ],

            // Under the legacy division string AND the canonical one, so a job
            // recorded either way resolves to the same person.
            'staff' => [
                "INSERT IGNORE INTO `{$t}` (division_nm, staff_nm, target_id)
                 SELECT CONVERT(COALESCE(v.legacy_name, v.name, '') USING utf8mb4),
                        CONVERT(s.name USING utf8mb4), s.id
                 FROM `{$core}`.staff s
                 LEFT JOIN `{$core}`.divisions v ON v.id = s.division_id
                 WHERE s.deleted_at IS NULL",
                "INSERT IGNORE INTO `{$t}` (division_nm, staff_nm, target_id)
                 SELECT CONVERT(v.name USING utf8mb4), CONVERT(s.name USING utf8mb4), s.id
                 FROM `{$core}`.staff s
                 JOIN `{$core}`.divisions v ON v.id = s.division_id
                 WHERE s.deleted_at IS NULL",
            ],

            default => [],
        };
    }

    /* ---------------- Strictness ---------------- */

    /**
     * Which of a row's id columns the trigger left NULL.
     *
     * gds sets these ids itself, but the trigger RECOMPUTES them from the name
     * columns and wins — so the only way to know a write was attributed is to
     * read it back. Callers use this to refuse a save rather than record a row
     * that belongs to nobody.
     *
     * @param  array<string,string>  $columns  id column => the name it came from
     * @return array<string,string>  the unresolved ones
     */
    public static function unresolved(string $table, int $id, array $columns): array
    {
        $row = self::bil()->table($table)->where('id', $id)->first();

        if (! $row) {
            return [];
        }

        $bad = [];

        foreach ($columns as $idColumn => $nameColumn) {
            $name = trim((string) ($row->{$nameColumn} ?? ''));

            // A blank name legitimately resolves to nothing.
            if ($name !== '' && ($row->{$idColumn} ?? null) === null) {
                $bad[$idColumn] = $name;
            }
        }

        return $bad;
    }
}
