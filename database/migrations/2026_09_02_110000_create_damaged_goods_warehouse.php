<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A finished-goods warehouse for rejected returns.
 *
 * When a customer sends goods back, some of it comes back unsellable. The
 * legacy recorded that as `sales_return.quantityrejected` and then contradicted
 * itself about where it went: `sales_return_request.php` added the WHOLE return
 * back to `storebundle_floor`, while the `stock_update()` call beside it added
 * only returned-minus-rejected. So damaged bundles either re-entered sellable
 * stock or vanished, depending on which figure you read.
 *
 * gds sends them here instead: still counted, never sellable, and visible on
 * the Warehouse Stock page as its own row. Nothing is written into it directly
 * — FinishedGoodsStock derives it from `sales_return`, the same way it derives
 * dispatch from `sales_loading`.
 *
 * ⚠️ `sort_order` 90 is deliberate. FinishedGoodsStock::loadingWarehouseId()
 * takes the FIRST finished-goods warehouse by sort order, and loadings would
 * be attributed to this one if it sorted before Ogba (5).
 */
return new class extends Migration
{
    private const NAME = 'Damaged Goods (FG)';
    private const CODE = 'FG-DMG';

    public function up(): void
    {
        $company = DB::connection('core')->table('warehouses')
            ->where('module', 'finished-goods')->whereNull('deleted_at')
            ->orderBy('sort_order')->value('company_id');

        if (DB::connection('core')->table('warehouses')->where('code', self::CODE)->exists()) {
            return;
        }

        DB::connection('core')->table('warehouses')->insert([
            'company_id' => $company,
            'module' => 'finished-goods',
            'name' => self::NAME,
            'code' => self::CODE,
            'sort_order' => 90,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::connection('core')->table('warehouses')->where('code', self::CODE)->value('id');

        if (! $id) {
            return;
        }

        // Stock rows are derived, so they can be dropped with it — but only
        // this warehouse's, and only if nothing was ever adjusted by hand.
        $adjusted = DB::connection('bil')->table('finished_goods_stock_adjustments')
            ->where('warehouse_id', $id)->exists();

        if ($adjusted) {
            throw new RuntimeException(
                'The damaged-goods warehouse carries manual adjustments — remove them before dropping it.'
            );
        }

        DB::connection('bil')->table('finished_goods_warehouse_stock')->where('warehouse_id', $id)->delete();
        DB::connection('core')->table('warehouses')->where('id', $id)->delete();
    }
};
