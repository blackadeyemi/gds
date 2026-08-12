<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the product name and code on each finished-goods stock row.
 *
 * Stock lives on `core` and products on `bil`, and MySQL cannot join two
 * connections in one statement — so a Stock grid that sorts or searches by
 * product name has nowhere to do it. Resolving names in PHP works for display
 * (the reports do exactly that) but not for sorting, because the sort has to
 * happen in SQL before the page is taken.
 *
 * So the name is denormalised, following the convention already in this
 * codebase for `products.mach` and `products.hardrollsource`: a readable copy
 * kept beside the id, refreshed whenever the row moves and by
 * `bil:reconcile-fg-stock`. The id remains the truth; these two are for
 * ordering and searching.
 *
 * There are only a few hundred stock rows, so keeping them in step is cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('finished_goods_warehouse_stock', function (Blueprint $table) {
            $table->string('productname')->nullable()->after('productid')->index();
            $table->string('productcode', 64)->nullable()->after('productname');
        });

        // Seed whatever stock already exists.
        $products = DB::connection('bil')->table('products')
            ->get(['productid', 'productname', 'productcode'])->keyBy('productid');

        foreach (DB::connection('core')->table('finished_goods_warehouse_stock')->get(['id', 'productid']) as $row) {
            $p = $products[$row->productid] ?? null;
            DB::connection('core')->table('finished_goods_warehouse_stock')
                ->where('id', $row->id)
                ->update([
                    'productname' => $p->productname ?? null,
                    'productcode' => $p->productcode ?? null,
                ]);
        }
    }

    public function down(): void
    {
        Schema::connection('core')->table('finished_goods_warehouse_stock', function (Blueprint $table) {
            $table->dropColumn(['productname', 'productcode']);
        });
    }
};
