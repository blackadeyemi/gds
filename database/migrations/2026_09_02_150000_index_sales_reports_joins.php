<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two composite indexes for the sales REPORTS, which walk the chain the entry
 * screens never had to: delivery -> loading, and loading -> delivery.
 *
 * `loadnumber` is a per-DAY sequence — it restarts at 1 every morning — so a
 * load is identified by (date, loadnumber) and never by the number alone. The
 * legacy schema indexed only the number, which means every hop across that pair
 * matches ten years of same-numbered loads and then throws almost all of them
 * away on the date. On the waybill report, which has to reach the customer
 * through waybill -> delivery -> loading -> order, that was the whole cost:
 *
 *   waybill report, summary by customer, one year     39,500ms
 *   the same with these two indexes                       see below
 *
 * `sales_loading (status, loadnumber)`: `status` holds the DELIVERY date on a
 * loading row, so this is the delivery side reaching back to what it delivered.
 * It supersedes the single-column `sl_status_idx` for that lookup and leaves it
 * in place for the plain range scan the Delivery report does.
 *
 * `sales_delivery (dateofdelivery, loadnumber)`: the other direction — a
 * loading row asking whether it was delivered, which is how the Loading and
 * Delivery reports show a load's delivery barcode.
 *
 * Both are pure additions: ALGORITHM=INPLACE, LOCK=NONE, no rows rewritten.
 */
return new class extends Migration
{
    private const ADD = [
        'sales_loading' => ['sl_status_loadnumber_idx' => '(`status`, `loadnumber`)'],
        'sales_delivery' => ['sd_date_loadnumber_idx' => '(`dateofdelivery`, `loadnumber`)'],
    ];

    public function up(): void
    {
        foreach (self::ADD as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if ($this->missing($table, $name)) {
                    DB::connection('bil')->statement(
                        "ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns},
                         ALGORITHM=INPLACE, LOCK=NONE"
                    );
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::ADD as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (! $this->missing($table, $name)) {
                    DB::connection('bil')->statement(
                        "ALTER TABLE `{$table}` DROP INDEX `{$name}`, ALGORITHM=INPLACE, LOCK=NONE"
                    );
                }
            }
        }
    }

    private function missing(string $table, string $name): bool
    {
        return ! collect(DB::connection('bil')->select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($i) => $i->Key_name === $name);
    }
};
