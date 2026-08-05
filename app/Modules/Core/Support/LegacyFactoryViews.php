<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\DB;

/**
 * The five legacy `bil` tables that are now views over core.factories /
 * machine_lines / machine_projects, kept in one place because two migrations
 * install them and any future change to the legacy contract belongs here.
 *
 * COLLATION — deliberately NOT cast. An earlier version wrapped every name in
 * `CONVERT(... USING latin1) COLLATE latin1_swedish_ci` to match the columns
 * these views replace. That backfired: an explicit COLLATE gives the expression
 * coercibility 0, and MySQL refuses to coerce an EXPLICIT collation against
 * another charset, so `JOIN factory_usage_rawmaterials u ON u.linename =
 * l.linename` (utf8mb3) died with "Illegal mix of collations". The original
 * columns were plain latin1 columns — coercibility 2, IMPLICIT — which MySQL
 * happily converts up to the wider charset. Emitting the underlying utf8mb4
 * columns untouched restores exactly that behaviour: utf8mb4 is a superset of
 * both latin1 and utf8mb3, so joins from either side coerce and still work.
 */
class LegacyFactoryViews
{
    /** view name => SELECT body */
    public static function definitions(): array
    {
        return [
            // Root nodes only. Emits the canonical factory_lines spelling —
            // legacy_alias belongs to factory_details, not here.
            'factory_lines' => "
                SELECT
                    l.id AS id,
                    f.code AS factoryname,
                    l.name AS linename,
                    l.code AS linecode
                FROM core.machine_lines l
                LEFT JOIN core.factories f ON f.id = l.factory_id
                WHERE l.parent_id IS NULL AND l.deleted_at IS NULL
            ",

            // Child nodes only. linename is derived from the parent, which is
            // why the '13' garbage in the old cached column disappears.
            'factory_sublines' => "
                SELECT
                    s.id AS id,
                    s.parent_id AS lineid,
                    p.name AS linename,
                    s.name AS sublinename
                FROM core.machine_lines s
                JOIN core.machine_lines p ON p.id = s.parent_id
                WHERE s.parent_id IS NOT NULL AND s.deleted_at IS NULL
            ",

            // A project hangs off either a sub-line or (for two Gambini rows) a
            // line directly, so lineid/linename walk up to the root either way
            // and sublinename goes blank when the owner IS the root.
            'factory_projects' => "
                SELECT
                    pr.id AS id,
                    COALESCE(ln.parent_id, ln.id) AS lineid,
                    COALESCE(pl.name, ln.name) AS linename,
                    CASE WHEN ln.parent_id IS NULL THEN '' ELSE ln.name END AS sublinename,
                    pr.name AS project,
                    pr.code AS code
                FROM core.machine_projects pr
                JOIN core.machine_lines ln ON ln.id = pr.line_id
                LEFT JOIN core.machine_lines pl ON pl.id = ln.parent_id
                WHERE pr.parent_id IS NULL AND pr.deleted_at IS NULL
            ",

            // projectcode is read off the parent project — a sub-project never
            // had a code of its own, it just copied its parent's.
            'factory_subprojects' => "
                SELECT
                    sp.id AS id,
                    COALESCE(ln.parent_id, ln.id) AS lineid,
                    COALESCE(pl.name, ln.name) AS linename,
                    CASE WHEN ln.parent_id IS NULL THEN '' ELSE ln.name END AS sublinename,
                    par.name AS project,
                    par.code AS projectcode,
                    sp.name AS subproject
                FROM core.machine_projects sp
                JOIN core.machine_projects par ON par.id = sp.parent_id
                JOIN core.machine_lines ln ON ln.id = sp.line_id
                LEFT JOIN core.machine_lines pl ON pl.id = ln.parent_id
                WHERE sp.parent_id IS NOT NULL AND sp.deleted_at IS NULL
            ",

            // Only the nodes that were in factory_details, emitting the legacy
            // spelling (FACIAL, Handkerchief, Aluminium Foil) via legacy_alias
            // so existing GROUP BY linename reports don't split into two rows.
            'factory_details' => "
                SELECT
                    n.detail_id AS id,
                    f.code AS location,
                    COALESCE(pl.legacy_alias, pl.name, n.legacy_alias, n.name) AS linename,
                    COALESCE(n.legacy_alias, n.name) AS sublinename,
                    n.detail_code AS linecode
                FROM core.machine_lines n
                LEFT JOIN core.machine_lines pl ON pl.id = n.parent_id
                LEFT JOIN core.factories f ON f.id = COALESCE(n.factory_id, pl.factory_id)
                WHERE n.detail_id IS NOT NULL AND n.deleted_at IS NULL
            ",

            // `division` must emit the full legacy string ("MAINTENANCE
            // ELECTRICAL"), not the canonical name — 43k maintenance rows and
            // every legacy dropdown still speak it. `staff_no` is aliased back
            // to the legacy column name.
            'factory_staff' => "
                SELECT
                    s.id AS id,
                    s.staff_no AS staff_id,
                    s.name AS name,
                    d.name AS department,
                    COALESCE(v.legacy_name, v.name, '') AS division
                FROM core.staff s
                JOIN core.departments d ON d.id = s.department_id
                LEFT JOIN core.divisions v ON v.id = s.division_id
                WHERE s.deleted_at IS NULL
            ",
        ];
    }

    /** (Re)create all five views in bil, plus bpl's mirrors of them. */
    public static function apply(): void
    {
        $bil = DB::connection('bil');
        foreach (static::definitions() as $name => $select) {
            $bil->statement("DROP TABLE IF EXISTS `{$name}`");
            $bil->statement("CREATE OR REPLACE VIEW `{$name}` AS {$select}");
        }

        $bpl = DB::connection('bpl');
        foreach (array_keys(static::definitions()) as $name) {
            $bpl->statement("CREATE OR REPLACE VIEW `{$name}` AS SELECT * FROM `bil`.`{$name}`");
        }
    }
}
