<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index the two columns the Delivery screen reaches `sales_delivery` by.
 *
 * The legacy schema indexed `loadnumber` and `deliverynumber` — the identity
 * columns the waybill chain joins on — but not the date, and not the barcode
 * a delivery is looked up by. Every date-scoped read therefore scanned all
 * 129,583 rows. Measured on this database:
 *
 *   a day's deliveries   WHERE dateofdelivery = ?              216ms
 *   the next number      MAX(deliverynumber) WHERE date = ?    116ms
 *   one delivery         WHERE barcode = ?                     193ms
 *
 * The first two are the same index. `deliverynumber` is the second column so
 * MAX() is read straight off the tail of the range rather than aggregated, and
 * the day list comes back in the order it is displayed in.
 *
 * Both are selective enough to be worth it: the busiest day is 579 rows
 * (0.45%), and a barcode identifies one delivery — two barcodes in the whole
 * table repeat, both from the double-confirm bug the new screen refuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->missing('sd_date_number_idx')) {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_delivery`
                 ADD INDEX `sd_date_number_idx` (`dateofdelivery`, `deliverynumber`),
                 ALGORITHM=INPLACE, LOCK=NONE'
            );
        }

        if ($this->missing('sd_barcode_idx')) {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_delivery` ADD INDEX `sd_barcode_idx` (`barcode`),
                 ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    public function down(): void
    {
        foreach (['sd_date_number_idx', 'sd_barcode_idx'] as $name) {
            if (! $this->missing($name)) {
                DB::connection('bil')->statement(
                    "ALTER TABLE `sales_delivery` DROP INDEX `{$name}`, ALGORITHM=INPLACE, LOCK=NONE"
                );
            }
        }
    }

    private function missing(string $name): bool
    {
        return ! collect(DB::connection('bil')->select('SHOW INDEX FROM `sales_delivery`'))
            ->contains(fn ($i) => $i->Key_name === $name);
    }
};
