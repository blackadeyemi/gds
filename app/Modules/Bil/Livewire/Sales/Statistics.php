<?php

namespace Modules\Bil\Livewire\Sales;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\Concerns\LegacyStatQueries;
use Modules\Bil\Support\SalesLoadings;
use Modules\Core\Livewire\StatisticsPage;

/**
 * BIL → Sales → Statistics. An analytics dashboard over the whole sales chain —
 * ordered, loaded, delivered, returned, and what the haulage cost — on the same
 * Modules\Core\Livewire\StatisticsPage base the other four modules use.
 *
 * ⚠️ EVERY FIGURE HERE IS IN BUNDLES, NOT MONEY, except the transport section.
 * There is no price anywhere in the sales schema — not on the order line, not
 * on the order, not on the product — so "free of charge" can be measured as
 * volume given away and never as revenue foregone. Said plainly on the Sold vs
 * Free tab rather than left for someone to assume.
 *
 * The dates are all legacy 'Y/m/d' varchars, so everything goes through
 * LegacyStatQueries, which buckets with LEFT(col, 7|10) and fills the gaps in
 * PHP. Its series() backticks the date column as one identifier, so the column
 * is named UNQUALIFIED and the tables it joins are unaliased — `dateofloading`
 * exists on exactly one of them, so it still resolves.
 *
 * Two things this dashboard inherits from the Sales reports, for the same
 * reasons documented there:
 *
 *   - the hop from a loading back to its order is a LEFT join. 71,972 loadings
 *     point at an order line that no longer exists (6.7m bundles, almost all
 *     2017-18 rows written before `sod_id` was populated) and an inner join
 *     drops every one of them.
 *   - `sales_delivery` is never joined to count delivered bundles. 462 (date,
 *     load number) pairs carry two delivery rows from the double-confirmation
 *     bug, and joining them doubles the load. Delivered is read off
 *     `sales_loading.status`, which holds the confirmation date.
 *
 * ── On speed ──
 * Twelve months is about 50,000 loadings, and the first cut of this page asked
 * eight to thirteen separate questions of them per tab — ten seconds to draw.
 * Two things fixed that, and both matter if a tile is ever added:
 *
 *   - ONE QUERY PER TABLE PER TAB. loadTotals() and orderTotals() answer every
 *     tile on a tab in a single pass and are memoised, rather than a query per
 *     figure over the same rows.
 *   - JOIN NO FURTHER THAN THE QUESTION NEEDS. bare -> order line -> order:
 *     bundles need none of it, the sold/free split needs the line, and only
 *     the customer and the depot need the order. Each hop costs about 500ms
 *     over a year.
 *
 * The two date indexes were also widened to carry `sod_id` and `quantityloaded`
 * (2026_09_03_100000), which is what lets a bundle total be answered from the
 * index instead of 50,000 row lookups.
 */
#[Title('Sales Statistics')]
class Statistics extends StatisticsPage
{
    use LegacyStatQueries;

    /** Memoised per-tab aggregates — see the speed note on the class. */
    protected array $memo = [];

    public function pageTitle(): string
    {
        return 'Sales Statistics';
    }

    public function pageSubtitle(): string
    {
        return 'Ordered, loaded, delivered and returned — in bundles, with what was given away.';
    }

    /**
     * No all-time option. `sales_loading` is 583k rows; a bounded range keeps
     * every tab to a handful of indexed aggregates. Twelve months already
     * buckets by month.
     */
    public function rangeOptions(): array
    {
        return [
            '7d' => ['Last 7 days', 7],
            '30d' => ['Last 30 days', 30],
            '90d' => ['Last 90 days', 90],
            '12m' => ['Last 12 months', 365],
        ];
    }

    protected function exportRouteName(): ?string
    {
        return 'bil.sales.statistics.export';
    }

    protected function exportPageKey(): ?string
    {
        return 'bil.sales.statistics';
    }

    public function sections(): array
    {
        return [
            'overview' => 'Overview',
            'foc' => 'Sold vs Free',
            'orders' => 'Orders',
            'dispatch' => 'Loading & Delivery',
            'returns' => 'Returns',
            'transport' => 'Transport',
            'customers' => 'Customers',
        ];
    }

    protected function section(string $key): array
    {
        return match ($key) {
            'foc' => $this->focSection(),
            'orders' => $this->ordersSection(),
            'dispatch' => $this->dispatchSection(),
            'returns' => $this->returnsSection(),
            'transport' => $this->transportSection(),
            'customers' => $this->customersSection(),
            default => $this->overviewSection(),
        };
    }

