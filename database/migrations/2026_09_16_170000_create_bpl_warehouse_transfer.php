<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A log of rolls moved between BPL warehouses.
 *
 * The legacy `bpl_store_transfer.php` recorded NOTHING. It overwrote
 * `bpl_storeentrance.location_id` in place and adjusted the two stock rows, so
 * after a transfer the receipt claimed the roll had arrived at the destination
 * and there was no trace it had ever been anywhere else. The only record was a
 * Monolog line in a file nobody reads.
 *
 * That matters more here than it looks: the Warehouse Stock page derives the
 * held position from the entry rows, and its Movement anomalies view exists to
 * catch exactly this class of silent rewrite. A transfer that leaves no
 * movement behind is a hole in that audit.
 *
 * gds-only and new, so it covers BOTH streams with a `stream` column rather
 * than mirroring the legacy hardroll/softroll table split — there is no legacy
 * table to stay compatible with. `weight` is stored on the row so the log can
 * be reconciled without re-reading production, which is what the entry tables
 * do NOT let you do after the fact.
 *
 * `barcode` is deliberately not unique: a roll can be moved more than once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('bpl')->hasTable('bpl_warehouse_transfer')) {
            return;
        }

        Schema::connection('bpl')->create('bpl_warehouse_transfer', function ($t) {
            $t->increments('id');
            $t->string('stream', 10);
            $t->string('barcode', 20);
            $t->unsignedTinyInteger('from_location_id');
            $t->unsignedTinyInteger('to_location_id');
            // What moved, as at the moment it moved.
            $t->double('weight');
            $t->string('user', 50)->nullable();
            // Legacy 'Y/m/d' text, matching every other BPL movement table so
            // the reports can union them without converting.
            $t->string('date', 20);
            $t->timestamp('created_at')->useCurrent();
            $t->softDeletes();

            $t->index('barcode', 'bpl_warehouse_transfer_barcode_idx');
            $t->index(['date', 'deleted_at'], 'bpl_warehouse_transfer_date_idx');
            $t->index(['to_location_id', 'deleted_at'], 'bpl_warehouse_transfer_to_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('bpl')->dropIfExists('bpl_warehouse_transfer');
    }
};
