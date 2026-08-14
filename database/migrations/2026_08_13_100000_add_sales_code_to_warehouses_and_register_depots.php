<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bring the SALES DEPOTS into the core warehouse model.
 *
 * WHY
 * Sales Order's "Order Location" is filled in the legacy app from
 * `bil.sales_warehouse` — three rows, Lagos (01) / Kano (02) / Abuja (03).
 * That is a warehouse list living outside the warehouse model, and it is the
 * same list Sales Loading, Delivery and Waybill will need. So it moves here,
 * as finished-goods warehouses under Belimpex, next to the FG store.
 *
 * `legacy_sales_code` is the bridge and MUST keep working: legacy still runs,
 * `sales_order.warehousecode` is a varchar(3) holding '01'/'02'/'03', and
 * `Sales\Loading::makeBarcode()` maps 1->L, 2->K, 3->A into the load barcode.
 * gds therefore picks a warehouse and writes its legacy code, rather than
 * inventing an id the other app cannot read.
 *
 * Lagos is NOT a new row. Warehouse 'FG' — the factory store the finished-goods
 * screens already receive into — IS the Lagos location orders are placed
 * against; giving it the code keeps one warehouse per physical place instead of
 * a "Lagos" twin that would split its stock.
 *
 * Kano is registered INACTIVE: 4 orders ever, none since 2023. Inactive keeps
 * its historic orders resolving to a name without offering it for new ones.
 */
return new class extends Migration
{
    /** Belimpex — the company that owns the BIL sales depots. */
    protected const COMPANY_CODE = 'BIL';

    public function up(): void
    {
        $core = Schema::connection('core');

        if (! $core->hasColumn('warehouses', 'legacy_sales_code')) {
            $core->table('warehouses', function (Blueprint $table) {
                // The code the legacy sales tables store. Nullable: a warehouse
                // that is not a sales depot has none, and most never will.
                $table->string('legacy_sales_code', 3)->nullable()->after('legacy_location_id')->index();
            });
        }

        $db = DB::connection('core');

        $companyId = $db->table('companies')->where('code', self::COMPANY_CODE)->value('id');
        if (! $companyId) {
            // Nothing to attach the depots to. The column is still added, so a
            // later run (or the admin editor) can finish the job.
            return;
        }

        // Lagos = the existing finished-goods store, if it has no code yet.
        $db->table('warehouses')
            ->where('company_id', $companyId)
            ->where('module', 'finished-goods')
            ->where('code', 'FG')
            ->whereNull('legacy_sales_code')
            ->update(['legacy_sales_code' => '01', 'updated_at' => now()]);

        $depots = [
            ['code' => 'KANO', 'name' => 'Kano Depot', 'sales' => '02', 'active' => 0, 'sort' => 20],
            ['code' => 'ABJ', 'name' => 'Abuja Depot', 'sales' => '03', 'active' => 1, 'sort' => 30],
        ];

        foreach ($depots as $d) {
            $exists = $db->table('warehouses')
                ->where('company_id', $companyId)
                ->where(fn ($q) => $q->where('code', $d['code'])->orWhere('legacy_sales_code', $d['sales']))
                ->exists();

            if ($exists) {
                continue;
            }

            $db->table('warehouses')->insert([
                'company_id' => $companyId,
                'module' => 'finished-goods',
                'legacy_location_id' => null,
                'legacy_sales_code' => $d['sales'],
                'name' => $d['name'],
                'code' => $d['code'],
                'sort_order' => $d['sort'],
                'is_active' => $d['active'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $db = DB::connection('core');

        // Only the rows this migration created — and only while still empty, so
        // a depot that has since been given gates or edited is left alone.
        $db->table('warehouses')
            ->whereIn('code', ['KANO', 'ABJ'])
            ->whereNotExists(fn ($q) => $q->from('warehouse_gates')
                ->whereColumn('warehouse_gates.warehouse_id', 'warehouses.id'))
            ->delete();

        if (Schema::connection('core')->hasColumn('warehouses', 'legacy_sales_code')) {
            Schema::connection('core')->table('warehouses', function (Blueprint $table) {
                $table->dropColumn('legacy_sales_code');
            });
        }
    }
};
