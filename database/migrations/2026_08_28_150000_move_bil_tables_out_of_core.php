<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves the finished-goods, raw-materials and conversion-waste tables out of
 * `core` and into `bil`, where their owners already live.
 *
 * WHY THEY WERE WRONG WHERE THEY WERE
 *
 * `core` is meant to hold the platform (users, roles, pages) and the structure
 * shared across companies (companies, factories, warehouses, gates, shifts).
 * These six are none of that: every model that reads them is
 * Modules\Bil\Models\*, every service Bil\Support\*, every page Bil\Livewire\*,
 * and every row is Belimpex's — all 1,165,543 finished-goods receipts, all 117
 * raw-material stock lines, the conversion runs against a BIL factory.
 *
 * 2026_08_12_110000 said as much when it created two of them:
 *
 *   "these two are module-specific, because `productid` means a different thing
 *    per module: bil.products here, bpl_products for BPL. A shared stock table
 *    would need a discriminator ... BPL gets its own pair"
 *
 * — and then created them on the `core` connection anyway. This corrects that.
 * BPL's eventual twins now have somewhere obvious to go: `bpl`.
 *
 * `stock_transfers` / `stock_transfer_lines` deliberately STAY in core. A
 * transfer runs warehouse-to-warehouse and can cross companies (one already
 * goes BIL -> Belhin), so neither schema can own it.
 *
 * HOW
 *
 * RENAME TABLE moves a table between schemas on the same server as a metadata
 * operation — the 323 MB receipts table included — so this is fast, but it does
 * take a brief exclusive lock on each table. Run it with the app quiet.
 *
 * The two cross-schema foreign keys (cause_id, origin_id -> core.waste_*) are
 * dropped rather than carried across. InnoDB would allow them, but `bil` and
 * `bpl` declare no foreign keys at all, and conversion_waste_runs already
 * references core.factories and core.machine_lines as plain indexed columns.
 * Making the exception here would be the odd one out, and it would tie a bil
 * restore to a matching core. The indexes stay, so lookups are unaffected.
 */
return new class extends Migration
{
    /** The tables to move, largest last so a failure leaves the least behind. */
    private const TABLES = [
        'raw_materials_warehouse_stock',
        'finished_goods_warehouse_stock',
        'finished_goods_stock_adjustments',
        'conversion_waste_runs',
        'conversion_waste_entries',
        'finished_goods_warehouse_receipts',
    ];

    /**
     * Foreign keys on conversion_waste_entries: column, target, delete rule.
     * Only run_id cascades — deleting a run takes its entries with it, while a
     * cause or origin still in use cannot be deleted at all.
     */
    private const ENTRY_KEYS = [
        'conversion_waste_entries_run_id_foreign' => ['run_id', 'conversion_waste_runs', ' ON DELETE CASCADE'],
        'conversion_waste_entries_cause_id_foreign' => ['cause_id', 'waste_causes', ''],
        'conversion_waste_entries_origin_id_foreign' => ['origin_id', 'waste_origins', ''],
    ];

    public function up(): void
    {
        $this->dropEntryKeys('core');
        $this->move('core', 'bil');

        // run_id's target moved too, so this one is same-schema again.
        $this->addKey('bil', 'conversion_waste_entries_run_id_foreign', 'run_id', 'bil', 'conversion_waste_runs', ' ON DELETE CASCADE');
    }

    public function down(): void
    {
        $this->dropEntryKeys('bil');
        $this->move('bil', 'core');

        foreach (self::ENTRY_KEYS as $name => [$column, $target, $rule]) {
            $this->addKey('core', $name, $column, 'core', $target, $rule);
        }
    }

    /**
     * RENAME TABLE each table from one schema to the other, skipping any that
     * has already been moved so a half-finished run can be repeated.
     */
    private function move(string $from, string $to): void
    {
        $db = DB::connection('core');

        foreach (self::TABLES as $table) {
            if (! $this->exists($from, $table)) {
                continue;
            }

            if ($this->exists($to, $table)) {
                throw new RuntimeException(
                    "Cannot move `$table` to `$to`: a table of that name is already there. "
                    . 'Resolve by hand — this migration will not overwrite it.'
                );
            }

            $db->statement("RENAME TABLE `$from`.`$table` TO `$to`.`$table`");
        }
    }

    private function dropEntryKeys(string $schema): void
    {
        $db = DB::connection('core');

        foreach (array_keys(self::ENTRY_KEYS) as $name) {
            $exists = $db->selectOne(
                'SELECT 1 ok FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                [$schema, 'conversion_waste_entries', $name]
            );

            if ($exists) {
                $db->statement("ALTER TABLE `$schema`.`conversion_waste_entries` DROP FOREIGN KEY `$name`");
            }
        }
    }

    private function addKey(string $schema, string $name, string $column, string $targetSchema, string $target, string $rule = ''): void
    {
        if (! $this->exists($schema, 'conversion_waste_entries') || ! $this->exists($targetSchema, $target)) {
            return;
        }

        DB::connection('core')->statement(
            "ALTER TABLE `$schema`.`conversion_waste_entries`
             ADD CONSTRAINT `$name` FOREIGN KEY (`$column`)
             REFERENCES `$targetSchema`.`$target` (`id`)$rule"
        );
    }

    private function exists(string $schema, string $table): bool
    {
        return (bool) DB::connection('core')->selectOne(
            'SELECT 1 ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$schema, $table]
        );
    }
};
