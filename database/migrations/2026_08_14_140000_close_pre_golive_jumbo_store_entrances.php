<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clear phantom jumbo-roll stock left in the BPL stores by the old system.
 *
 * `jumboreel_storeentrance.status IS NULL` means "received into a PM store and
 * not yet released", which is how the Jumbo Rolls Stock page decides a reel is
 * standing in a BPL warehouse. 57 Belimpex reels have carried that flag since
 * December 2018 – February 2019: the store-exit step that should have cleared
 * them was never run before the BIL factory-entrance system went live in March
 * 2021 (`factory_entrance_reel` starts in earnest 2021/03; the handful of
 * earlier rows are tests). Those reels were consumed years ago — they are not
 * stock, and reporting 25,953 kg that does not exist is worse than reporting
 * nothing.
 *
 * Scope, deliberately narrow:
 *   - Belimpex reels only (`bpl_production.customer_id` = the configured jumbo
 *     customer). 54 rows of the same vintage belong to other customers; they
 *     are BPL's to clean up when those modules are built, not this module's.
 *   - Dated before the go-live cut-over. Anything after it is live data.
 *
 * The pre-go-live PRODUCTION rows need no such fix: all 54,073 Belimpex reels
 * made before the cut-over that never reached a BIL gate already carry
 * `status = 'Exited'`, so the stock page has never counted them.
 *
 * `status` is set to CLOSED rather than the 'yes' a real store exit writes, so
 * these rows stay distinguishable from reels that genuinely left, and down()
 * can restore exactly what up() changed. Every legacy reader of this column
 * tests `IS NULL`, so any non-null value behaves identically to them.
 */
return new class extends Migration
{
    /** First month the BIL factory-entrance system was in real use. */
    private const GO_LIVE = '2021/03/01';

    /** Marker written into `status`: closed by this cleanup, not by a store exit. */
    private const CLOSED = 'closed-stale';

    public function up(): void
    {
        // The whole `jumboreel_*` route was retired shortly after this ran, so
        // on a rebuilt environment there is nothing left to clean up.
        if (! Schema::connection('core')->hasTable('jumboreel_storeentrance')) {
            return;
        }

        $barcodes = $this->staleBarcodes();

        if ($barcodes === []) {
            return;
        }

        DB::connection('core')->table('jumboreel_storeentrance')
            ->whereIn('barcode', $barcodes)
            ->whereNull('status')
            ->update(['status' => self::CLOSED]);
    }

    public function down(): void
    {
        if (! Schema::connection('core')->hasTable('jumboreel_storeentrance')) {
            return;
        }

        DB::connection('core')->table('jumboreel_storeentrance')
            ->where('status', self::CLOSED)
            ->update(['status' => null]);
    }

    /**
     * Belimpex reels still flagged as in a PM store from before the cut-over.
     *
     * Resolved on the `bil` connection, where `jumboreel_storeentrance` and
     * `bpl_production` are both compatibility views, so the join is one query;
     * the update itself goes to the `core` base table.
     */
    private function staleBarcodes(): array
    {
        return DB::connection('bil')->table('jumboreel_storeentrance as se')
            ->join('bpl_production as prod', 'prod.barcode', '=', 'se.barcode')
            ->whereNull('se.status')
            ->where('se.dateofentrance', '<', self::GO_LIVE)
            ->where('prod.customer_id', (int) config('bil.jumbo_roll_customer_id'))
            ->whereNull('prod.deleted_at')
            ->pluck('se.barcode')
            ->all();
    }
};
