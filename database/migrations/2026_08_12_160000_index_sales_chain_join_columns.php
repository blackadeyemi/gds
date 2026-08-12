<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index the columns the sales chain is actually joined on.
 *
 * The finished-goods Stock detail modal walks order → loading → delivery →
 * waybill, plus both return paths, for one product. Every one of those hops was
 * a full table scan: none of the join columns carried an index, and
 * `sales_order_details.productid` alone meant scanning 598k rows. The modal
 * took 4.3s to build, 1.7s of it in that one query.
 *
 * Additive and index-only — no column or data changes — following the same
 * pattern as the earlier `rawmaterials_warehouse_exit` indexes. The legacy
 * screens join on exactly these columns too, so they get faster with it.
 *
 * Guarded by name so a re-run is a no-op, and each is attempted separately: a
 * table that already has one should not stop the rest.
 */
return new class extends Migration
{
    /** table => [index name => column] */
    private const INDEXES = [
        'sales_order_details' => ['sod_productid_idx' => 'productid', 'sod_orderid_idx' => 'orderid'],
        'sales_loading' => ['sl_sod_id_idx' => 'sod_id', 'sl_loadnumber_idx' => 'loadnumber'],
        'sales_return' => ['sr_sod_id_idx' => 'sod_id'],
        'sales_loading_return' => ['slr_sod_id_idx' => 'sod_id'],
        'sales_delivery' => ['sd_loadnumber_idx' => 'loadnumber', 'sd_deliverynumber_idx' => 'deliverynumber'],
        'sales_waybill' => ['sw_deliverynumber_idx' => 'deliverynumber'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $column) {
                if ($this->hasIndex($table, $name)) {
                    continue;
                }
                DB::connection('bil')->statement(
                    "ALTER TABLE `{$table}` ADD INDEX `{$name}` (`{$column}`)"
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if ($this->hasIndex($table, $name)) {
                    DB::connection('bil')->statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
            }
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(DB::connection('bil')->select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($i) => $i->Key_name === $name);
    }
};
