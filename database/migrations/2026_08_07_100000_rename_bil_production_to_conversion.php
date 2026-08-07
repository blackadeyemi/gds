<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename BIL's "production" tables to "conversion".
 *
 * BIL does not produce paper — it CONVERTS hardroll made by BPL into finished
 * goods. The legacy names say production, which reads as if BIL and BPL do the
 * same thing. This renames the BIL side only: bpl_production and
 * bpl_softroll_production keep their names, because those record actual paper
 * manufacture.
 *
 *   factory_production            -> factory_conversion          (1.2M rows)
 *   factory_preproduction         -> conversion_setup            (what each
 *                                    line is set up to convert, 1 row/line)
 *   factory_preproduction_history -> conversion_setup_history    (25k changeovers)
 *
 * Each old name is left behind as a plain compatibility VIEW. Unlike the
 * factory_lines/details views (which are composed and therefore read-only),
 * a straight one-table view IS insertable and updatable, so the 30+ legacy
 * pages keep both reading AND writing through it — including the changeover
 * screen's `UPDATE … WHERE linename` plus INSERT fallback.
 *
 * The BEFORE INSERT/UPDATE triggers that resolve linename -> line_id travel
 * with the base table and still fire for writes made through the view.
 *
 * NOTE MySQL expands `SELECT *` at view-creation time, so a view is frozen to
 * today's columns. That is deliberate: a column added for gds does not leak
 * into the legacy app. Recreate the view if legacy ever needs a new one.
 */
return new class extends Migration
{
    /** old (kept as a view) => new base table name */
    private array $renames = [
        'factory_production' => 'factory_conversion',
        'factory_preproduction' => 'conversion_setup',
        'factory_preproduction_history' => 'conversion_setup_history',
    ];

    public function up(): void
    {
        $db = DB::connection('bil');

        foreach ($this->renames as $old => $new) {
            if (! $this->isBaseTable($old)) {
                continue; // already migrated
            }

            $db->statement("RENAME TABLE `{$old}` TO `{$new}`");
            $db->statement("CREATE OR REPLACE VIEW `{$old}` AS SELECT * FROM `{$new}`");
        }
    }

    public function down(): void
    {
        $db = DB::connection('bil');

        foreach ($this->renames as $old => $new) {
            if ($this->isBaseTable($old)) {
                continue; // never migrated
            }

            $db->statement("DROP VIEW IF EXISTS `{$old}`");
            $db->statement("RENAME TABLE `{$new}` TO `{$old}`");
        }
    }

    private function isBaseTable(string $name): bool
    {
        $row = DB::connection('bil')->selectOne(
            'SELECT TABLE_TYPE t FROM information_schema.tables
             WHERE table_schema = DATABASE() AND TABLE_NAME = ?',
            [$name]
        );

        return $row && $row->t === 'BASE TABLE';
    }
};
