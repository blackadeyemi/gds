<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bring raw materials onto the same warehouse and gate model as finished goods.
 *
 * WHAT THE LEGACY MODEL HAD
 *   rawmaterial_store_location    Ogba, Oregun — these ARE the RM warehouses
 *   rawmaterials_storeexit_details  store gates, keyed by destination factory
 *   factoryentrance_details       factory gates goods arrive at
 *   rawmaterials_stock            quantity + weight, keyed by a LOCATION NAME
 *
 * and four screens with the location hard-coded: Warehouse Entry and Factory
 * Returns both had `const LOCATION_ID = 1`, Warehouse Exit had
 * `const EXIT_LOCATION = 'Rawmaterial Store'`.
 *
 * WHAT THIS DOES
 *   - imports Ogba and Oregun as `warehouses` with module `raw-materials`,
 *     keeping `legacy_location_id` so the RM tables' `location_id` still maps;
 *   - imports the store exit gates and the factory entrance gates;
 *   - creates ONE receiving gate per RM warehouse, because the legacy app had
 *     no such table — entry was hard-coded to the warehouse itself. They are
 *     named "<Warehouse> Entrance" and can be renamed or added to;
 *   - adds a nullable `gate_id` to the four RM movement tables, additive on
 *     live legacy tables exactly as `exit_location_id` was for factory exits;
 *   - creates `raw_materials_warehouse_stock`, keyed by warehouse rather than
 *     by a location NAME, and seeds it from the in-store barcodes.
 *
 * `rawmaterials_stock` is NOT dropped and NOT migrated in place: the legacy app
 * still reads it. gds stops writing it, the same clean cut made for finished
 * goods. The new table is derivable from the barcodes, so
 * `bil:reconcile-rm-stock` can prove or repair it — which the old one could
 * never do (it had drifted ~8.5x, see the earlier reconcile command).
 */
