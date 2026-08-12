<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index the legacy columns gds actually filters on.
 *
 * The legacy schema indexed identity (`barcode`, `id`) and the foreign-key-ish
 * columns, but never the DATES — and every report gds builds opens on a date
 * range. So each one was a full scan of a million-row table, whatever the range:
 * measured on dev, the Conversion Output and Factory Exit reports spent ~3.5s per
 * query and ran three or four of them per render.
 *
 * Chosen by measurement, not by eye. Every column here has a worst-case bucket
 * under 1% of its table, so a predicate on it throws nearly everything away —
 * which is exactly when an index pays. The categorical filters that sit next to
 * them in the same reports (`factory`, `shift`, `linename`, `exitlocation`,
 * `status` on the RM tables) are all 30–100% in their worst bucket and are
 * DELIBERATELY left alone: MySQL would ignore such an index and the writes would
 * still pay for it. `factory_machine_maintenance.date` is left alone too, for a
 * different reason — the table is 44k rows and already answers in 5ms.
 *
 * The MyISAM raw-materials tables are untouched. Their candidates measured
 * marginal at best, and ADD INDEX on MyISAM locks the table for the rebuild,
 * which is not a trade worth making for a maybe on a live shared database.
 *
 * Additive and index-only: no column, row or value changes, so the legacy PHP
 * app reading the same tables sees nothing but faster queries.
 */
return new class extends Migration
{
    /**
     * name => [table, columns, why].
     *
     * The two composites put `id` after the date because both reports order by
     * `id DESC` within a range. With the date fixed — the default, since the
     * pickers open on today — the index then supplies the ordering too, so the
     * page comes back from a backwards index scan with no filesort at all.
     */
    private const INDEXES = [
        'fc_barcode_idx' => ['factory_conversion', ['barcode'],
            'every Factory Exit scan validates against it; 1.2M rows, near-unique'],
        'fc_production_date_id_idx' => ['factory_conversion', ['dateofproduction', 'id'],
            'Conversion Output + Factory Floor Stock date range'],
        'fe_exit_date_id_idx' => ['factory_exit', ['dateofexit', 'id'],
            'Factory Exit report date range'],
        'sl_loading_date_idx' => ['sales_loading', ['dateofloading'],
            'stock ledger since cut-over + the movements modal'],
        'slr_loading_id_idx' => ['sales_loading_return', ['loading_id'],
            'the unload join in loadedSinceCutover()'],
        'so_order_date_idx' => ['sales_order', ['dateoforder'],
            '90-day order window + the movements modal'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if ($this->hasIndex($table, $name)) {
                continue;
            }

            $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));

            // Explicit online DDL. Adding a secondary index to an InnoDB table
            // always supports INPLACE/NONE, so naming it costs nothing — and if
            // that ever stops being true the migration fails loudly instead of
            // quietly locking a table the legacy app is still writing to.
            DB::connection('bil')->statement(
                "ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$cols}), ALGORITHM=INPLACE, LOCK=NONE"
            );
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => [$table]) {
            if ($this->hasIndex($table, $name)) {
                DB::connection('bil')->statement(
                    "ALTER TABLE `{$table}` DROP INDEX `{$name}`, ALGORITHM=INPLACE, LOCK=NONE"
                );
            }
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(DB::connection('bil')->select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($i) => $i->Key_name === $name);
    }
};
