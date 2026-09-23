<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inbound gates for the three BPL stores, so Store Entrance has somewhere to
 * receive into.
 *
 * The stores themselves were registered as warehouses under Belpapyrus back in
 * `2026_08_14_170000` (module `jumbo-rolls`, `legacy_location_id` carrying
 * `bpl_stock_locations.id`), but no gate was ever attached — nothing had needed
 * one until now.
 *
 * Who may receive into which store becomes a per-user grant
 * (`warehouse_gate_user`), replacing the legacy filter baked into
 * bpl_store_entrance.php's JavaScript: userlevel 45 saw only PM2 Store, 46 only
 * PM3 Store, 44 only Waste Paper Store.
 *
 * Only `in` gates here. Store Exit needs `out` gates and can add its own when
 * it is built — a gate nobody can use yet is just a wrong choice on a dropdown.
 */
return new class extends Migration
{
    /** warehouse code => [gate name, sort order] */
    private const GATES = [
        'BPL-PM2S' => ['PM2 Store Entrance', 200],
        'BPL-PM3S' => ['PM3 Store Entrance', 210],
        'BPL-WPS' => ['Waste Paper Store Entrance', 220],
    ];

    public function up(): void
    {
        $core = DB::connection('core');
        $now = now();

        foreach (self::GATES as $code => [$name, $order]) {
            $warehouseId = $core->table('warehouses')->where('code', $code)->value('id');

            // A rebuilt environment without the BPL stores simply gets no gate;
            // the screen says so rather than failing the migration.
            if (! $warehouseId) {
                continue;
            }

            $core->table('warehouse_gates')->updateOrInsert(
                ['name' => $name],
                [
                    'warehouse_id' => $warehouseId,
                    'direction' => 'in',
                    // The legacy store screens name a place, not a gate — the
                    // warehouse's own `legacy_location_id` is what maps back to
                    // `bpl_stock_locations`, so there is nothing to put here.
                    'legacy_name' => null,
                    'sort_order' => $order,
                    'is_active' => 1,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::connection('core')->table('warehouse_gates')
            ->whereIn('name', array_column(self::GATES, 0))
            ->delete();
    }
};
