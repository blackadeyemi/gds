<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let finished-goods history into the reports without wrecking the stock figure.
 *
 * THE PROBLEM
 * The clean cut left the Warehouse Entrance report empty: all 1.17M receipts are
 * still in the legacy `store_entrance`. Importing them fixes the report, but
 * stock is `SUM(bundles)` of receipts — so importing nine years of history would
 * report every pallet ever received as if it were still on the floor.
 *
 * THE SHAPE
 * A receipt is now either a live movement or imported history:
 *
 *     is_historic = false  counts toward stock  (gds received it)
 *     is_historic = true   reporting only       (imported from store_entrance)
 *
 * And stock stops being "sum of receipts" and becomes a small ledger, so it
 * stays DERIVABLE — which is the property worth protecting:
 *
 *     bundles = SUM(receipts WHERE NOT is_historic)
 *             + SUM(adjustments)
 *             - SUM(sales_loading.quantityloaded since the cut-over)
 *
 * Manual corrections go in `finished_goods_stock_adjustments` rather than
 * writing `bundles` directly: setting a product to N records the delta, so every
 * change has an author and a reason and the total can still be proved by
 * `bil:reconcile-fg-stock`.
 *
 * Goods leaving are read from the legacy `sales_loading` (joined to
 * `sales_order_details` for the product) rather than mirrored on write — gds has
 * no dispatch screen yet, and deriving avoids a second copy that could drift.
 * See FinishedGoodsStock for the cut-over date and the single-warehouse caveat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('finished_goods_warehouse_receipts', function (Blueprint $table) {
            // Imported history: shown in reports, excluded from stock.
            $table->boolean('is_historic')->default(false)->after('username')->index();
            // The legacy `store_entrance.id` an imported row came from, so the
            // import is idempotent and a row can be traced back.
            $table->unsignedBigInteger('legacy_id')->nullable()->after('is_historic')->unique();
        });

        Schema::connection('core')->create('finished_goods_stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->index();
            $table->unsignedInteger('productid')->index();
            // Signed delta, not a target: two people correcting the same product
            // at once must add up rather than overwrite each other.
            $table->integer('bundles');
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('username')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('finished_goods_stock_adjustments');
        Schema::connection('core')->table('finished_goods_warehouse_receipts', function (Blueprint $table) {
            $table->dropColumn(['is_historic', 'legacy_id']);
        });
    }
};