    protected function db()
    {
        return DB::connection('bil');
    }

    /** Run a closure once per render, keyed by name and the current range. */
    protected function once(string $key, callable $fn)
    {
        return $this->memo[$key . '|' . $this->range] ??= $fn();
    }

    /* ---------------- The spine, as builders ---------------- */

    /**
     * Loadings in the range and NOTHING ELSE — the cheapest of the three.
     *
     * Enough for bundles, loads, cagerooms and trucks; the widened
     * `(dateofloading, sod_id, quantityloaded)` index answers a bundle total
     * without touching a row. `$dateCol` is `dateofloading` for what left the
     * warehouse and `status` for what the customer confirmed.
     */
    protected function loadingsBare(string $dateCol = 'dateofloading')
    {
        [$from, $to] = $this->bounds('/');

        return $this->db()->table('sales_loading as l')
            ->when($dateCol === 'status', fn ($q) => $q->whereNotNull('l.status'))
            ->when($from, fn ($q) => $q->whereBetween('l.' . $dateCol, [$from, $to]));
    }

    /** + the order line, which is where `foc` and the product live. */
    protected function loadings(string $dateCol = 'dateofloading')
    {
        return $this->loadingsBare($dateCol)
            ->leftJoin('sales_order_details as sod', 'sod.id', '=', 'l.sod_id');
    }

    /** + the order, which is the only way to the customer and the depot. */
    protected function loadingsFull(string $dateCol = 'dateofloading')
    {
        return $this->loadings($dateCol)
            ->leftJoin('sales_order as so', 'so.orderid', '=', 'sod.orderid');
    }

    /** Order lines in the range, out to the order they belong to. */
    protected function orderLines()
    {
        [$from, $to] = $this->bounds('/');

        return $this->db()->table('sales_order as so')
            ->join('sales_order_details as sod', 'sod.orderid', '=', 'so.orderid')
            ->when($from, fn ($q) => $q->whereBetween('so.dateoforder', [$from, $to]));
    }

    protected function returns()
    {
        [$from, $to] = $this->bounds('/');

        return $this->db()->table('sales_return as r')
            ->leftJoin('sales_order_details as sod', 'sod.id', '=', 'r.sod_id')
            ->leftJoin('sales_order as so', 'so.orderid', '=', 'sod.orderid')
            ->when($from, fn ($q) => $q->whereBetween('r.dateofreturn', [$from, $to]));
    }

    protected function waybills()
    {
        [$from, $to] = $this->bounds('/');

        return $this->db()->table('sales_waybill as w')
            ->when($from, fn ($q) => $q->whereBetween('w.dateofwaybill', [$from, $to]));
    }

    /* ---------------- One pass, every figure ---------------- */

    /**
     * Everything the tiles ask of `sales_loading`, in a single pass.
     *
     * NULL `foc` (a loading whose order line has gone) counts as SOLD, which is
     * what it was: an order line that existed when the truck was filled. It has
     * to land in one half or the other, or sold + free would stop equalling
     * what left the warehouse.
     */
    protected function loadTotals(): object
    {
        return $this->once('loadTotals', fn () => $this->loadingsFull()->selectRaw(
            'SUM(CASE WHEN sod.foc = 1 THEN 0 ELSE l.quantityloaded END) as sold,
             SUM(CASE WHEN sod.foc = 1 THEN l.quantityloaded ELSE 0 END) as free,
             SUM(l.quantityloaded) as bundles,
             SUM(CASE WHEN l.status IS NULL THEN l.quantityloaded ELSE 0 END) as open_bundles,
             COUNT(DISTINCT l.barcode) as loads,
             COUNT(DISTINCT so.customerid) as customers,
             COUNT(DISTINCT CASE WHEN sod.foc = 1 THEN so.customerid END) as free_customers'
        )->first() ?? (object) []);
    }

    /** The same, for `sales_order` — bundles and lines, sold and free. */
    protected function orderTotals(): object
    {
        return $this->once('orderTotals', fn () => $this->orderLines()->selectRaw(
            'SUM(CASE WHEN sod.foc = 1 THEN 0 ELSE sod.quantityordered END) as sold,
             SUM(CASE WHEN sod.foc = 1 THEN sod.quantityordered ELSE 0 END) as free,
             SUM(sod.quantityordered) as bundles,
             COUNT(*) as linecount,
             SUM(CASE WHEN sod.foc = 1 THEN 1 ELSE 0 END) as free_lines,
             COUNT(DISTINCT so.orderid) as orders,
             COUNT(DISTINCT so.customerid) as customers'
        )->first() ?? (object) []);
    }

