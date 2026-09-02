<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index `sales_loading.status`.
 *
 * `status` is not a flag — it holds the date a load was closed off — and NULL
 * means the load is still open. Only 304 of 583,096 rows are NULL (0.05%), and
 * that tiny slice is the entire working set of the Loading screen: the open
 * queue, and the guard on every correction and return.
 *
 * Unindexed, listing it scanned the whole table through four joins and took
 * ~1.2s. The same shape as factory_conversion.status, indexed for the same
 * reason: a heavily skewed column whose rare value is the one always asked for.
 */
return new class extends Migration
{
    private const NAME = 'sl_status_idx';

    public function up(): void
    {
        if ($this->missing()) {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_loading` ADD INDEX `' . self::NAME . '` (`status`), ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    public function down(): void
    {
        if (! $this->missing()) {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_loading` DROP INDEX `' . self::NAME . '`, ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    private function missing(): bool
    {
        return ! collect(DB::connection('bil')->select('SHOW INDEX FROM `sales_loading`'))
            ->contains(fn ($i) => $i->Key_name === self::NAME);
    }
};
