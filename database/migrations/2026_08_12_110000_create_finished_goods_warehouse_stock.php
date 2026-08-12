<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finished-goods receipts and the stock they add up to.
 *
 * Unlike warehouses and gates — which are company-wide structure and live in
 * `warehouses` / `warehouse_entrances` — these two are module-specific, because
 * `productid` means a different thing per module: bil.products here, bpl_products
 * for BPL. A shared stock table would need a discriminator to say which master a
 * product id belongs to, and would be wrong the first time someone forgot it.
 * BPL gets its own pair, over the same warehouses and entrances.
 *
 * WHAT THIS REPLACES
 *   store_entrance      -> finished_goods_warehouse_receipts
 *   storebundle         -> finished_goods_warehouse_stock (per warehouse, not
 *   storebundle_floor      one hard-coded code and three hard-coded floors)
 *
 * The gain worth the rebuild: **stock is now derivable**. Every bundle in it
 * arrived on a receipt, so the totals are exactly SUM(bundles) per warehouse per
 * product, and `bil:reconcile-fg-stock` can prove or repair them. The legacy
 * totals were not — nothing recorded which floor a bundle had been counted onto,
 * so drift was permanent and undetectable.
 *
 * gds stops writing `store_entrance`, `storebundle` and `storebundle_floor`
 * entirely. The legacy app still owns them; from this cut-over the two diverge
 * for anything received through gds. See docs/DEPLOYMENT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $core = Schema::connection('core');

        $core->create('finished_goods_warehouse_receipts', function (Blueprint $table) {
            $table->id();
            // One receipt per pallet, as in the legacy table.
            $table->string('barcode', 64)->unique();
            $table->unsignedBigInteger('entrance_id')->index();
            // Denormalised off the entrance so a receipt keeps pointing at the
            // right stock even if the gate later moves warehouse or is retired.
            $table->unsignedBigInteger('warehouse_id')->index();
            // bil.products.productid — cross-database, so a plain indexed column.
            $table->unsignedInteger('productid')->index();
            $table->unsignedInteger('bundles');
            // A real DATE, unlike the legacy varchar `Y/m/d`: new table, no
            // legacy reader to stay string-compatible with.
            $table->date('date_of_entrance')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('username')->nullable();
            $table->timestamps();
        });

        $core->create('finished_goods_warehouse_stock', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->index();
            $table->unsignedInteger('productid')->index();
            // Bundles. Signed: a correction can legitimately take a product
            // negative, and hiding that would mask a real problem.
            $table->integer('bundles')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'productid'], 'fg_stock_warehouse_product_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('finished_goods_warehouse_stock');
        Schema::connection('core')->dropIfExists('finished_goods_warehouse_receipts');
    }
};
