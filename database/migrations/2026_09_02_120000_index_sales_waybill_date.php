<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index `dateofwaybill` on `sales_waybill`.
 *
 * Same hole as every other table in this chain: the legacy indexed the identity
 * column the delivery joins on (`deliverynumber`) and not the date, so every
 * date-scoped read scanned all 54,894 rows — 28ms to count a day's waybills,
 * 50ms to list them. The Waybill screen does both on every render, and again
 * for each date it looks back over to find where the work is.
 *
 * Selective enough: the busiest day in nine years is 731 rows (1.3%), and a
 * normal one is a few dozen.
 */
return new class extends Migration
{
    private const NAME = 'sw_date_idx';

    public function up(): void
    {
        if ($this->missing()) {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_waybill` ADD INDEX `' . self::NAME . '` (`dateofwaybill`),
                 ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    public function down(): void
    {
        if (! $this->missing()) {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_waybill` DROP INDEX `' . self::NAME . '`, ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    private function missing(): bool
    {
        return ! collect(DB::connection('bil')->select('SHOW INDEX FROM `sales_waybill`'))
            ->contains(fn ($i) => $i->Key_name === self::NAME);
    }
};
