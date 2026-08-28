<?php

namespace Modules\Bil\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Bil\Support\RawMaterialsStock;

/**
 * Prove — or repair — `raw_materials_warehouse_stock` against the barcodes.
 *
 * The twin of `bil:reconcile-fg-stock`. An item is in store exactly while its
 * `rawmaterials_warehouse_entry` row has no status, so the totals are always a
 * straight rollup and drift is detectable rather than permanent.
 *
 * This is the whole point of replacing `rawmaterials_stock`: that aggregate had
 * drifted to roughly 8.5x reality, and the only way to find out was to write a
 * one-off command and compare by hand.
 */
class ReconcileRawMaterialsStock extends Command
{
    protected $signature = 'bil:reconcile-rm-stock
                            {--fix : Write the corrections instead of only reporting}
                            {--warehouse= : Limit to one warehouse id}';

    protected $description = 'Check raw_materials_warehouse_stock against the in-store barcodes';

    public function handle(): int
    {
        $warehouseId = $this->option('warehouse') ? (int) $this->option('warehouse') : null;
        $drift = RawMaterialsStock::drift($warehouseId);

        if ($drift === []) {
            $this->info('Stock agrees with the barcodes — nothing to fix.');

            return self::SUCCESS;
        }

        $warehouses = DB::connection('core')->table('warehouses')->pluck('name', 'id');
        $products = DB::connection('bil')->table('rawmaterials_products')
            ->whereIn('id', array_column($drift, 'productid'))->pluck('productname', 'id');

        $this->table(
            ['Warehouse', 'Product', 'Qty (stored)', 'Qty (actual)', 'Weight (stored)', 'Weight (actual)'],
            array_map(fn ($d) => [
                $warehouses[$d['warehouse_id']] ?? ('#' . $d['warehouse_id']),
                $products[$d['productid']] ?? ('#' . $d['productid']),
                $d['stored_quantity'],
                $d['expected_quantity'],
                number_format($d['stored_weight'], 2),
                number_format($d['expected_weight'], 2),
            ], $drift)
        );

        if (! $this->option('fix')) {
            $this->warn(count($drift) . ' line(s) disagree. Re-run with --fix to correct them.');

            return self::FAILURE;
        }

        DB::connection('bil')->transaction(function () use ($drift) {
            foreach ($drift as $d) {
                DB::connection('bil')->table('raw_materials_warehouse_stock')->updateOrInsert(
                    ['warehouse_id' => $d['warehouse_id'], 'productid' => $d['productid']],
                    [
                        'quantity' => $d['expected_quantity'],
                        'weight' => $d['expected_weight'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        });

        $this->info(count($drift) . ' line(s) corrected.');

        return self::SUCCESS;
    }
}
