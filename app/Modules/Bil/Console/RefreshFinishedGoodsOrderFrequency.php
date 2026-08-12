<?php

namespace Modules\Bil\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recount how often each product was ordered in the recent window.
 *
 * The window slides, so this is worth running nightly. `orders_counted_at` on
 * each row records when it last ran, and the Stock page shows the age — a
 * figure quietly going stale is worse than an obviously old one.
 *
 * `sales_order.dateoforder` is a legacy `Y/m/d` varchar, which compares
 * correctly as a string, so the window is applied without parsing.
 *
 * The order header is read separately from its details rather than joined:
 * `sales_order_details.orderid` is utf8mb3 and `sales_order.orderid` is latin1,
 * and MySQL cannot use an index across that mismatch — the same trap that made
 * the Stock movement modal take 1.6s in one query.
 */
class RefreshFinishedGoodsOrderFrequency extends Command
{
    protected $signature = 'bil:refresh-fg-order-frequency
                            {--days=90 : Size of the window}';

    protected $description = 'Recount orders per product over the recent window, onto the stock rows';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days)->format('Y/m/d');

        // Orders placed in the window. Read first, then their details — see the
        // collation note above.
        $orderIds = DB::connection('bil')->table('sales_order')
            ->where('dateoforder', '>=', $since)
            ->pluck('orderid');

        $this->line(number_format($orderIds->count()) . ' order(s) since ' . $since . '.');

        $counts = [];
        if ($orderIds->isNotEmpty()) {
            DB::connection('bil')->table('sales_order_details')
                ->whereIn('orderid', $orderIds)
                ->groupBy('productid')
                // COUNT(DISTINCT orderid): a product listed twice on one order
                // was ordered once, not twice.
                ->selectRaw('productid, COUNT(DISTINCT orderid) as orders, SUM(quantityordered) as qty')
                ->get()
                ->each(function ($r) use (&$counts) {
                    $counts[(int) $r->productid] = [
                        'orders' => (int) $r->orders,
                        'qty' => (int) $r->qty,
                    ];
                });
        }

        $now = now();
        $updated = 0;

        // Every stock row is stamped, including the ones with no orders — a
        // zero that was checked is information; a zero that was never counted
        // is not.
        foreach (DB::connection('core')->table('finished_goods_warehouse_stock')->get(['id', 'productid']) as $row) {
            $c = $counts[(int) $row->productid] ?? ['orders' => 0, 'qty' => 0];
            DB::connection('core')->table('finished_goods_warehouse_stock')
                ->where('id', $row->id)
                ->update([
                    'orders_90d' => $c['orders'],
                    'ordered_qty_90d' => $c['qty'],
                    'orders_counted_at' => $now,
                    'updated_at' => $now,
                ]);
            $updated++;
        }

        $this->info($updated . ' stock row(s) recounted over ' . $days . ' days.');

        return self::SUCCESS;
    }
}
