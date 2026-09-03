<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen the two date indexes on `sales_loading` so an aggregate can be answered
 * from the index instead of the rows.
 *
 *   sl_loading_date_idx  (dateofloading)  ->  (dateofloading, sod_id, quantityloaded, status, barcode)
 *   sl_status_idx        (status)         ->  (status, sod_id, quantityloaded)
 *
 * NOT two extra indexes — the same two, each given the columns everything that
 * uses them then goes on to read. `sales_loading` is the busiest table in the
 * module and each index is a write cost, so nothing here is additive: both
 * replacements keep the original column as their leading prefix, so every query
 * that used the narrow index still uses the wide one.
 *
 * The gap they close is the difference between counting rows and reading them.
 * Over twelve months (about 50,000 loadings):
 *
 *   SELECT COUNT(*)             ... WHERE dateofloading BETWEEN     58ms
 *   SELECT SUM(quantityloaded)  ... WHERE dateofloading BETWEEN    535ms
 *
 * The count is answered off the index; the sum was 50,000 random primary-key
 * lookups to fetch one integer each. Sales Statistics runs eight to thirteen of
 * those per tab, which is why a twelve-month view took ten seconds while a
 * thirty-day view took a quarter of one.
 *
 * The trailing columns are the rest of what a dashboard tile asks of a loading:
 * `sod_id` for the join to the order line (which is how the Sold vs Free split
 * is made), `status` for what is still out, and `barcode` for the load count.
 * Together they let the busiest query on the page — every loading figure in one
 * pass — be answered without reading a single row of the table.
 *
 * `sl_status_idx` is redundant even before this: `sl_status_loadnumber_idx`
 * already leads with `status`. Rebuilding it as the wider one costs nothing and
 * gives the delivered-bundles aggregates the same treatment.
 *
 * ALGORITHM=INPLACE, LOCK=NONE — no rows rewritten, writes keep flowing.
 */
return new class extends Migration
{
    private const WIDE = [
        'sl_loading_date_idx' => '(`dateofloading`, `sod_id`, `quantityloaded`, `status`, `barcode`)',
        'sl_status_idx' => '(`status`, `sod_id`, `quantityloaded`)',
    ];

    private const NARROW = [
        'sl_loading_date_idx' => '(`dateofloading`)',
        'sl_status_idx' => '(`status`)',
    ];

    public function up(): void
    {
        $this->rebuild(self::WIDE);
    }

    public function down(): void
    {
        $this->rebuild(self::NARROW);
    }

    private function rebuild(array $indexes): void
    {
        foreach ($indexes as $name => $columns) {
            if ($this->columnsOf($name) === $columns) {
                continue;
            }

            if ($this->columnsOf($name) !== null) {
                DB::connection('bil')->statement(
                    "ALTER TABLE `sales_loading` DROP INDEX `{$name}`, ALGORITHM=INPLACE, LOCK=NONE"
                );
            }

            DB::connection('bil')->statement(
                "ALTER TABLE `sales_loading` ADD INDEX `{$name}` {$columns}, ALGORITHM=INPLACE, LOCK=NONE"
            );
        }
    }

    /** The index's columns as a "(`a`, `b`)" string, or null if it isn't there. */
    private function columnsOf(string $name): ?string
    {
        $rows = collect(DB::connection('bil')->select('SHOW INDEX FROM `sales_loading`'))
            ->where('Key_name', $name)->sortBy('Seq_in_index');

        if ($rows->isEmpty()) {
            return null;
        }

        return '(' . $rows->map(fn ($r) => '`' . $r->Column_name . '`')->implode(', ') . ')';
    }
};
