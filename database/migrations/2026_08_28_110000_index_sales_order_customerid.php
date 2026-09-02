<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index `sales_order.customerid`.
 *
 * The legacy table indexes `orderid` and `dateoforder` but never the customer,
 * even though "this customer's orders" is the question the sales screens ask
 * most. Nothing noticed until the Sales Customers grid put an orders count
 * beside each row: the correlated subquery scanned all 97,291 orders once per
 * customer, so a full export/print of the 1,898-customer list took **150
 * seconds**. With the index it is milliseconds.
 *
 * Legacy benefits too — `Bil\Sales\Order::customer()` and the sales-balance and
 * invoice reports all join or filter on this column.
 *
 * Guarded by an existence check because `sales_order` is a legacy table shared
 * with the running legacy app, where the index may already have been added by
 * hand.
 */
return new class extends Migration
{
    protected const NAME = 'so_customerid_idx';

    protected function exists(): bool
    {
        return DB::connection('bil')
            ->select("SHOW INDEX FROM `sales_order` WHERE Key_name = ?", [self::NAME]) !== [];
    }

    public function up(): void
    {
        if (! $this->exists()) {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_order` ADD INDEX `' . self::NAME . '` (`customerid`)'
            );
        }
    }

    public function down(): void
    {
        if ($this->exists()) {
            DB::connection('bil')->statement('ALTER TABLE `sales_order` DROP INDEX `' . self::NAME . '`');
        }
    }
};
