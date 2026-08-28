<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `productname` / `productcode` from finished_goods_warehouse_stock.
 *
 * 2026_08_12_150000 put them there so the Stock grid could sort and search by
 * product, on the stated grounds that "stock lives on `core` and products on
 * `bil`, and MySQL cannot join two connections in one statement". The first
 * half stopped being true when 2026_08_28_150000 moved the stock table into
 * `bil`; the second half was never true — Laravel *connections* cannot be
 * joined, MySQL *schemas* on one server can.
 *
 * So the grid joins `products` now, and these two stop being a cache that every
 * stock movement and `bil:reconcile-fg-stock` had to keep in step. They were in
 * step — all 167 rows matched the master when this was written — which is
 * exactly why nothing is lost by deriving them instead.
 *
 * NOT the same call as `stock_transfer_lines.product_code` / `product_name`,
 * which stay: a transfer line records what was SENT, and a later rename must
 * not rewrite history. A stock row is current state and should follow the
 * master.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('bil')->hasColumn('finished_goods_warehouse_stock', 'productname')) {
            return;
        }

        // MySQL drops an index whose only column goes with it, so the
        // productname index needs no separate statement.
        Schema::connection('bil')->table('finished_goods_warehouse_stock', function (Blueprint $table) {
            $table->dropColumn(['productname', 'productcode']);
        });
    }

    public function down(): void
    {
        if (Schema::connection('bil')->hasColumn('finished_goods_warehouse_stock', 'productname')) {
            return;
        }

        Schema::connection('bil')->table('finished_goods_warehouse_stock', function (Blueprint $table) {
            $table->string('productname')->nullable()->after('productid')->index();
            $table->string('productcode', 64)->nullable()->after('productname');
        });

        // Refill from the master, or the restored columns would come back empty
        // and the code that reads them would show every row as unnamed.
        DB::connection('bil')->statement(
            'UPDATE `finished_goods_warehouse_stock` s
             JOIN `products` p ON p.productid = s.productid
             SET s.productname = p.productname, s.productcode = p.productcode'
        );
    }
};
