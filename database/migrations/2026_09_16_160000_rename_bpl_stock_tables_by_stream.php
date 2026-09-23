<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Name both stock tables after the stream they hold.
 *
 *   bpl_stock       -> bpl_hardroll_stock
 *   softroll_stock  -> bpl_softroll_stock
 *
 * `bpl_stock` held HARDROLL stock only — the bare name made it look like the
 * stock table, with softroll stock as some special case beside it. They are two
 * equal halves, and the rest of the module already says so
 * (`bpl_products_hardroll` / `bpl_products_softroll`,
 * `bpl_warehouse_entry` / `bpl_softroll_warehouse_entry`).
 *
 * ⚠️ The two are still keyed DIFFERENTLY and the rename does not change that:
 * `bpl_hardroll_stock` is UNIQUE (location_id, product_id), `bpl_softroll_stock`
 * is UNIQUE (location_id, grade_id) — softroll stock predates
 * `bpl_products_softroll`. Re-keying would rewrite the legacy reports too.
 *
 * Both old names survive as writable compat views on `bpl` AND `bil`, and both
 * new names are exposed on `bil` as well — gds reads BPL data through the `bil`
 * connection in places (the Jumbo Rolls Stock page), and the previous rename
 * learned that the hard way by shipping only the old names there.
 */
return new class extends Migration
{
    /** new name => old name */
    private const RENAMES = [
        'bpl_hardroll_stock' => 'bpl_stock',
        'bpl_softroll_stock' => 'softroll_stock',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $new => $old) {
            if (Schema::connection('bpl')->hasTable($new)) {
                continue;
            }

            DB::connection('bpl')->statement("RENAME TABLE `{$old}` TO `{$new}`");

            // Old names: what the flat PHP app still reads and writes.
            $this->view('bpl', $old, $new);
            $this->view('bil', $old, $new);
            // New name on bil: what gds reads when it unions BPL legs there.
            $this->view('bil', $new, $new);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $new => $old) {
            if (! Schema::connection('bpl')->hasTable($new)) {
                continue;
            }

            DB::connection('bpl')->statement("DROP VIEW IF EXISTS `bpl`.`{$old}`");
            DB::connection('bpl')->statement("DROP VIEW IF EXISTS `bil`.`{$new}`");
            DB::connection('bpl')->statement("RENAME TABLE `{$new}` TO `{$old}`");
            $this->view('bil', $old, $old);
        }
    }

    private function view(string $schema, string $name, string $base): void
    {
        DB::connection('bpl')->statement("DROP VIEW IF EXISTS `{$schema}`.`{$name}`");
        DB::connection('bpl')->statement(
            "CREATE VIEW `{$schema}`.`{$name}` AS SELECT * FROM `bpl`.`{$base}`"
        );
    }
};
