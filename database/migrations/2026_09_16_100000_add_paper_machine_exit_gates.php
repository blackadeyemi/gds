<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Outbound gates for the two paper machines, so BPL Factory Exit has somewhere
 * to send a reel from.
 *
 * `core.factory_gates` already carried PM2 Gate / PM3 Gate, but both are
 * `direction = in` — the arriving side. A reel leaving the machine floor is an
 * `out` movement and had no gate at all.
 *
 * `legacy_name` carries the name the legacy BPL tables use for the same place:
 * `bpl_stock_locations.location`, i.e. bare 'PM2' / 'PM3'. `bpl_factoryexit`
 * stores that table's id, and the flat PHP app still reads it, so the gate has
 * to resolve back to it — see Bpl\Livewire\JumboRolls\FactoryExit::legacyLocationId().
 *
 * Who may pick which gate is a per-user grant (`factory_gate_user`), which is
 * what finally replaces the legacy `userlevel === 42 ? PM2 : PM3` filter baked
 * into bpl_factory_exit.php. Admins see both regardless.
 */
return new class extends Migration
{
    /** factory code => [gate name, legacy location name, sort order] */
    private const GATES = [
        'PM2' => ['PM2 Exit', 'PM2', 160],
        'PM3' => ['PM3 Exit', 'PM3', 170],
    ];

    public function up(): void
    {
        $core = DB::connection('core');
        $now = now();

        foreach (self::GATES as $factoryCode => [$name, $legacy, $order]) {
            $factoryId = $core->table('factories')->where('code', $factoryCode)->value('id');

            // A rebuilt environment without the paper machines simply gets no
            // gate; the screen says so rather than failing the migration.
            if (! $factoryId) {
                continue;
            }

            $core->table('factory_gates')->updateOrInsert(
                ['name' => $name],
                [
                    'factory_id' => $factoryId,
                    'direction' => 'out',
                    'legacy_name' => $legacy,
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
        DB::connection('core')->table('factory_gates')
            ->whereIn('name', array_column(self::GATES, 0))
            ->delete();
    }
};
