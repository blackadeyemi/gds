<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Outbound gates for the three BPL stores, so Store Exit has somewhere to send
 * a roll from.
 *
 * `2026_09_16_110000` added the inbound side and deliberately stopped there — a
 * gate nobody can use is a wrong choice on a dropdown. This is that page
 * arriving.
 *
 * Who may release from which store is a per-user grant (`warehouse_gate_user`),
 * the same mechanism the entrance uses.
 */
return new class extends Migration
{
    /** warehouse code => [gate name, sort order] */
    private const GATES = [
        'BPL-PM2S' => ['PM2 Store Exit', 230],
        'BPL-PM3S' => ['PM3 Store Exit', 240],
        'BPL-WPS' => ['Waste Paper Store Exit', 250],
    ];

    public function up(): void
    {
        $core = DB::connection('core');
        $now = now();

        foreach (self::GATES as $code => [$name, $order]) {
            $warehouseId = $core->table('warehouses')->where('code', $code)->value('id');

            if (! $warehouseId) {
                continue;
            }

            $core->table('warehouse_gates')->updateOrInsert(
                ['name' => $name],
                [
                    'warehouse_id' => $warehouseId,
                    'direction' => 'out',
                    // The legacy screens name a place, not a gate; the
                    // warehouse's own `legacy_location_id` is the mapping back
                    // to `bpl_stock_locations`.
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
