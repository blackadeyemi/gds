<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Core\Support\TableEngines;

/**
 * Convert the last MyISAM tables in core / bil / bpl to InnoDB.
 *
 * MyISAM has no transactions. Every `DB::transaction()` that touched one of
 * these committed statement by statement, so a failed batch left rows behind —
 * the raw-materials screens work around it with GET_LOCK, and the activity log
 * (2026_10_07_110000) is written in the same unit of work as the change it
 * records. On 2026-10-07 that was 19 tables:
 *
 *   bil   factory_entrance_rawmaterials, rawmaterials_supplier_deliveries,
 *         rawmaterials_warehouse_entry, rawmaterials_warehouse_exit,
 *         return_approval, damagedgoods_rawmaterial, reprint_approval,
 *         rawmaterials_products / _groups / _subgroups,
 *         rawmaterial_store_location, factory_hod,
 *         factory_machine_maintenance_comment
 *   bpl   bpl_storeentrance_trash, bpl_storeentrance_trash_details
 *   core  countries, currencies, fg_inter_transfer, fg_inter_received
 *
 * All have an `id` primary key leading their AUTO_INCREMENT, no triggers and
 * no FULLTEXT indexes, so nothing about them changes but the engine. The views
 * that read them (bil.countries, bpl.return_approval, …) refer by name and need
 * nothing.
 *
 * Found by query rather than listed, so a table that is MyISAM for any reason
 * is caught. `gds:replay-backfills` re-runs this after a hand restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        TableEngines::convertAll();
    }

    public function down(): void
    {
        // Deliberately not reversed: going back to MyISAM would only take away
        // the transactions that the screens now rely on.
    }
};
