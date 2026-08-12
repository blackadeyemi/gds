<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How often a product has been ordered lately, on the stock row.
 *
 * "How many times was this ordered in the last 90 days" is the question that
 * turns a stock list into a decision: a big pile of something nobody orders is
 * a different problem from a small pile of something ordered daily.
 *
 * It is a column rather than a computed value for the same reason
 * `productname` is: orders live on `bil` and stock on `core`, so a sortable
 * column has to exist in the same table the sort runs against.
 *
 * Refreshed by `bil:refresh-fg-order-frequency` — worth running nightly, since
 * the window slides. `orders_counted_at` records when, so a stale figure is
 * visible rather than silently wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('finished_goods_warehouse_stock', function (Blueprint $table) {
            // Distinct sales orders containing this product in the window.
            $table->unsignedInteger('orders_90d')->default(0)->after('bundles')->index();
            // Total quantity on those orders — "often" and "a lot" are
            // different signals and a product can be one without the other.
            $table->integer('ordered_qty_90d')->default(0)->after('orders_90d');
            $table->timestamp('orders_counted_at')->nullable()->after('ordered_qty_90d');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('finished_goods_warehouse_stock', function (Blueprint $table) {
            $table->dropColumn(['orders_90d', 'ordered_qty_90d', 'orders_counted_at']);
        });
    }
};
