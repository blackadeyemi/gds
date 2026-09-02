<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every transporter a code.
 *
 * `sales_transporters` has only `id` and `transportername` — the legacy screen
 * never had anything else. A code gives the haulier a stable reference that is
 * not the database id: ids leak how many rows exist, and mean nothing on a
 * printed waybill or over the phone.
 *
 * 8 digits, matching `sales_customers.customercode` (41181366) — the shape
 * everyone in sales already reads off paper.
 *
 * Codes are minted here for the 143 existing rows and by the model thereafter;
 * the UNIQUE index is the real guarantee, added once every row has one. Range
 * 10000000-99999999 so a code is always eight characters whether read as text
 * or as a number — a leading zero would become a 7-digit number the first time
 * someone opened the export in Excel.
 *
 * The column stays NULLABLE: the legacy `sales_transporters.php` INSERT names
 * only `transportername`, and a NOT NULL with no default would break it. A row
 * created there simply has no code until gds gives it one.
 */
return new class extends Migration
{
    protected const INDEX = 'st_code_unq';

    public function up(): void
    {
        if (! Schema::connection('bil')->hasColumn('sales_transporters', 'transportercode')) {
            Schema::connection('bil')->table('sales_transporters', function (Blueprint $table) {
                $table->string('transportercode', 8)->nullable()->after('id');
            });
        }

        $db = DB::connection('bil');

        // Held in memory so the backfilled codes cannot collide with each other
        // before the unique index exists to catch it.
        $taken = array_flip(array_filter($db->table('sales_transporters')->pluck('transportercode')->all()));

        $ids = $db->table('sales_transporters')
            ->where(fn ($q) => $q->whereNull('transportercode')->orWhere('transportercode', ''))
            ->pluck('id');

        foreach ($ids as $id) {
            do {
                $code = (string) random_int(10000000, 99999999);
            } while (isset($taken[$code]));

            $taken[$code] = true;
            $db->table('sales_transporters')->where('id', $id)->update(['transportercode' => $code]);
        }

        if (! $this->hasIndex(self::INDEX)) {
            $db->statement('ALTER TABLE `sales_transporters` ADD UNIQUE INDEX `' . self::INDEX . '` (`transportercode`)');
        }
    }

    public function down(): void
    {
        if ($this->hasIndex(self::INDEX)) {
            DB::connection('bil')->statement('ALTER TABLE `sales_transporters` DROP INDEX `' . self::INDEX . '`');
        }

        if (Schema::connection('bil')->hasColumn('sales_transporters', 'transportercode')) {
            Schema::connection('bil')->table('sales_transporters', function (Blueprint $table) {
                $table->dropColumn('transportercode');
            });
        }
    }

    protected function hasIndex(string $name): bool
    {
        return DB::connection('bil')
            ->select('SHOW INDEX FROM `sales_transporters` WHERE Key_name = ?', [$name]) !== [];
    }
};
