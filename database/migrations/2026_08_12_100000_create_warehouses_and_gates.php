<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouses, and the gates goods move through — company-wide structure, not a
 * finished-goods concern.
 *
 * These sit beside `factories` in core and are built the same way: a company
 * owns sites, a site owns gates. BPL has warehouses and gates too, so modelling
 * these per module would mean a `bpl_warehouse` twin of every table here.
 *
 *   warehouses                    a warehouse, owned by a company
 *   warehouse_entrances           a gate goods are received through
 *   warehouse_entrance_user       which entrances a user may pick
 *   factory_exit_locations        a gate goods leave a factory through
 *   factory_exit_location_user    which of those a user may pick
 *
 * WHAT THIS REPLACES
 * The legacy model had no warehouse at all: `storebundle` hard-coded a single
 * `warehousecode = '01'`, `storebundle_floor` hard-coded three floors, which
 * floor a gate fed was a PHP string comparison against one location name, and
 * who could use which gate was a `switch` on the legacy user level. Gates
 * themselves were name pairs in `storeentrance_details` / `factoryexit_details`.
 *
 * The name `warehouses` also supersedes the legacy `storelocations` — a rack-line
 * layout abandoned in April 2018 (560 rows, last written 2018-04-17, referenced
 * by no legacy PHP or JS). Its columns describe rack lines, not warehouses, so
 * there is nothing to carry over; it is left in place untouched as dead data.
 *
 * Warehouses are deliberately NOT seeded — the legacy data has no warehouse
 * concept to derive them from. The three imported entrances arrive unassigned
 * and cannot receive until someone attaches them to a warehouse.
 */
