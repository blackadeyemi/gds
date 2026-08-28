<?php

namespace Modules\Bil\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Bil\Support\FinishedGoodsStock;

/**
 * Give finished-goods stock an opening balance, so the Stock page is usable
 * before anyone keys 167 products in by hand.
 *
 * TWO SOURCES, AND THEY DISAGREE — the command shows both so the choice is made
 * with the numbers visible:
 *
 *   --from=legacy    (default) the legacy `storebundle` figure. Imperfect, but
 *                    it is the number the business is using today, so gds
 *                    starts out agreeing with what people already see.
 *
 *   --from=computed  received - loaded + unloaded + returned, all-time.
 *
 * The computed figure is NOT a stock balance and should be treated with
 * suspicion: measured across the live data it comes to ~7.8M bundles, roughly
 * 390 days of production sitting in the warehouse, and 13% of products go
 * NEGATIVE. Receipts and loadings do not balance on their own — damage,
 * write-offs and `store_adjustment` are all outside the arithmetic. It is
 * offered because it is derived from movements rather than from an accumulator
 * that has drifted, which makes it worth comparing against.
 *
 * Either way the balance is written as an ADJUSTMENT, not by setting `bundles`
 * directly, so the opening figure carries an author and a reason and stock
 * stays provable. Re-running replaces the previous opening balance with a
 * further adjustment rather than doubling it.
 */
class SeedFinishedGoodsStock extends Command
{
    protected $signature = 'bil:seed-fg-stock
                            {--from=legacy : legacy (storebundle) or computed (movements)}
                            {--apply : Write the adjustments (without this it only reports)}
                            {--warehouse= : Warehouse id; defaults to the first FG warehouse}';

    protected $description = 'Set finished-goods opening stock from the legacy total or from movements';

    public function handle(): int
    {
        $source = (string) $this->option('from');
        if (! in_array($source, ['legacy', 'computed'], true)) {
            $this->error('--from must be "legacy" or "computed".');

            return self::FAILURE;
        }

        $warehouseId = $this->option('warehouse')
            ? (int) $this->option('warehouse')
            : FinishedGoodsStock::loadingWarehouseId();

        if (! $warehouseId) {
            $this->error('No finished-goods warehouse. Create one in Admin > Warehouses first.');

            return self::FAILURE;
        }

        $legacy = $this->legacyTotals();
        $computed = $this->computedTotals();
        $names = DB::connection('bil')->table('products')->pluck('productname', 'productid');

        $target = $source === 'legacy' ? $legacy : $computed;
        if ($target === []) {
            $this->error('Nothing to seed from ' . $source . '.');

            return self::FAILURE;
        }

        // Show both, so the difference is impossible to miss.
        $rows = [];
        foreach ($target as $productid => $_) {
            $rows[] = [
                'name' => $names[$productid] ?? ('#' . $productid),
                'legacy' => $legacy[$productid] ?? 0,
                'computed' => $computed[$productid] ?? 0,
            ];
        }
        usort($rows, fn ($a, $b) => $b[$source] <=> $a[$source]);

        $this->table(
            ['Product', 'Legacy (storebundle)', 'Computed (movements)'],
            array_map(fn ($r) => [
                substr($r['name'], 0, 40),
                number_format($r['legacy']),
                number_format($r['computed']),
            ], array_slice($rows, 0, 15))
        );
        if (count($rows) > 15) {
            $this->line('  … and ' . (count($rows) - 15) . ' more.');
        }

        $negatives = count(array_filter($computed, fn ($v) => $v < 0));
        $this->newLine();
        $this->line(sprintf('legacy total   %15s bundles across %d products',
            number_format(array_sum($legacy)), count($legacy)));
        $this->line(sprintf('computed total %15s bundles across %d products (%d negative)',
            number_format(array_sum($computed)), count($computed), $negatives));

        if ($source === 'computed') {
            $this->newLine();
            $this->warn('The computed figure is a net of movements, not a counted balance.');
            $this->line('Damage, write-offs and store_adjustment are outside it, which is why');
            $this->line('products can come out negative. Prefer --from=legacy unless you have');
            $this->line('a reason not to.');
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Dry run — re-run with --apply to write the opening balances.');

            return self::SUCCESS;
        }

        $reason = 'Opening balance (' . $source . ', ' . now()->format('d M Y') . ')';
        $written = 0;

        $this->newLine();
        $bar = $this->output->createProgressBar(count($target));
        $bar->start();

        foreach ($target as $productid => $bundles) {
            // setTo() records the difference from whatever is there now, so a
            // re-run corrects rather than doubles.
            FinishedGoodsStock::setTo($warehouseId, (int) $productid, (int) $bundles, $reason);
            $written++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info($written . ' opening balance(s) written as adjustments.');
        $this->line('Every one is on the product\'s Incoming tab, with its reason.');

        return self::SUCCESS;
    }

    /** The legacy running total — what the business sees today. */
    private function legacyTotals(): array
    {
        return DB::connection('bil')->table('storebundle')
            ->where('warehousecode', '01')
            ->whereNotNull('productid')->where('productid', '>', 0)
            ->pluck('bundles', 'productid')
            ->map(fn ($b) => (int) $b)
            ->all();
    }

    /** received − loaded + unloaded + returned, all-time. */
    private function computedTotals(): array
    {
        $bil = DB::connection('bil');
        $bil = DB::connection('bil');

        $received = $bil->table('finished_goods_warehouse_receipts')
            ->groupBy('productid')->selectRaw('productid, SUM(bundles) as b')
            ->pluck('b', 'productid');

        $sales = fn (string $table, string $column) => $bil->table($table . ' as t')
            ->join('sales_order_details as d', 't.sod_id', '=', 'd.id')
            ->groupBy('d.productid')->selectRaw("d.productid, SUM(t.{$column}) as b")
            ->pluck('b', 'productid');

        $loaded = $sales('sales_loading', 'quantityloaded');
        $unloaded = $sales('sales_loading_return', 'quantityunloaded');
        $returned = $sales('sales_return', 'quantityreturned');

        $out = [];
        foreach ($received as $productid => $bundles) {
            // 44 legacy receipts carry productid 0 — real corruption in
            // store_entrance. They are imported so the history is honest, but
            // there is no product to hold their stock against.
            if ((int) $productid <= 0) {
                continue;
            }
            $out[(int) $productid] = (int) $bundles
                - (int) ($loaded[$productid] ?? 0)
                + (int) ($unloaded[$productid] ?? 0)
                + (int) ($returned[$productid] ?? 0);
        }

        return $out;
    }
}