    /** Bundles the customer has confirmed receiving, off `status`. */
    protected function deliveredBundles(): float
    {
        return $this->once('delivered',
            fn () => (float) $this->loadingsBare('status')->sum('l.quantityloaded'));
    }

    protected function val(?object $row, string $field): float
    {
        return (float) ($row->{$field} ?? 0);
    }

    /* ---------------- Formatting ---------------- */

    /** Free as a percentage of everything that moved. */
    protected function freeShare(float $sold, float $free): float
    {
        $total = $sold + $free;

        return $total > 0.0 ? $free / $total * 100 : 0.0;
    }

    protected function share(float $part, float $whole): string
    {
        return $this->pct($whole > 0.0 ? $part / $whole * 100 : 0.0);
    }

    protected function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
    }

    protected function naira(float $v): string
    {
        return '₦' . ($this->isRounded() ? $this->figure($v) : number_format($v, 2));
    }

    /**
     * A pair of filled series, sold and free, over the same buckets.
     *
     * Two passes through series() rather than one grouped query, because the
     * trait fills empty buckets and both halves have to be filled against the
     * SAME timeline or the two lines would not line up.
     */
    protected function splitSeries(): array
    {
        $join = fn ($q) => $q->leftJoin('sales_order_details as sod', 'sod.id', '=', 'sales_loading.sod_id');

        return [
            $this->series('sales_loading', 'dateofloading', '/',
                'SUM(CASE WHEN sod.foc = 1 THEN 0 ELSE quantityloaded END)', $join),
            $this->series('sales_loading', 'dateofloading', '/',
                'SUM(CASE WHEN sod.foc = 1 THEN quantityloaded ELSE 0 END)', $join),
        ];
    }

    /**
     * A filled series over a quantity, with optional joins. Thin wrapper over
     * the trait's series() so every caller states the table unaliased and the
     * date column unqualified in one place.
     */
    protected function seriesQty(string $table, string $dateCol, string $agg, ?callable $join = null): array
    {
        return $this->series($table, $dateCol, '/', $agg, function ($q) use ($join, $table, $dateCol) {
            if ($dateCol === 'status') {
                $q->whereNotNull($table . '.status');
            }
            if ($join) {
                $join($q);
            }
        });
    }

    /* ---------------- Overview ---------------- */

    protected function overviewSection(): array
    {
        $load = $this->loadTotals();
        $order = $this->orderTotals();
        $delivered = $this->deliveredBundles();
        $returned = (float) $this->returns()->sum('r.quantityreturned');

        $sold = $this->val($load, 'sold');
        $free = $this->val($load, 'free');
        $inTransit = SalesLoadings::inTransit();

        $ordered = $this->seriesQty('sales_order', 'dateoforder', 'SUM(sales_order_details.quantityordered)',
            fn ($q) => $q->join('sales_order_details', 'sales_order_details.orderid', '=', 'sales_order.orderid'));
        $loadedLine = $this->seriesQty('sales_loading', 'dateofloading', 'SUM(quantityloaded)');
        $deliveredLine = $this->seriesQty('sales_loading', 'status', 'SUM(quantityloaded)');

        return [
            'tiles' => [
                $this->tile('Ordered', $this->num($this->val($order, 'bundles')), $this->rangeLabel(), 'brand'),
                $this->tile('Loaded', $this->num($this->val($load, 'bundles')), $this->rangeLabel()),
                $this->tile('Delivered', $this->num($delivered), 'confirmed by the customer'),
                $this->tile('Free of Charge', $this->num($free),
                    $this->pct($this->freeShare($sold, $free)) . ' of what was loaded',
                    $free > 0 ? 'neg' : null),
                $this->tile('Returned', $this->num($returned), $this->rangeLabel(),
                    $returned > 0 ? 'neg' : null),
                $this->tile('Orders', $this->num($this->val($order, 'orders')),
                    $this->num($this->val($load, 'loads')) . ' load(s)'),
                $this->tile('Customers Served', $this->num($this->val($load, 'customers')), $this->rangeLabel()),
                // All time, not the range: a load still out is still out
                // whenever it left.
                $this->tile('Out, Unconfirmed', $this->num((float) $inTransit['bundles']),
                    $this->num((float) $inTransit['loads']) . ' load(s), all time',
                    $inTransit['stale_loads'] > 0 ? 'neg' : null),
            ],
            'charts' => [
                $this->chartSpec('sales-flow', 'line', 'Ordered, Loaded and Delivered',
                    $ordered['labels'] ?: $loadedLine['labels'], [
                        ['name' => 'Ordered', 'data' => $ordered['data']],
                        ['name' => 'Loaded', 'data' => $loadedLine['data']],
                        ['name' => 'Delivered', 'data' => $deliveredLine['data']],
                    ], ['span' => 2, 'subtitle' => 'Bundles · ' . $this->rangeLabel()]),
                $this->chartSpec('sales-split', 'donut', 'Sold vs Free of Charge',
                    ['Sold', 'Free of charge'],
                    [['name' => 'Bundles', 'data' => [$sold, $free]]],
                    ['subtitle' => 'Bundles loaded · ' . $this->rangeLabel()]),
                $this->chartSpec('sales-depot', 'donut', 'Loaded by Depot', ...$this->byDepot()),
            ],
        ];
    }

    /** [labels, series, opt] for a donut of bundles by depot. */
    protected function byDepot(): array
    {
        $rows = $this->loadingsFull()
            ->selectRaw('so.warehousecode, SUM(l.quantityloaded) as bundles')
            ->groupBy('so.warehousecode')->orderByDesc('bundles')->get();

        $names = DB::connection('core')->table('warehouses')
            ->whereNotNull('legacy_sales_code')->pluck('name', 'legacy_sales_code');

        return [
            $rows->map(fn ($r) => $names[$r->warehousecode] ?? ($r->warehousecode ?: 'Unknown'))->all(),
            [['name' => 'Bundles', 'data' => $rows->map(fn ($r) => (float) $r->bundles)->all()]],
            ['subtitle' => 'Bundles loaded · ' . $this->rangeLabel()],
        ];
    }

    /* ---------------- Sold vs Free ---------------- */

    protected function focSection(): array
    {
        $load = $this->loadTotals();
        $order = $this->orderTotals();

        $sold = $this->val($load, 'sold');
        $free = $this->val($load, 'free');

        [$soldSeries, $freeSeries] = $this->splitSeries();

        // Free share per bucket, so a spike shows even when volume is flat.
        $shareLine = [];
        foreach ($freeSeries['data'] as $i => $bucketFree) {
            $shareLine[] = round(
                $this->freeShare((float) ($soldSeries['data'][$i] ?? 0), (float) $bucketFree), 2
            );
        }

        return [
            'tiles' => [
                $this->tile('Sold', $this->num($sold), 'bundles loaded', 'pos'),
                $this->tile('Free of Charge', $this->num($free), 'bundles loaded', $free > 0 ? 'neg' : null),
                $this->tile('Free Share', $this->pct($this->freeShare($sold, $free)), 'of bundles loaded'),
                $this->tile('Free Share of Orders',
                    $this->pct($this->freeShare($this->val($order, 'sold'), $this->val($order, 'free'))),
                    'of bundles ordered'),
                // Lines, not bundles, and the two are very different numbers: a
                // free line is usually a small one. All time, one order line in
                // five is free of charge but only one bundle in thirty-five.
                $this->tile('Free Order Lines',
                    $this->share($this->val($order, 'free_lines'), $this->val($order, 'linecount')),
                    $this->num($this->val($order, 'free_lines')) . ' of '
                    . $this->num($this->val($order, 'linecount')) . ' lines'),
                $this->tile('Customers Given Stock', $this->num($this->val($load, 'free_customers')),
                    'of ' . $this->num($this->val($load, 'customers')) . ' served'),
            ],
            'charts' => [
                $this->chartSpec('foc-time', 'bar', 'Sold vs Free of Charge', $soldSeries['labels'], [
                    ['name' => 'Sold', 'data' => $soldSeries['data']],
                    ['name' => 'Free of charge', 'data' => $freeSeries['data']],
                ], ['span' => 2, 'subtitle' => 'Bundles loaded · ' . $this->rangeLabel()]),
                $this->chartSpec('foc-share-time', 'line', 'Free Share Over Time',
                    $freeSeries['labels'], [['name' => 'Free share', 'data' => $shareLine]],
                    ['span' => 2, 'valueFmt' => 'pct',
                        'subtitle' => 'Free bundles as a % of everything loaded']),
                $this->chartSpec('foc-donut', 'donut', 'Share of Bundles Loaded',
                    ['Sold', 'Free of charge'],
                    [['name' => 'Bundles', 'data' => [$sold, $free]]]),
                $this->chartSpec('foc-products', 'hbar', 'Most Given Away, by Product',
                    ...$this->topFree('product')),
                $this->chartSpec('foc-customers', 'hbar', 'Most Given Away, by Customer',
                    ...$this->topFree('customer')),
            ],
        ];
    }

    /** [labels, series, opt] for the top ten receivers of free stock. */
    protected function topFree(string $by): array
    {
        // Only the customer cut needs the order; the product is on the line.
        $q = $by === 'customer'
            ? $this->loadingsFull()->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid')
            : $this->loadings()->leftJoin('products as p', 'p.productid', '=', 'sod.productid');

        $name = $by === 'customer' ? 'c.customername' : 'p.productname';

        $rows = $q->where('sod.foc', 1)
            ->selectRaw("COALESCE(NULLIF({$name}, ''), '—') as name, SUM(l.quantityloaded) as bundles")
            ->groupBy('name')->orderByDesc('bundles')->limit(10)->get();

        return [
            $rows->pluck('name')->all(),
            [['name' => 'Free bundles', 'data' => $rows->map(fn ($r) => (float) $r->bundles)->all()]],
            ['subtitle' => $this->rangeLabel()],
        ];
    }

    /* ---------------- Orders ---------------- */

    protected function ordersSection(): array
    {
        $order = $this->orderTotals();
        $ordered = $this->val($order, 'bundles');

        // What has actually gone out against those orders. A correlated
        // subquery, not a join — a line loaded on three trucks would otherwise
        // multiply its ordered quantity by three.
        //
        // first(), not value(): value() rewrites the select list with the
        // column name and would lose the expression it is meant to read.
        $loaded = (float) ($this->orderLines()
            ->selectRaw('SUM(COALESCE((SELECT SUM(quantityloaded) FROM sales_loading
                         WHERE sales_loading.sod_id = sod.id), 0)) as loaded')
            ->first()->loaded ?? 0);

        $count = $this->series('sales_order', 'dateoforder', '/', 'COUNT(*)');
        $bundles = $this->seriesQty('sales_order', 'dateoforder', 'SUM(sales_order_details.quantityordered)',
            fn ($q) => $q->join('sales_order_details', 'sales_order_details.orderid', '=', 'sales_order.orderid'));

        $byProduct = $this->orderLines()
            ->leftJoin('products as p', 'p.productid', '=', 'sod.productid')
            ->selectRaw("COALESCE(NULLIF(p.productname,''),'—') as name, SUM(sod.quantityordered) as bundles")
            ->groupBy('name')->orderByDesc('bundles')->limit(10)->get();

        $balance = $ordered - $loaded;

        return [
            'tiles' => [
                $this->tile('Orders Placed', $this->num($this->val($order, 'orders')), $this->rangeLabel(), 'brand'),
                $this->tile('Bundles Ordered', $this->num($ordered),
                    $this->num($this->val($order, 'linecount')) . ' line(s)'),
                $this->tile('Loaded Against Them', $this->num($loaded),
                    $this->share($loaded, $ordered) . ' of the order'),
                $this->tile('Still Owed', $this->num(max(0, $balance)),
                    'ordered but not yet loaded', $balance > 0 ? 'neg' : 'pos'),
                $this->tile('Free of Charge', $this->num($this->val($order, 'free')),
                    $this->pct($this->freeShare($this->val($order, 'sold'), $this->val($order, 'free')))
                    . ' of bundles ordered'),
                $this->tile('Customers Ordering', $this->num($this->val($order, 'customers')), $this->rangeLabel()),
            ],
            'charts' => [
                $this->chartSpec('ord-time', 'bar', 'Orders Placed', $count['labels'],
                    [['name' => 'Orders', 'data' => $count['data']]],
                    ['subtitle' => $this->rangeLabel()]),
                $this->chartSpec('ord-bundles', 'line', 'Bundles Ordered', $bundles['labels'],
                    [['name' => 'Bundles', 'data' => $bundles['data']]],
                    ['subtitle' => $this->rangeLabel()]),
                $this->chartSpec('ord-products', 'hbar', 'Most Ordered Products',
                    $byProduct->pluck('name')->all(),
                    [['name' => 'Bundles', 'data' => $byProduct->map(fn ($r) => (float) $r->bundles)->all()]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
            ],
        ];
    }

    /* ---------------- Loading & Delivery ---------------- */

    protected function dispatchSection(): array
    {
        $load = $this->loadTotals();
        $loaded = $this->val($load, 'bundles');
        $loads = $this->val($load, 'loads');
        $delivered = $this->deliveredBundles();

        // Joined on (sod_id, barcode), not `loading_id`, which 58,364 of the
        // older unload rows never had.
        [$from, $to] = $this->bounds('/');
        $unloaded = (float) $this->db()->table('sales_loading as l')
            ->join('sales_loading_return as u', function ($j) {
                $j->on('u.sod_id', '=', 'l.sod_id')->on('u.barcode', '=', 'l.barcode');
            })
            ->when($from, fn ($q) => $q->whereBetween('l.dateofloading', [$from, $to]))
            ->sum('u.quantityunloaded');

        $out = $this->seriesQty('sales_loading', 'dateofloading', 'SUM(quantityloaded)');
        $confirmed = $this->seriesQty('sales_loading', 'status', 'SUM(quantityloaded)');

        // The cageroom is on the loading row itself — no joins needed.
        $byGate = $this->loadingsBare()
            ->selectRaw("COALESCE(NULLIF(l.cageroomcode,''),'—') as code, SUM(l.quantityloaded) as bundles")
            ->groupBy('code')->orderByDesc('bundles')->get();
        $gateNames = DB::connection('core')->table('warehouse_gates')
            ->where('legacy_name', 'like', 'CGR%')->pluck('name', 'legacy_name');

        $byProduct = $this->loadings()
            ->leftJoin('products as p', 'p.productid', '=', 'sod.productid')
            ->selectRaw("COALESCE(NULLIF(p.productname,''),'—') as name, SUM(l.quantityloaded) as bundles")
            ->groupBy('name')->orderByDesc('bundles')->limit(10)->get();

        return [
            'tiles' => [
                $this->tile('Bundles Loaded', $this->num($loaded), $this->num($loads) . ' load(s)', 'brand'),
                $this->tile('Confirmed Delivered', $this->num($delivered),
                    $this->share($delivered, $loaded) . ' of what was loaded', 'pos'),
                $this->tile('Loaded, Unconfirmed', $this->num($this->val($load, 'open_bundles')),
                    'still live from this period',
                    $this->val($load, 'open_bundles') > 0 ? 'neg' : null),
                $this->tile('Taken Back at the Gate', $this->num($unloaded),
                    'unloaded before the truck left'),
                $this->tile('Free of Charge', $this->num($this->val($load, 'free')),
                    $this->pct($this->freeShare($this->val($load, 'sold'), $this->val($load, 'free')))
                    . ' of bundles loaded'),
                $this->tile('Bundles per Load', $this->num($loads > 0 ? round($loaded / $loads) : 0), 'average'),
            ],
            'charts' => [
                $this->chartSpec('disp-time', 'line', 'Loaded vs Confirmed Delivered',
                    $out['labels'], [
                        ['name' => 'Loaded', 'data' => $out['data']],
                        ['name' => 'Delivered', 'data' => $confirmed['data']],
                    ], ['span' => 2, 'subtitle' => 'Bundles · ' . $this->rangeLabel()]),
                $this->chartSpec('disp-gate', 'hbar', 'Loaded by Cageroom',
                    $byGate->map(fn ($r) => $gateNames[$r->code] ?? $r->code)->all(),
                    [['name' => 'Bundles', 'data' => $byGate->map(fn ($r) => (float) $r->bundles)->all()]],
                    ['subtitle' => $this->rangeLabel()]),
                $this->chartSpec('disp-product', 'hbar', 'Most Loaded Products',
                    $byProduct->pluck('name')->all(),
                    [['name' => 'Bundles', 'data' => $byProduct->map(fn ($r) => (float) $r->bundles)->all()]],
                    ['subtitle' => $this->rangeLabel()]),
            ],
        ];
    }

    /* ---------------- Returns ---------------- */

    protected function returnsSection(): array
    {
        // `linecount`, not `lines` — LINES is a MySQL keyword and an unquoted
        // alias of that name is a syntax error.
        $totals = $this->returns()
            ->selectRaw('SUM(r.quantityreturned) as returned, SUM(r.quantityrejected) as rejected,
                         COUNT(*) as linecount, COUNT(DISTINCT so.customerid) as customers')
            ->first();

        $returned = $this->val($totals, 'returned');
        $rejected = $this->val($totals, 'rejected');

        // Against what went out in the same window, which is the only thing
        // that makes a return figure mean anything.
        $loaded = $this->val($this->loadTotals(), 'bundles');

        $back = $this->series('sales_return', 'dateofreturn', '/', 'SUM(quantityreturned)');
        $bad = $this->series('sales_return', 'dateofreturn', '/', 'SUM(quantityrejected)');

        $byProduct = $this->returns()
            ->leftJoin('products as p', 'p.productid', '=', 'sod.productid')
            ->selectRaw("COALESCE(NULLIF(p.productname,''),'—') as name,
                         SUM(r.quantityreturned) as returned, SUM(r.quantityrejected) as rejected")
            ->groupBy('name')->orderByDesc('returned')->limit(10)->get();

        $byCustomer = $this->returns()
            ->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid')
            ->selectRaw("COALESCE(NULLIF(c.customername,''),'—') as name, SUM(r.quantityreturned) as returned")
            ->groupBy('name')->orderByDesc('returned')->limit(10)->get();

        return [
            'tiles' => [
                $this->tile('Returned', $this->num($returned), $this->rangeLabel(),
                    $returned > 0 ? 'neg' : 'pos'),
                // Rejected is PART of returned, never additional to it.
                $this->tile('Rejected as Damaged', $this->num($rejected),
                    $this->share($rejected, $returned) . ' of what came back',
                    $rejected > 0 ? 'neg' : null),
                $this->tile('Back to Sellable Stock', $this->num($returned - $rejected), 'the rest'),
                $this->tile('Return Rate', $this->share($returned, $loaded),
                    'of bundles loaded in the same period'),
                $this->tile('Return Lines', $this->num($this->val($totals, 'linecount')), $this->rangeLabel()),
                $this->tile('Customers Returning', $this->num($this->val($totals, 'customers')), $this->rangeLabel()),
            ],
            'charts' => [
                $this->chartSpec('ret-time', 'bar', 'Returned vs Rejected', $back['labels'], [
                    ['name' => 'Returned', 'data' => $back['data']],
                    ['name' => 'Rejected', 'data' => $bad['data']],
                ], ['span' => 2, 'subtitle' => 'Bundles · ' . $this->rangeLabel()]),
                $this->chartSpec('ret-product', 'hbar', 'Most Returned Products',
                    $byProduct->pluck('name')->all(), [
                        ['name' => 'Returned', 'data' => $byProduct->map(fn ($r) => (float) $r->returned)->all()],
                        ['name' => 'Rejected', 'data' => $byProduct->map(fn ($r) => (float) $r->rejected)->all()],
                    ], ['subtitle' => $this->rangeLabel()]),
                $this->chartSpec('ret-customer', 'hbar', 'Customers Returning Most',
                    $byCustomer->pluck('name')->all(),
                    [['name' => 'Returned', 'data' => $byCustomer->map(fn ($r) => (float) $r->returned)->all()]],
                    ['subtitle' => $this->rangeLabel()]),
            ],
        ];
    }

    /* ---------------- Transport ---------------- */

    protected function transportSection(): array
    {
        $totals = $this->waybills()->selectRaw(
            'COUNT(*) as bills, SUM(w.transportcost) as cost,
             SUM(CASE WHEN w.receiptnumber IS NULL THEN 1 ELSE 0 END) as no_receipt'
        )->first();

        $bills = $this->val($totals, 'bills');
        $cost = $this->val($totals, 'cost');

        // Deliveries in the same window, to say how many of them were hauled.
        [$from, $to] = $this->bounds('/');
        $deliveries = (float) $this->db()->table('sales_delivery')
            ->when($from, fn ($q) => $q->whereBetween('dateofdelivery', [$from, $to]))
            ->count();

        $spend = $this->series('sales_waybill', 'dateofwaybill', '/', 'SUM(transportcost)');
        $count = $this->series('sales_waybill', 'dateofwaybill', '/', 'COUNT(*)');
        $byTransporter = $this->transporterSpend();

        return [
            'tiles' => [
                $this->tile('Transport Cost', $this->naira($cost), $this->rangeLabel(), 'brand'),
                $this->tile('Waybills Raised', $this->num($bills), $this->rangeLabel()),
                $this->tile('Average per Waybill', $this->naira($bills > 0 ? $cost / $bills : 0)),
                // Most deliveries never get a waybill: a customer collecting in
                // their own truck has no haulier to pay.
                $this->tile('Deliveries Hauled', $this->share($bills, $deliveries),
                    'of ' . $this->num($deliveries) . ' delivery(s) — the rest were collected'),
                $this->tile('No Receipt Number', $this->num($this->val($totals, 'no_receipt')),
                    $this->share($this->val($totals, 'no_receipt'), $bills) . ' of waybills',
                    $this->val($totals, 'no_receipt') > 0 ? 'neg' : null),
            ],
            'charts' => [
                $this->chartSpec('wb-spend', 'line', 'Transport Cost', $spend['labels'],
                    [['name' => 'Cost', 'data' => $spend['data']]],
                    ['span' => 2, 'valueFmt' => 'ngn', 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('wb-count', 'bar', 'Waybills Raised', $count['labels'],
                    [['name' => 'Waybills', 'data' => $count['data']]],
                    ['subtitle' => $this->rangeLabel()]),
                $this->chartSpec('wb-transporter', 'hbar', 'Spend by Transporter',
                    $byTransporter->pluck('name')->all(),
                    [['name' => 'Cost', 'data' => $byTransporter->map(fn ($r) => (float) $r->cost)->all()]],
                    ['valueFmt' => 'ngn', 'subtitle' => $this->rangeLabel()]),
            ],
        ];
    }

    /**
     * Spend per transporter.
     *
     * The waybill reaches a transporter only through delivery -> loading, and a
     * load has one row per product, so the bill is collapsed to ONE ROW PER
     * WAYBILL first and totalled after. Grouping the raw join would charge a
     * ₦120,000 bill once for every product on the truck.
     */
    protected function transporterSpend()
    {
        [$from, $to] = $this->bounds('/');

        $perBill = $this->db()->table('sales_waybill as w')
            ->leftJoin('sales_delivery as d', function ($j) {
                $j->on('d.dateofdelivery', '=', 'w.dateofwaybill')
                  ->whereRaw('d.deliverynumber = CAST(w.deliverynumber AS UNSIGNED)');
            })
            ->leftJoin('sales_loading as l', function ($j) {
                $j->on('l.status', '=', 'd.dateofdelivery')->on('l.loadnumber', '=', 'd.loadnumber');
            })
            ->leftJoin('sales_transporters as t', 't.id', '=', 'l.transporterid')
            ->when($from, fn ($q) => $q->whereBetween('w.dateofwaybill', [$from, $to]))
            ->selectRaw("w.id, w.transportcost, COALESCE(MAX(t.transportername), '— none recorded —') as name")
            ->groupBy('w.id', 'w.transportcost');

        return $this->db()->query()->fromSub($perBill, 'b')
            ->selectRaw('b.name, SUM(b.transportcost) as cost, COUNT(*) as bills')
            ->groupBy('b.name')->orderByDesc('cost')->limit(10)->get();
    }

    /* ---------------- Customers ---------------- */

    protected function customersSection(): array
    {
        $load = $this->loadTotals();

        $rows = $this->loadingsFull()
            ->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid')
            ->selectRaw("COALESCE(NULLIF(c.customername,''),'— no order line —') as name,
                         SUM(l.quantityloaded) as bundles,
                         SUM(CASE WHEN sod.foc = 1 THEN l.quantityloaded ELSE 0 END) as free,
                         COUNT(DISTINCT l.barcode) as loads")
            ->groupBy('name')->orderByDesc('bundles')->limit(10)->get();

        $total = $this->val($load, 'bundles');

        $byState = $this->loadingsFull()
            ->leftJoin('sales_customers as c', 'c.id', '=', 'so.customerid')
            ->selectRaw("COALESCE(NULLIF(c.customerstate,''),'—') as name, SUM(l.quantityloaded) as bundles")
            ->groupBy('name')->orderByDesc('bundles')->limit(12)->get();

        $newCustomers = (float) $this->db()->table('sales_customers')
            ->when($this->rangeStart(), fn ($q, $s) => $q->where('created_at', '>=', $s))
            ->count();

        return [
            'tiles' => [
                $this->tile('Customers Served', $this->num($this->val($load, 'customers')),
                    $this->rangeLabel(), 'brand'),
                $this->tile('Bundles Loaded', $this->num($total), $this->rangeLabel()),
                $this->tile('Top 10 Share', $this->share((float) $rows->sum('bundles'), $total),
                    'of everything loaded'),
                $this->tile('Biggest Customer', $rows->first()?->name ?? '—',
                    $rows->first() ? $this->num((float) $rows->first()->bundles) . ' bundles' : null),
                $this->tile('States Reached', $this->num((float) $byState->count()), $this->rangeLabel()),
                $this->tile('New Customers', $this->num($newCustomers),
                    'added ' . strtolower($this->rangeLabel())),
            ],
            'charts' => [
                $this->chartSpec('cust-top', 'hbar', 'Biggest Customers',
                    $rows->pluck('name')->all(), [
                        ['name' => 'Sold', 'data' => $rows->map(fn ($r) => (float) $r->bundles - (float) $r->free)->all()],
                        ['name' => 'Free of charge', 'data' => $rows->map(fn ($r) => (float) $r->free)->all()],
                    ], ['span' => 2, 'height' => 320, 'subtitle' => 'Bundles loaded · ' . $this->rangeLabel()]),
                $this->chartSpec('cust-state', 'hbar', 'Bundles by State',
                    $byState->pluck('name')->all(),
                    [['name' => 'Bundles', 'data' => $byState->map(fn ($r) => (float) $r->bundles)->all()]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
            ],
        ];
    }
}
