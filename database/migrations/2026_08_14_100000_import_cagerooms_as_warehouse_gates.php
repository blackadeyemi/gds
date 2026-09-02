<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cagerooms become warehouse gates.
 *
 * A cageroom is the bay a truck is loaded from — `store_cagerooms` maps a
 * `cageroomcode` to a `warehousecode`, which is exactly what a warehouse gate
 * already is: a named way out of a warehouse. Importing them rather than
 * reading the legacy lookup means loading reuses the gate model and its
 * per-user access, so an operator sees only the bays they work.
 *
 * `legacy_name` keeps the cageroom CODE (CGR04), because that is what
 * `sales_loading.cageroomcode` stores and what the legacy app still writes.
 * The mapping back is by that column, not by the gate id.
 *
 * Idempotent: matched on (warehouse_id, legacy_name), so re-running adds
 * nothing and preserves any renaming done in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        $core = DB::connection('core');
        $bil = DB::connection('bil');

        if (! $core->getSchemaBuilder()->hasTable('warehouse_gates')) {
            return;
        }

        // sales warehousecode ('01') -> the core warehouse it already maps to.
        $warehouses = $core->table('warehouses')
            ->whereNotNull('legacy_sales_code')
            ->pluck('id', 'legacy_sales_code');

        try {
            $cagerooms = $bil->table('store_cagerooms')->orderBy('cageroomcode')->get();
        } catch (\Throwable) {
            return; // no legacy lookup in this environment
        }

        $sort = 200;

        foreach ($cagerooms as $room) {
            $warehouseId = $warehouses[$room->warehousecode] ?? null;

            // A cageroom whose warehouse we cannot place is left alone rather
            // than parked on a guess — an unattributed gate is worse than none.
            if (! $warehouseId) {
                continue;
            }

            $exists = $core->table('warehouse_gates')
                ->where('warehouse_id', $warehouseId)
                ->where('legacy_name', $room->cageroomcode)
                ->exists();

            if ($exists) {
                continue;
            }

            $core->table('warehouse_gates')->insert([
                'warehouse_id' => $warehouseId,
                'name' => $room->cageroomnumber,
                // Goods LEAVE through a cageroom.
                'direction' => 'out',
                'legacy_name' => $room->cageroomcode,
                'sort_order' => $sort += 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Only the ones this migration could have created: an 'out' gate whose
        // legacy_name is a cageroom code.
        DB::connection('core')->table('warehouse_gates')
            ->where('direction', 'out')
            ->where('legacy_name', 'like', 'CGR%')
            ->delete();
    }
};