return new class extends Migration
{
    /** Gates the RM factory-entrance screen never offered. */
    private const NON_FACTORY_ENTRANCES = ['Oregun Store'];

    public function up(): void
    {
        $core = DB::connection('core');
        $bil = DB::connection('bil');
        $now = now();

        /* ---------------- RM warehouses ---------------- */

        $companyId = $core->table('companies')->where('code', 'BIL')->value('id')
            ?? $core->table('companies')->value('id');

        $warehouseByLegacy = [];
        $order = 100;
        foreach ($bil->table('rawmaterial_store_location')->orderBy('id')->get() as $loc) {
            $existing = $core->table('warehouses')->where('legacy_location_id', $loc->id)->value('id');
            if ($existing) {
                $warehouseByLegacy[$loc->id] = $existing;

                continue;
            }

            $warehouseByLegacy[$loc->id] = $core->table('warehouses')->insertGetId([
                'company_id' => $companyId,
                'module' => 'raw-materials',
                'legacy_location_id' => $loc->id,
                'name' => $loc->location,
                'code' => null,
                'sort_order' => $order,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $order += 10;
        }

        /* ---------------- RM warehouse gates ---------------- */

        // Receiving gates. The legacy app had none — Warehouse Entry booked
        // straight against the store — so one is created per warehouse to give
        // the screen something real to pick.
        $order = 100;
        foreach ($warehouseByLegacy as $legacyId => $warehouseId) {
            $name = $core->table('warehouses')->where('id', $warehouseId)->value('name') . ' Entrance';
            if (! $core->table('warehouse_gates')->where('name', $name)->exists()) {
                $core->table('warehouse_gates')->insert([
                    'warehouse_id' => $warehouseId,
                    'name' => $name,
                    'direction' => 'in',
                    'legacy_name' => null,
                    'sort_order' => $order,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $order += 10;
            }
        }

        // Exit gates, from the legacy store-exit table. That table has one row
        // per (gate, destination factory), so the same gate appears three
        // times — the gates themselves are the DISTINCT exit locations.
        $order = 200;
        $exitGates = $bil->table('rawmaterials_storeexit_details')
            ->select('exitlocation', 'storename')->distinct()->orderBy('exitlocation')->get();

        foreach ($exitGates as $gate) {
            if ($core->table('warehouse_gates')->where('name', $gate->exitlocation)->exists()) {
                continue;
            }

            // "Store 1(Ogba) Gate 1" belongs to Ogba, "Store 2(Oregun)..." to
            // Oregun — matched on the warehouse name appearing in either label.
            $warehouseId = null;
            foreach ($warehouseByLegacy as $legacyId => $id) {
                $wName = $core->table('warehouses')->where('id', $id)->value('name');
                if (stripos($gate->exitlocation, $wName) !== false || stripos($gate->storename, $wName) !== false) {
                    $warehouseId = $id;
                    break;
                }
            }

            $core->table('warehouse_gates')->insert([
                'warehouse_id' => $warehouseId,
                'name' => $gate->exitlocation,
                'direction' => 'out',
                'legacy_name' => $gate->exitlocation,
                'sort_order' => $order,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $order += 10;
        }

        /* ---------------- Factory entrance gates ---------------- */

        $factories = $core->table('factories')->pluck('id', 'name');
        // The legacy names are not the canonical ones for the paper machines.
        $factoryAliases = ['PM2' => 'Paper Machine 2', 'PM3' => 'Paper Machine 3'];

        $order = 100;
        $gateByLegacyEntrance = [];
        foreach ($bil->table('factoryentrance_details')->orderBy('id')->get() as $gate) {
            $existing = $core->table('factory_gates')->where('name', $gate->entrancelocation)->value('id');
            if ($existing) {
                $gateByLegacyEntrance[$gate->id] = $existing;

                continue;
            }

            $factoryName = $factoryAliases[$gate->factoryname] ?? $gate->factoryname;
            // "Oregun Store" is a store, not a factory — imported inactive so
            // the 0 rows that name it still resolve, without offering it.
            $isFactory = ! in_array($gate->factoryname, self::NON_FACTORY_ENTRANCES, true);

            $gateByLegacyEntrance[$gate->id] = $core->table('factory_gates')->insertGetId([
                'factory_id' => $isFactory ? ($factories[$factoryName] ?? null) : null,
                'name' => $gate->entrancelocation,
                'direction' => 'in',
                'legacy_name' => $gate->entrancelocation,
                'sort_order' => $order,
                'is_active' => $isFactory,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $order += 10;
        }

        /* ---------------- gate_id on the RM movement tables ---------------- */

        foreach ([
            'rawmaterials_warehouse_entry',
            'rawmaterials_warehouse_exit',
            'factory_entrance_rawmaterials',
            'return_approval',
        ] as $table) {
            if (! Schema::connection('bil')->hasColumn($table, 'gate_id')) {
                $bil->statement("ALTER TABLE `{$table}` ADD COLUMN `gate_id` INT NULL");
                $bil->statement("ALTER TABLE `{$table}` ADD INDEX `{$table}_gate_id_idx` (`gate_id`)");
            }
        }

        // Factory entrances CAN be backfilled: `location_id` already points at
        // `factoryentrance_details`, so the mapping is exact.
        foreach ($gateByLegacyEntrance as $legacyId => $gateId) {
            $bil->table('factory_entrance_rawmaterials')
                ->where('location_id', $legacyId)->update(['gate_id' => $gateId]);
        }

        // Warehouse entries and exits are NOT backfilled: their `location_id`
        // is the STORE, not a gate, and the legacy app never recorded which
        // gate was used. Inventing one would be fiction. Historic rows keep a
        // null gate and report as "—".

        /* ---------------- RM stock, keyed by warehouse ---------------- */

        Schema::connection('core')->create('raw_materials_warehouse_stock', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->index();
            // bil.rawmaterials_products.id — cross-database, so a plain column.
            $table->unsignedInteger('productid')->index();
            $table->integer('quantity')->default(0);
            $table->double('weight')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'productid'], 'rm_stock_warehouse_product_unique');
        });

        // Seed from the barcode truth — items still in store — rather than from
        // `rawmaterials_stock`, which had drifted badly enough to need its own
        // reconcile command.
        $rows = $bil->table('rawmaterials_warehouse_entry')
            ->whereNull('status')
            ->groupBy('location_id', 'productid')
            ->selectRaw('location_id, productid, COUNT(*) as quantity, SUM(weight) as weight')
            ->get();

        foreach ($rows as $row) {
            $warehouseId = $warehouseByLegacy[$row->location_id] ?? null;
            if (! $warehouseId) {
                continue;
            }

            $core->table('raw_materials_warehouse_stock')->insert([
                'warehouse_id' => $warehouseId,
                'productid' => $row->productid,
                'quantity' => (int) $row->quantity,
                'weight' => (float) $row->weight,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $bil = DB::connection('bil');

        Schema::connection('core')->dropIfExists('raw_materials_warehouse_stock');

        foreach ([
            'rawmaterials_warehouse_entry',
            'rawmaterials_warehouse_exit',
            'factory_entrance_rawmaterials',
            'return_approval',
        ] as $table) {
            if (Schema::connection('bil')->hasColumn($table, 'gate_id')) {
                $bil->statement("ALTER TABLE `{$table}` DROP COLUMN `gate_id`");
            }
        }

        $core = DB::connection('core');

        // Everything this migration created is identifiable without guessing:
        // the RM warehouses are the ones carrying a legacy location id, their
        // gates hang off those, and the factory gates it added are the only
        // inbound ones (factory gates shipped as outbound).
        $rmWarehouses = $core->table('warehouses')->whereNotNull('legacy_location_id')->pluck('id');

        $gates = $core->table('warehouse_gates')
            ->whereIn('warehouse_id', $rmWarehouses)->orWhere('direction', 'out')->pluck('id');
        $core->table('warehouse_gate_user')->whereIn('gate_id', $gates)->delete();
        $core->table('warehouse_gates')->whereIn('id', $gates)->delete();

        $factoryGates = $core->table('factory_gates')->where('direction', 'in')->pluck('id');
        $core->table('factory_gate_user')->whereIn('gate_id', $factoryGates)->delete();
        $core->table('factory_gates')->whereIn('id', $factoryGates)->delete();

        $core->table('warehouses')->whereIn('id', $rmWarehouses)->delete();
    }
};
