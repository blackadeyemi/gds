<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Store" becomes "Warehouse" across the BPL roll pipeline.
 *
 * The places these movements happen are already **warehouses** everywhere else
 * in gds — they are rows in `core.warehouses` under Belpapyrus, with warehouse
 * gates and per-user grants. Only the legacy table names still called them
 * stores, and having the screens say one thing while the model says another is
 * the kind of split that makes people mis-read a report.
 *
 *   bpl_storeentrance       -> bpl_warehouse_entry
 *   softroll_storeentrance  -> bpl_softroll_warehouse_entry
 *   bpl_storeexit           -> bpl_warehouse_exit
 *   softroll_storeexit      -> bpl_softroll_warehouse_exit
 *
 * The softroll tables gain the `bpl_` prefix they always should have had: their
 * factory-exit twin is `bpl_softroll_factoryexit`, so the old bare names were an
 * inconsistency, not a convention.
 *
 * ⚠️ **Every old name survives as a compatibility VIEW**, because the flat PHP
 * app still reads AND WRITES these tables. A single-table `SELECT *` view is
 * insertable and updatable in MySQL, which is what makes that work — the same
 * trick the `factory_production` -> `factory_conversion` rename used, verified
 * live then. MySQL expands `SELECT *` at creation time, so the views are frozen
 * to today's columns: **add a column to a base table and you must recreate its
 * view** or the legacy app will not see it.
 *
 * The `bil`-side compat views are recreated to point at the new base tables
 * rather than at a view of a view.
 *
 * No triggers exist on any of the four, so nothing travels with them.
 */
return new class extends Migration
{
    /** new name => old name */
    private const RENAMES = [
        'bpl_warehouse_entry' => 'bpl_storeentrance',
        'bpl_softroll_warehouse_entry' => 'softroll_storeentrance',
        'bpl_warehouse_exit' => 'bpl_storeexit',
        'bpl_softroll_warehouse_exit' => 'softroll_storeexit',
    ];

    /** The gds page keys move with the screens; no grants exist on them yet. */
    private const PERMISSIONS = [
        'bpl.jumbo_rolls.store_entrance' => 'bpl.jumbo_rolls.warehouse_entry',
        'bpl.jumbo_rolls.store_exit' => 'bpl.jumbo_rolls.warehouse_exit',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $new => $old) {
            if (Schema::connection('bpl')->hasTable($new)) {
                continue;
            }

            DB::connection('bpl')->statement("RENAME TABLE `{$old}` TO `{$new}`");
            $this->compatView('bpl', $old, $new);
            $this->compatView('bil', $old, $new);
        }

        $this->renamePermissions(self::PERMISSIONS);
    }

    public function down(): void
    {
        foreach (self::RENAMES as $new => $old) {
            if (! Schema::connection('bpl')->hasTable($new)) {
                continue;
            }

            DB::connection('bpl')->statement("DROP VIEW IF EXISTS `bpl`.`{$old}`");
            DB::connection('bpl')->statement("RENAME TABLE `{$new}` TO `{$old}`");
            $this->compatView('bil', $old, $old);
        }

        $this->renamePermissions(array_flip(self::PERMISSIONS));
    }

    /** `{$schema}`.`{$name}` = SELECT * FROM `bpl`.`{$base}`. */
    private function compatView(string $schema, string $name, string $base): void
    {
        DB::connection('bpl')->statement("DROP VIEW IF EXISTS `{$schema}`.`{$name}`");
        DB::connection('bpl')->statement(
            "CREATE VIEW `{$schema}`.`{$name}` AS SELECT * FROM `bpl`.`{$base}`"
        );
    }

    /** @param array<string,string> $map old page key => new page key */
    private function renamePermissions(array $map): void
    {
        foreach ($map as $old => $new) {
            DB::connection('core')->table('permissions')
                ->where('name', 'like', $old . ':%')
                ->update(['name' => DB::raw("REPLACE(`name`, '{$old}:', '{$new}:')")]);
        }
    }
};
