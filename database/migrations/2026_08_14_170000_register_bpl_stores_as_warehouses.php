<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Register BPL's own stores as warehouses under Belpapyrus.
 *
 * These three are where BPL keeps finished jumbo rolls, including the ~1,000
 * reels (~700 tonnes) it is currently holding for BIL — the largest jumbo-roll
 * position after the BIL factory floors. They were only ever `location_id`
 * values on `bpl_storeentrance`; putting them in `core.warehouses` gives them a
 * name in the Warehouses admin, a company, and somewhere for gates and per-user
 * grants to hang off when the BPL receiving screens are built.
 *
 * `legacy_location_id` carries `bpl_stock_locations.id`, the same mapping the
 * raw-material warehouses use for their legacy store ids, so the movement rows
 * resolve by id rather than by matching on a name.
 *
 * Idempotent: matched on company + legacy id, so re-running updates rather than
 * duplicating, and a name edited in the admin afterwards is not overwritten.
 */
return new class extends Migration
{
    /** bpl_stock_locations.id => [name, code, module, sort] */
    private const STORES = [
        3 => ['PM2 Store', 'BPL-PM2S', 'jumbo-rolls', 10],
        4 => ['PM3 Store', 'BPL-PM3S', 'jumbo-rolls', 20],
        // Named for waste paper, but what it actually holds today is produced
        // reels (152 of them are Belimpex's), so its stock refers to the jumbo
        // product master. Retag it if it turns out to hold wp_* stock as well.
        5 => ['Waste Paper Store', 'BPL-WPS', 'jumbo-rolls', 30],
    ];

    public function up(): void
    {
        $core = DB::connection('core');

        $companyId = $core->table('companies')->where('code', 'BPL')->value('id');
        if (! $companyId) {
            return; // no Belpapyrus on this environment; nothing to hang them off
        }

        $now = now();

        foreach (self::STORES as $legacyId => [$name, $code, $module, $sort]) {
            $existing = $core->table('warehouses')
                ->where('company_id', $companyId)
                ->where('legacy_location_id', $legacyId)
                ->first();

            if ($existing) {
                continue;
            }

            $core->table('warehouses')->insert([
                'company_id' => $companyId,
                'module' => $module,
                'legacy_location_id' => $legacyId,
                'name' => $name,
                'code' => $code,
                'sort_order' => $sort,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $core = DB::connection('core');
        $companyId = $core->table('companies')->where('code', 'BPL')->value('id');

        if ($companyId) {
            $core->table('warehouses')
                ->where('company_id', $companyId)
                ->whereIn('legacy_location_id', array_keys(self::STORES))
                ->delete();
        }
    }
};
