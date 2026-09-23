<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every BPL transporter a code — the BIL `sales_transporters` change
 * (2026_08_28_130000), repeated for `bpl.bpl_transporters`.
 *
 * Same shape for the same reasons: 8 random digits in 10000000-99999999, so a
 * code is always eight characters even after Excel reads it as a number, and
 * it says nothing about how many rows exist the way an id does.
 *
 * NULLABLE, because the legacy `bpl_transporters.php` INSERT names only
 * `transportername`. The UNIQUE index goes on after the backfill, so the
 * generated codes cannot collide before there is an index to catch it.
 *
 * The `bil.bpl_transporters` compat view was created with an explicit column
 * list (id, transportername), so it is frozen at the old shape and would never
 * show the new column. It is recreated here — the trap every column addition to
 * a viewed table has to remember.
 */
return new class extends Migration
{
    protected const INDEX = 'bt_code_unq';

    public function up(): void
    {
        if (! Schema::connection('bpl')->hasColumn('bpl_transporters', 'transportercode')) {
            Schema::connection('bpl')->table('bpl_transporters', function (Blueprint $table) {
                $table->string('transportercode', 8)->nullable()->after('id');
            });
        }

        $db = DB::connection('bpl');

        $taken = array_flip(array_filter($db->table('bpl_transporters')->pluck('transportercode')->all()));

        $ids = $db->table('bpl_transporters')
            ->where(fn ($q) => $q->whereNull('transportercode')->orWhere('transportercode', ''))
            ->pluck('id');

        foreach ($ids as $id) {
            do {
                $code = (string) random_int(10000000, 99999999);
            } while (isset($taken[$code]));

            $taken[$code] = true;
            $db->table('bpl_transporters')->where('id', $id)->update(['transportercode' => $code]);
        }

        if (! $this->hasIndex(self::INDEX)) {
            $db->statement('ALTER TABLE `bpl_transporters` ADD UNIQUE INDEX `' . self::INDEX . '` (`transportercode`)');
        }

        $this->bilView(['id', 'transportercode', 'transportername']);
    }

    public function down(): void
    {
        $this->bilView(['id', 'transportername']);

        if ($this->hasIndex(self::INDEX)) {
            DB::connection('bpl')->statement('ALTER TABLE `bpl_transporters` DROP INDEX `' . self::INDEX . '`');
        }

        if (Schema::connection('bpl')->hasColumn('bpl_transporters', 'transportercode')) {
            Schema::connection('bpl')->table('bpl_transporters', function (Blueprint $table) {
                $table->dropColumn('transportercode');
            });
        }
    }

    private function bilView(array $columns): void
    {
        $select = implode(', ', array_map(
            fn ($c) => "`bpl`.`bpl_transporters`.`{$c}` AS `{$c}`",
            $columns
        ));

        DB::connection('bil')->statement(
            "CREATE OR REPLACE VIEW `bil`.`bpl_transporters` AS SELECT {$select} FROM `bpl`.`bpl_transporters`"
        );
    }

    protected function hasIndex(string $name): bool
    {
        return DB::connection('bpl')
            ->select('SHOW INDEX FROM `bpl_transporters` WHERE Key_name = ?', [$name]) !== [];
    }
};
