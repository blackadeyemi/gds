<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `sales_waybill.transportcost` from FLOAT to DECIMAL(12,2).
 *
 * It is a money column stored as single-precision float, which holds about
 * seven significant digits — so ₦125,000.50 comes back as ₦125,000 and the 50
 * kobo is gone with no error anywhere. That is not theoretical: 17,907 of the
 * 54,894 rows carry a fraction, so kobo are entered in practice, and the
 * largest cost on record is ₦2,500,000, which needs nine digits to hold to the
 * kobo.
 *
 * The float noise is visible in what is already stored: 91197.7969 for a figure
 * someone typed as 91,197.80, and 16146.4004 for 16,146.40.
 *
 * ⚠️ IT REWRITES VALUES, and that part is not reversible. 13,043 rows round to
 * the two decimals they were meant to have. The whole table moves by 12 kobo
 * across ₦7.38 billion — the rounding recovers what was typed rather than
 * changing it — but take a dump first if this is production.
 *
 * DECIMAL(12,2) reaches ₦9,999,999,999.99, four thousand times the largest cost
 * ever recorded, and the legacy app keeps working: it writes the value as a
 * string in an INSERT and reads it back as one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->type() === 'float') {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_waybill` MODIFY `transportcost` DECIMAL(12,2) NOT NULL'
            );
        }
    }

    public function down(): void
    {
        // Restores the TYPE. The precision lost to rounding on the way up
        // cannot come back, and would not be wanted if it could.
        if (str_starts_with($this->type(), 'decimal')) {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_waybill` MODIFY `transportcost` FLOAT NOT NULL'
            );
        }
    }

    private function type(): string
    {
        $col = DB::connection('bil')->select("SHOW COLUMNS FROM `sales_waybill` LIKE 'transportcost'");

        return $col === [] ? '' : strtolower((string) $col[0]->Type);
    }
};
