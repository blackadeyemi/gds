<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index `productid` on the three pallet tables.
 *
 * "Does this product have any history?" is now asked on every render of the
 * Finished Goods Products grid, and none of these carried an index on
 * `productid` — so each question was a full scan of 1.2M rows. Guarding six
 * rows took 7 seconds.
 *
 * Additive and index-only. The legacy reports group and filter by product on
 * exactly these tables too, so they get faster with it.
 */
return new class extends Migration
{
    private const INDEXES = [
        'factory_conversion' => 'fc_productid_idx',
        'factory_exit' => 'fe_productid_idx',
        'store_entrance' => 'se_productid_idx',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $name) {
            if (! $this->hasIndex($table, $name)) {
                DB::connection('bil')->statement(
                    "ALTER TABLE `{$table}` ADD INDEX `{$name}` (`productid`)"
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $name) {
            if ($this->hasIndex($table, $name)) {
                DB::connection('bil')->statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(DB::connection('bil')->select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($i) => $i->Key_name === $name);
    }
};
