<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fold gate DIRECTION into the gate, and say what each warehouse stores.
 *
 * WHY
 * The first cut modelled only the two gates finished goods needed — goods into
 * a warehouse, goods out of a factory. Raw materials needs the other two: out of
 * a warehouse (Warehouse Exit) and into a factory (Factory Entrance, Factory
 * Returns). Four gate tables plus four user-grant pivots is the same shape four
 * times over, and would put four checklists in the user editor.
 *
 * A gate is a place goods pass through; which way they are going is an attribute
 * of the movement, not a different kind of thing. `both` is a real case — one
 * elevator or roller door is often used either way.
 *
 *   warehouse_entrances        -> warehouse_gates      (+ direction, default in)
 *   factory_exit_locations     -> factory_gates        (+ direction, default out)
 *   warehouse_entrance_user    -> warehouse_gate_user  (entrance_id -> gate_id)
 *   factory_exit_location_user -> factory_gate_user    (exit_location_id -> gate_id)
 *
 * These renames are safe: the tables shipped days ago, hold only the imported
 * gates, and nothing outside gds reads them.
 *
 * AND: `warehouses.module` — what the warehouse stores. Not cosmetic; it decides
 * which product master its stock refers to and which stock table holds it. See
 * config/warehouses.php for why the list lives in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $core = Schema::connection('core');

        /* ---------------- Warehouse gates ---------------- */

        $core->rename('warehouse_entrances', 'warehouse_gates');
        $core->table('warehouse_gates', function (Blueprint $table) {
            // Everything imported so far is a receiving gate.
            $table->string('direction', 8)->default('in')->after('name')->index();
        });

        $core->rename('warehouse_entrance_user', 'warehouse_gate_user');
        $core->table('warehouse_gate_user', function (Blueprint $table) {
            $table->renameColumn('entrance_id', 'gate_id');
        });

        /* ---------------- Factory gates ---------------- */

        $core->rename('factory_exit_locations', 'factory_gates');
        $core->table('factory_gates', function (Blueprint $table) {
            $table->string('direction', 8)->default('out')->after('name')->index();
        });

        $core->rename('factory_exit_location_user', 'factory_gate_user');
        $core->table('factory_gate_user', function (Blueprint $table) {
            $table->renameColumn('exit_location_id', 'gate_id');
        });

        /* ---------------- What a warehouse stores ---------------- */

        $core->table('warehouses', function (Blueprint $table) {
            // Nullable: an existing warehouse has to be told what it holds
            // before its screens can use it, and guessing would be worse than
            // asking. `usableFor()` on the model treats NULL as "not ready".
            $table->string('module', 32)->nullable()->after('company_id')->index();
            // Where a raw-materials warehouse came from: the legacy
            // `rawmaterial_store_location` id that RM tables still store in
            // their `location_id` columns. Null for warehouses with no legacy
            // counterpart.
            $table->unsignedInteger('legacy_location_id')->nullable()->after('module')->index();
        });

        // The finished-goods gates imported from `storeentrance_details` are,
        // by definition, finished-goods gates — but their warehouses do not
        // exist yet, so there is nothing to tag. Left for the operator.
        DB::connection('core')->table('warehouses')
            ->whereNull('module')->update(['module' => 'finished-goods']);
    }

    public function down(): void
    {
        $core = Schema::connection('core');

        $core->table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['module', 'legacy_location_id']);
        });

        $core->table('factory_gate_user', function (Blueprint $table) {
            $table->renameColumn('gate_id', 'exit_location_id');
        });
        $core->rename('factory_gate_user', 'factory_exit_location_user');

        $core->table('factory_gates', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
        $core->rename('factory_gates', 'factory_exit_locations');

        $core->table('warehouse_gate_user', function (Blueprint $table) {
            $table->renameColumn('gate_id', 'entrance_id');
        });
        $core->rename('warehouse_gate_user', 'warehouse_entrance_user');

        $core->table('warehouse_gates', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
        $core->rename('warehouse_gates', 'warehouse_entrances');
    }
};
