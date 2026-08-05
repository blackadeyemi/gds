<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Covering index for the Warehouse Exit report's row count.
 *
 * Counting exits over a multi-year range joins 310k exit rows to warehouse-entry
 * by barcode. With only the single-column date index, MySQL scanned the date
 * index and then read the full row for each match just to get `barcode` — 26s
 * over a 7-year range. Indexing (dateofcreation, barcode) makes both sides of
 * the join index-only ("Using index" on each), taking it to ~7s.
 *
 * Still not instant — 310k index lookups into a fanned-out join is inherently
 * expensive — but it is the difference between "slow" and "looks broken". The
 * remaining seconds are what the report's slow-count notice warns about.
 *
 * NOTE: builds an index on a 310k-row table; allow a couple of minutes and run
 * it in the maintenance window with the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::connection('bil')->selectOne(
            "SELECT 1 AS x FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'rawmaterials_warehouse_exit'
               AND index_name = 'rmse_date_barcode_idx' LIMIT 1"
        );

        if (! $exists) {
            DB::connection('bil')->statement(
                'ALTER TABLE `rawmaterials_warehouse_exit` ADD INDEX `rmse_date_barcode_idx` (`dateofcreation`, `barcode`)'
            );
        }
    }

    public function down(): void
    {
        DB::connection('bil')->statement(
            'ALTER TABLE `rawmaterials_warehouse_exit` DROP INDEX `rmse_date_barcode_idx`'
        );
    }
};
