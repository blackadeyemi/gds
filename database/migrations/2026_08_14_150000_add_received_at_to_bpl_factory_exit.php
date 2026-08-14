<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Close the handshake between BPL's factory exit and BIL's factory entrance.
 *
 * A reel leaves BPL (`bpl_factoryexit`) and later arrives at a BIL gate
 * (`factory_entrance_reel`). Nothing ever linked the two, so "shipped but never
 * received" could only be found by an anti-join across 130k exits — ~6 seconds,
 * far too slow to put on a page, and impossible to act on because no row said
 * it was outstanding.
 *
 * `received_at` records the arrival on the exit row, so the outstanding set is
 * an indexed `received_at IS NULL` instead of a computed absence. That is what
 * makes the In Transit position on the Jumbo Rolls Stock page usable, and it is
 * the column an expected-arrivals list and an ageing exception report both hang
 * off later.
 *
 * Backfilled from the entrances that already exist. Nullable and additive: the
 * legacy BPL app names its columns on insert and never reads this one.
 *
 * Note it is derived, not authoritative — if a legacy user deletes an entrance
 * after the fact, the stamp goes stale. Re-running the same backfill statement
 * repairs it, so a reconcile command is the natural follow-up if that turns out
 * to happen in practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bpl = DB::connection('bpl');

        if (! Schema::connection('bpl')->hasColumn('bpl_factoryexit', 'received_at')) {
            $bpl->statement('ALTER TABLE `bpl_factoryexit` ADD COLUMN `received_at` DATE NULL');
            $bpl->statement('ALTER TABLE `bpl_factoryexit` ADD INDEX `bpl_factoryexit_received_idx` (`received_at`, `deleted_at`)');
        }

        // Every exit whose reel has since been scanned in at a BIL gate.
        // `dateofentrance` is legacy 'Y/m/d' text; anything unparseable lands as
        // NULL, which would read as "never received" — so those keep a sentinel
        // taken from the exit's own date rather than silently staying open.
        DB::connection('bil')->statement(
            'UPDATE `bpl`.`bpl_factoryexit` x'
            . ' JOIN `bil`.`factory_entrance_reel` f'
            . '   ON f.`barcode` = x.`barcode` AND f.`is_deleted` = 0'
            . ' SET x.`received_at` = COALESCE('
            . "     STR_TO_DATE(f.`dateofentrance`, '%Y/%m/%d'),"
            . "     STR_TO_DATE(x.`date`, '%Y/%m/%d'))"
            . ' WHERE x.`received_at` IS NULL'
        );

        // The bil-side compatibility view was created with an explicit column
        // list, so it is frozen at the old shape and would not expose the new
        // column. Everything on this side reads through the view.
        $this->refreshCompatView(true);
    }

    /** Recreate `bil.bpl_factoryexit`, with or without the new column. */
    private function refreshCompatView(bool $withReceivedAt): void
    {
        $columns = ['id', 'user', 'barcode', 'location_id', 'date', 'status',
            'created_at', 'updated_at', 'deleted_at'];

        if ($withReceivedAt) {
            $columns[] = 'received_at';
        }

        $select = implode(', ', array_map(
            fn ($c) => "`bpl`.`bpl_factoryexit`.`{$c}` AS `{$c}`",
            $columns
        ));

        DB::connection('bil')->statement(
            "CREATE OR REPLACE VIEW `bil`.`bpl_factoryexit` AS SELECT {$select} FROM `bpl`.`bpl_factoryexit`"
        );
    }

    public function down(): void
    {
        $this->refreshCompatView(false);

        if (Schema::connection('bpl')->hasColumn('bpl_factoryexit', 'received_at')) {
            DB::connection('bpl')->statement('ALTER TABLE `bpl_factoryexit` DROP INDEX `bpl_factoryexit_received_idx`');
            DB::connection('bpl')->statement('ALTER TABLE `bpl_factoryexit` DROP COLUMN `received_at`');
        }
    }
};
