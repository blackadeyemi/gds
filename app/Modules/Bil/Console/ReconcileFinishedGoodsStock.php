<?php

namespace Modules\Bil\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Bil\Support\FinishedGoodsStock;

/**
 * Prove — or repair — `finished_goods_warehouse_stock` against the receipts.
 *
 * Unlike the legacy `storebundle` totals it replaces, finished-goods stock is
 * fully derivable: every bundle in it arrived on a receipt, so the truth is
 * always SUM(bundles) per warehouse per product. That makes drift detectable
 * instead of permanent, which is the whole point of the rebuild.
 *
 * Run with no options to report; add --fix to write the corrections. Idempotent
 * and transactional either way.
 */
class ReconcileFinishedGoodsStock extends Command
{
    protected $signature = 'bil:reconcile-fg-stock
                            {--fix : Write the corrections instead of only reporting}
                            {--warehouse= : Limit to one warehouse id}';

    protected $description = 'Check finished_goods_warehouse_stock against the receipts it derives from';

    public function handle(): int
    {
        $warehouseId = $this->option('warehouse') ? (int) $this->option('warehouse') : null;
        $drift = FinishedGoodsStock::drift($warehouseId);

        if ($drift === []) {
            $this->info('Stock agrees with the receipts — nothing to fix.');

            return self::SUCCESS;
        }

        $warehouses = DB::connection('core')->table('warehouses')->pluck('name', 'id');
        $products = DB::connection('bil')->table('products')
            ->whereIn('productid', array_column($drift, 'productid'))
            ->pluck('productname', 'productid');

        $this->table(
            ['Warehouse', 'Product', 'Stored', 'Expected', 'Difference'],
            array_map(fn ($d) => [
                $warehouses[$d['warehouse_id']] ?? ('#' . $d['warehouse_id']),
                $products[$d['productid']] ?? ('#' . $d['productid']),
                $d['stored'],
                $d['expected'],
                sprintf('%+d', $d['difference']),
            ], $drift)
        );

        if (! $this->option('fix')) {
            $this->warn(count($drift) . ' line(s) disagree. Re-run with --fix to correct them.');

            return self::FAILURE;
        }

        DB::connection('bil')->transaction(function () use ($drift, $products) {
            foreach ($drift as $d) {
                DB::connection('bil')->table('finished_goods_warehouse_stock')->updateOrInsert(
                    ['warehouse_id' => $d['warehouse_id'], 'productid' => $d['productid']],
                    [
                        'bundles' => $d['expected'],
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