return new class extends Migration
{
    public function up(): void
    {
        $core = Schema::connection('core');

        /* ---------------- Warehouses ---------------- */

        // Mirrors `factories`: a company owns it, it carries a short code, and
        // it can be deactivated without losing its history.
        $core->create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('name');
            $table->string('code', 20)->nullable()->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            // Name is unique per company, not globally — two companies may each
            // have a "Main Store". Same rule the Factories editor enforces.
            $table->unique(['company_id', 'name'], 'warehouses_company_name_unique');
        });

        $core->create('warehouse_entrances', function (Blueprint $table) {
            $table->id();
            // Nullable: the legacy gates are imported below with no warehouse,
            // because there are none yet to put them in. An entrance with no
            // warehouse cannot be used to receive — there is nowhere to put
            // the stock.
            $table->unsignedBigInteger('warehouse_id')->nullable()->index();
            $table->string('name')->unique();
            // The legacy `entrancelocation` string this gate came from, so
            // historic receipts can still be matched to it.
            $table->string('legacy_name')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Which entrances a user may choose from. No rows = no entrances,
        // except Admin, which is not scoped by this table at all.
        $core->create('warehouse_entrance_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entrance_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unique(['entrance_id', 'user_id'], 'warehouse_entrance_user_unique');
        });

        /* ---------------- Factory exit gates ---------------- */

        $core->create('factory_exit_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('factory_id')->nullable()->index();
            $table->string('name')->unique();
            $table->string('legacy_name')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        $core->create('factory_exit_location_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exit_location_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unique(['exit_location_id', 'user_id'], 'factory_exit_location_user_unique');
        });

        /* ---------------- Import the gates that already exist ---------------- */

        $now = now();

        // Warehouse entrances keep their legacy names so nothing has to be
        // re-learned, and carry `legacy_name` for matching history.
        $order = 10;
        foreach (DB::connection('bil')->table('storeentrance_details')->orderBy('id')->get() as $gate) {
            DB::connection('core')->table('warehouse_entrances')->insert([
                'warehouse_id' => null,
                'name' => $gate->entrancelocation,
                'legacy_name' => $gate->entrancelocation,
                'sort_order' => $order,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $order += 10;
        }

        // Factory exit gates CAN resolve their parent: factoryexit_details
        // already names the factory, and those factories exist in core.
        $factories = DB::connection('core')->table('factories')->pluck('id', 'name');
        $order = 10;
        foreach (DB::connection('bil')->table('factoryexit_details')->orderBy('id')->get() as $gate) {
            DB::connection('core')->table('factory_exit_locations')->insert([
                'factory_id' => $factories[$gate->factoryname] ?? null,
                'name' => $gate->exitlocation,
                'legacy_name' => $gate->exitlocation,
                'sort_order' => $order,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $order += 10;
        }

        // Bil-2 really had a gate: 16 pallets from April 2017 name it, they
        // carry B2 barcodes, and their conversion rows say factory Bil-2. It
        // was simply dropped from `factoryexit_details` at some point. Recreate
        // it INACTIVE so that history resolves without offering anyone a gate
        // that is no longer in use.
        DB::connection('core')->table('factory_exit_locations')->insert([
            'factory_id' => $factories['Bil-2'] ?? null,
            'name' => self::RETIRED_GATE,
            'legacy_name' => self::RETIRED_GATE,
            'sort_order' => $order,
            'is_active' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /* ---------------- Link historic exits to their gate ---------------- */

        // Additive nullable column on a live legacy table, exactly as
        // factory_id / line_id were added to factory_conversion.
        if (! Schema::connection('bil')->hasColumn('factory_exit', 'exit_location_id')) {
            DB::connection('bil')->statement(
                'ALTER TABLE `factory_exit` ADD COLUMN `exit_location_id` INT NULL'
            );
            DB::connection('bil')->statement(
                'ALTER TABLE `factory_exit` ADD INDEX `fe_exit_location_id_idx` (`exit_location_id`)'
            );
        }

        // Map every spelling that appears in the 1.2M historic rows onto a gate,
        // including four from March–April 2017 that `factoryexit_details` never
        // held. Three are plain spelling variants from the system's first weeks,
        // before the naming settled; the fourth is the retired Bil-2 gate above.
        $byName = DB::connection('core')->table('factory_exit_locations')->pluck('id', 'name');

        $map = [];
        foreach ($byName as $name => $id) {
            $map[$name] = $id;
        }
        foreach (self::ALIASES as $legacySpelling => $canonical) {
            if (isset($byName[$canonical])) {
                $map[$legacySpelling] = $byName[$canonical];
            }
        }

        // One CASE pass over the 1.2M rows rather than an UPDATE per gate:
        // `exitlocation` is an unindexed varchar, so each separate statement
        // would be its own full table scan.
        if ($map !== []) {
            $case = 'CASE `exitlocation`';
            $bindings = [];
            foreach ($map as $name => $id) {
                $case .= ' WHEN ? THEN ?';
                $bindings[] = $name;
                $bindings[] = $id;
            }
            $case .= ' ELSE NULL END';

            DB::connection('bil')->update("UPDATE `factory_exit` SET `exit_location_id` = {$case}", $bindings);
        }
    }

    /**
     * Legacy spellings that predate `factoryexit_details`, and the gate each
     * means. All four date from March–April 2017:
     *
     *   Bil-1 Elevator   578 rows  2017/03/29 – 2017/03/31
     *   BIL1 Elevator    153 rows  2017/03/29
     *   Gambini Gate       3 rows  2017/03/30 – 2017/03/31
     *
     * `exitlocation` itself is left alone — the legacy app reads that column and
     * the old spellings are what actually happened. Only the new id is filled in.
     */
    private const ALIASES = [
        'Bil-1 Elevator' => 'BIL1-Elevator',
        'BIL1 Elevator' => 'BIL1-Elevator',
        'Gambini Gate' => 'Gambini Gate 1',
    ];

    /** Bil-2's gate, dropped from factoryexit_details but named by 16 exits. */
    private const RETIRED_GATE = 'BIL2-Gate 1';

    public function down(): void
    {
        if (Schema::connection('bil')->hasColumn('factory_exit', 'exit_location_id')) {
            DB::connection('bil')->statement('ALTER TABLE `factory_exit` DROP COLUMN `exit_location_id`');
        }

        $core = Schema::connection('core');
        foreach ([
            'factory_exit_location_user',
            'factory_exit_locations',
            'warehouse_entrance_user',
            'warehouse_entrances',
            'warehouses',
        ] as $table) {
            $core->dropIfExists($table);
        }
    }
};
