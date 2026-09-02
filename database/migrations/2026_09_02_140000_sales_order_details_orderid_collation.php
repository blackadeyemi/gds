<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `sales_order_details.orderid` from utf8mb3 to latin1 — the collation the rest
 * of the sales chain already uses.
 *
 * The table itself is latin1_swedish_ci; only this ONE column was overridden to
 * utf8mb3, and it is the column every sales query joins on. The mismatch makes
 * the join one-directional: MySQL can widen latin1 to utf8mb3, so driving from
 * `sales_order` into the details works, but the reverse — details back to the
 * order, which is how loading, delivery, return and waybill all reach the
 * customer — cannot use `sales_order.orderid` at all. The optimizer's answer is
 * to abandon the date range and full-scan 97k orders instead:
 *
 *   loading -> details -> order, one week, 50 rows      20,091ms
 *   the same with CONVERT(... USING latin1) forced          33ms
 *   loading -> ... -> order, COUNT over one year        11,974ms
 *
 * That is the whole reason the existing Loading and Delivery screens fetch their
 * orders in a second indexed query instead of joining — a workaround that a
 * report cannot use, because a report groups and totals BY customer.
 *
 * Safe to convert: every value is a numeric order id (534,191 rows, zero of them
 * outside ASCII, checked with `orderid <> CONVERT(orderid USING latin1)`), every
 * one of them matches a `sales_order` row, and no view reads the table. latin1
 * represents all of them exactly.
 *
 * ⚠️ A charset change is a table REBUILD: ALGORITHM=COPY, and writes are blocked
 * while it runs (reads are not). At 534k rows / 22MB that is seconds, but it is
 * not instant — run it when the cageroom is not loading, and make sure nothing
 * is holding a metadata lock on the table first (`SHOW FULL PROCESSLIST`), or
 * the ALTER queues behind it and takes the write lock with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->charset() === 'utf8mb3') {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_order_details` MODIFY `orderid`
                 VARCHAR(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if ($this->charset() === 'latin1') {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_order_details` MODIFY `orderid`
                 VARCHAR(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL'
            );
        }
    }

    private function charset(): string
    {
        $row = DB::connection('bil')->selectOne(
            "SELECT CHARACTER_SET_NAME cs FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_order_details'
               AND COLUMN_NAME = 'orderid'"
        );

        return (string) ($row->cs ?? '');
    }
};
