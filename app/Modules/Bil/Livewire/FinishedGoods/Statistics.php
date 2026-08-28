<?php

namespace Modules\Bil\Livewire\FinishedGoods;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\Concerns\LegacyStatQueries;
use Modules\Bil\Models\StockTransfer as TransferModel;
use Modules\Core\Livewire\StatisticsPage;

/**
 * Finished Goods → Statistics. The counterpart of the Raw Materials dashboard,
 * on the same StatisticsPage base.
 *
 * It spans two date IDIOMS, not two schemas. Production and factory exit are
 * legacy `bil` tables with `Y/m/d` VARCHAR dates — handled by LegacyStatQueries,
 * which buckets with LEFT(col, 7|10). Warehouse receipts and waste are gds-owned
 * tables with real DATE columns, so they get the dateSeries()/dateCount()
 * helpers below: the same shape of answer without the string juggling.
 *
 * Those helpers take a connection because stock transfers still live in `core`
 * (a transfer can cross companies); everything else here is `bil`.
 *
 * Ranges are capped at 12 months for the same reason Raw Materials caps them:
 * `factory_conversion` is 1.2M rows and unbounded aggregation over nine years
 * is slow, while every bounded range rides the (dateofproduction, id) index.
 */
#[Title('Finished Goods Statistics')]
class Statistics extends StatisticsPage
{
    use LegacyStatQueries;

    public function pageTitle(): string
    {
        return 'Finished Goods Statistics';
    }

    public function pageSubtitle(): string
    {
        return 'Production, warehouse flow, stock, waste and transfers at a glance.';
    }

    /** See the class note — bounded ranges keep every query index-assisted. */
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
        return 'bil.finished-goods.statistics.export';
    }

    protected function exportPageKey(): ?string
    {
        return 'bil.finished_goods.statistics';
    }

    public function sections(): array
    {
        return [
            'overview' => 'Overview',
            'production' => 'Production',
            'warehouse' => 'Warehouse',
            'waste' => 'Waste',
            'transfers' => 'Transfers',
        ];
    }

    protected function section(string $key): array
    {
        return match ($key) {
            'production' => $this->productionSection(),
            'warehouse' => $this->warehouseSection(),
            'waste' => $this->wasteSection(),
            'transfers' => $this->transfersSection(),
            default => $this->overviewSection(),
        };
    }

    /** LegacyStatQueries works against the legacy tables. */
    protected function db()
    {
        return DB::connection('bil');
    }

    protected function core()
    {
        return DB::connection('core');
    }

    /* ---------------- core-connection helpers (real DATE columns) ---------------- */

    /** [from, to] as Carbon-formatted ISO dates, or [null, null] for all time. */
    protected function coreBounds(): array
    {
        $start = $this->rangeStart();

        return $start
            ? [$start->format('Y-m-d'), $this->rangeEnd()->format('Y-m-d')]
            : [null, null];
    }

    /**
     * A filled time series over a real DATE column on `core`.
     *
     * Same contract as LegacyStatQueries::series() — continuous buckets, zeroes
     * filled — but it can use DATE_FORMAT rather than LEFT(), because the column
     * is an actual date.
     */
    protected function dateSeries(string $table, string $dateCol, string $agg = 'COUNT(*)', ?callable $where = null, string $conn = 'bil'): array
    {
        $monthly = $this->isMonthly();
        $fmt = $monthly ? '%Y-%m' : '%Y-%m-%d';

        $q = DB::connection($conn)->table($table)
            ->selectRaw("DATE_FORMAT(`{$dateCol}`, '{$fmt}') as bucket, {$agg} as val");

        [$from, $to] = $this->coreBounds();
        if ($from) {
            $q->whereBetween($dateCol, [$from, $to]);
        }
        if ($where) {
            $where($q);
        }

        $vals = $q->groupBy('bucket')->orderBy('bucket')->pluck('val', 'bucket');

        $start = $this->rangeStart() ?? Carbon::parse('2020-01-01');
        $cursor = $monthly ? $start->copy()->startOfMonth() : $start->copy()->startOfDay();
        $end = $this->rangeEnd();

        $labels = [];
        $data = [];
        $guard = 0;

        while ($cursor <= $end && $guard++ < 800) {
            $key = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');
            $labels[] = $monthly ? $cursor->format('M Y') : $cursor->format('j M');
            $data[] = (float) ($vals[$key] ?? 0);
            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return ['labels' => $labels, 'data' => $data];
    }

    protected function dateCount(string $table, string $dateCol, ?callable $where = null, string $conn = 'bil'): int
    {
        return (int) $this->dateScoped($table, $dateCol, $where, $conn)->count();
    }

    protected function dateSum(string $table, string $dateCol, string $col, ?callable $where = null, string $conn = 'bil'): float
    {
        return (float) $this->dateScoped($table, $dateCol, $where, $conn)->sum($col);
    }

    protected function dateScoped(string $table, string $dateCol, ?callable $where = null, string $conn = 'bil')
    {
        $q = DB::connection($conn)->table($table);
        [$from, $to] = $this->coreBounds();

        if ($from) {
            $q->whereBetween($dateCol, [$from, $to]);
        }
        if ($where) {
            $where($q);
        }

        return $q;
    }

    /** bil.products, resolved in PHP — the masters are on the other connection. */
    protected function productNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        return $this->db()->table('products')->whereIn('productid', $ids)
            ->pluck('productname', 'productid')->all();
    }

    /* ---------------- Overview ---------------- */

    protected function overviewSection(): array
    {
        $products = (int) $this->db()->table('products')->where('is_deleted', 0)->count();

        $pallets = $this->countOver('factory_conversion', 'dateofproduction', '/');
        $bundles = $this->sumOver('factory_conversion', 'dateofproduction', '/', 'bundles');
        $exited = $this->countOver('factory_exit', 'dateofexit', '/');

        $received = $this->dateCount('finished_goods_warehouse_receipts', 'date_of_entrance',
            fn ($q) => $q->where('is_historic', false));

        // Snapshots — "right now", not "over the range".
        $onFloor = (int) $this->db()->table('factory_conversion')->whereNull('status')->count();
        $inStock = (float) $this->db()->table('finished_goods_warehouse_stock')->sum('bundles');

        $wasteKg = (float) $this->db()->table('conversion_waste_entries as e')
            ->join('conversion_waste_runs as r', 'e.run_id', '=', 'r.id')
            ->when($this->coreBounds()[0], fn ($q) => $q->whereBetween('r.production_date', $this->coreBounds()))
            ->sum('e.weight_kg');

        $openRuns = (int) $this->db()->table('conversion_waste_runs')->whereNull('confirmed_at')->count();

        // The flow, end to end: made → left the factory → booked into a store.
        $made = $this->series('factory_conversion', 'dateofproduction', '/', 'SUM(bundles)');
        $out = $this->series('factory_exit', 'dateofexit', '/', 'SUM(bundles)');
        $in = $this->dateSeries('finished_goods_warehouse_receipts', 'date_of_entrance', 'SUM(bundles)',
            fn ($q) => $q->where('is_historic', false));

        $byLine = $this->db()->table('factory_conversion')
            ->when($this->bounds('/')[0], fn ($q) => $q->whereBetween('dateofproduction', $this->bounds('/')))
            ->selectRaw('sublinename as name, SUM(bundles) as val')
            ->groupBy('sublinename')->orderByDesc('val')->limit(8)->get();

        return [
            'tiles' => [
                $this->tile('Products', $this->num($products)),
                $this->tile('Pallets Made', $this->num($pallets), $this->rangeLabel(), 'brand'),
                $this->tile('Bundles Made', $this->num($bundles), $this->rangeLabel()),
                $this->tile('Sent to Warehouse', $this->num($exited), $this->rangeLabel()),
                $this->tile('Received', $this->num($received), $this->rangeLabel()),
                $this->tile('On Factory Floor', $this->num($onFloor), 'pallets waiting'),
                $this->tile('In Stock', $this->num($inStock), 'bundles', 'brand'),
                $this->tile('Waste', $this->kg($wasteKg), $this->rangeLabel()),
                $this->tile('Runs Awaiting Waste', $this->num($openRuns), null, $openRuns > 0 ? 'neg' : 'pos'),
            ],
            'charts' => [
                $this->chartSpec('fg-flow', 'line', 'Finished Goods Flow',
                    $made['labels'] ?: ($out['labels'] ?: $in['labels']), [
                        ['name' => 'Made', 'data' => $made['data']],
                        ['name' => 'Left factory', 'data' => $out['data']],
                        ['name' => 'Received into store', 'data' => $in['data']],
                    ], ['span' => 2, 'height' => 260,
                        'subtitle' => 'Bundles at each stage · ' . $this->rangeLabel()]),

                $this->chartSpec('fg-byline', 'bar', 'Production by Line',
                    $byLine->pluck('name')->all(),
                    [['name' => 'Bundles', 'data' => $byLine->pluck('val')->map(fn ($v) => (float) $v)->all()]],
                    ['subtitle' => 'Bundles · ' . $this->rangeLabel()]),
            ],
        ];
    }

    /* ---------------- Production ---------------- */

    protected function productionSection(): array
    {
        $scoped = fn () => $this->db()->table('factory_conversion')
            ->when($this->bounds('/')[0], fn ($q) => $q->whereBetween('dateofproduction', $this->bounds('/')));

        $pallets = (int) $scoped()->count();
        $bundles = (float) $scoped()->sum('bundles');
        // Legacy weights are grams; every other screen divides by 1000 too.
        $netKg = (float) $scoped()->sum('netweight') / 1000;
        $perPallet = $pallets > 0 ? $bundles / $pallets : 0;

        $daily = $this->series('factory_conversion', 'dateofproduction', '/', 'SUM(bundles)');

        $byLine = $scoped()->selectRaw('sublinename as name, SUM(bundles) as val')
            ->groupBy('sublinename')->orderByDesc('val')->limit(10)->get();

        $byShift = $scoped()->selectRaw("COALESCE(NULLIF(shift,''), 'unknown') as name, SUM(bundles) as val")
            ->groupBy('name')->orderByDesc('val')->get();

        $byFactory = $scoped()->selectRaw('factory as name, SUM(bundles) as val')
            ->groupBy('factory')->orderByDesc('val')->get();

        // Grouped on the indexed productid, then named in PHP — joining to
        // products across 1.2M rows costs far more than 10 lookups.
        $topRaw = $scoped()->selectRaw('productid, SUM(bundles) as val')
            ->groupBy('productid')->orderByDesc('val')->limit(10)->get();
        $names = $this->productNames($topRaw->pluck('productid')->all());

        return [
            'tiles' => [
                $this->tile('Pallets', $this->num($pallets), $this->rangeLabel(), 'brand'),
                $this->tile('Bundles', $this->num($bundles), $this->rangeLabel()),
                $this->tile('Net Weight', $this->kg($netKg), $this->rangeLabel()),
                $this->tile('Bundles / Pallet', number_format($perPallet, 1), 'average'),
            ],
            'charts' => [
                $this->chartSpec('pr-daily', 'line', 'Production Over Time', $daily['labels'],
                    [['name' => 'Bundles', 'data' => $daily['data']]],
                    ['span' => 2, 'height' => 260, 'subtitle' => 'Bundles made · ' . $this->rangeLabel()]),

                $this->chartSpec('pr-line', 'bar', 'By Line',
                    $byLine->pluck('name')->all(),
                    [['name' => 'Bundles', 'data' => $byLine->pluck('val')->map(fn ($v) => (float) $v)->all()]]),

                $this->chartSpec('pr-shift', 'donut', 'Day vs Night',
                    $byShift->pluck('name')->map(fn ($s) => ucfirst($s))->all(),
                    [['name' => 'Bundles', 'data' => $byShift->pluck('val')->map(fn ($v) => (float) $v)->all()]]),

                $this->chartSpec('pr-top', 'hbar', 'Top Products',
                    $topRaw->map(fn ($r) => $names[$r->productid] ?? ('#' . $r->productid))->all(),
                    [['name' => 'Bundles', 'data' => $topRaw->pluck('val')->map(fn ($v) => (float) $v)->all()]],
                    ['height' => 300]),

                $this->chartSpec('pr-factory', 'donut', 'By Factory',
                    $byFactory->pluck('name')->all(),
                    [['name' => 'Bundles', 'data' => $byFactory->pluck('val')->map(fn ($v) => (float) $v)->all()]]),
            ],
        ];
    }

    /* ---------------- Warehouse ---------------- */

    protected function warehouseSection(): array
    {
        $live = fn ($q) => $q->where('is_historic', false);

        $receipts = $this->dateCount('finished_goods_warehouse_receipts', 'date_of_entrance', $live);
        $bundlesIn = $this->dateSum('finished_goods_warehouse_receipts', 'date_of_entrance', 'bundles', $live);

        $stockRows = $this->db()->table('finished_goods_warehouse_stock')->where('bundles', '>', 0);
        $inStock = (float) (clone $stockRows)->sum('bundles');
        $distinct = (int) (clone $stockRows)->distinct()->count('productid');

        $in = $this->dateSeries('finished_goods_warehouse_receipts', 'date_of_entrance', 'SUM(bundles)', $live);

        $byWarehouse = $this->db()->table('finished_goods_warehouse_stock as s')
            ->leftJoin('core.warehouses as w', 's.warehouse_id', '=', 'w.id')
            ->where('s.bundles', '>', 0)
            ->selectRaw("COALESCE(w.name,'Unassigned') as name, SUM(s.bundles) as val")
            ->groupBy('name')->orderByDesc('val')->get();

        $topStock = $this->db()->table('finished_goods_warehouse_stock as s')
            ->leftJoin('products as p', 'p.productid', '=', 's.productid')
            ->where('s.bundles', '>', 0)
            ->selectRaw("COALESCE(p.productname, CONCAT('#', s.productid)) as name, SUM(s.bundles) as val")
            ->groupBy('name')->orderByDesc('val')->limit(10)->get();

        $byGate = $this->db()->table('finished_goods_warehouse_receipts as r')
            ->leftJoin('core.warehouse_gates as g', 'r.entrance_id', '=', 'g.id')
            ->where('r.is_historic', false)
            ->when($this->coreBounds()[0], fn ($q) => $q->whereBetween('r.date_of_entrance', $this->coreBounds()))
            ->selectRaw("COALESCE(g.name,'Unknown') as name, SUM(r.bundles) as val")
            ->groupBy('name')->orderByDesc('val')->limit(8)->get();

        $ordered = (int) $this->db()->table('finished_goods_warehouse_stock')
            ->where('orders_90d', '>', 0)->count();

        return [
            'tiles' => [
                $this->tile('Receipts', $this->num($receipts), $this->rangeLabel()),
                $this->tile('Bundles Received', $this->num($bundlesIn), $this->rangeLabel()),
                $this->tile('In Stock', $this->num($inStock), 'bundles', 'brand'),
                $this->tile('Products in Stock', $this->num($distinct)),
                $this->tile('Ordered in 90 Days', $this->num($ordered), 'products'),
            ],
            'charts' => [
                $this->chartSpec('wh-in', 'line', 'Received Into Warehouse', $in['labels'],
                    [['name' => 'Bundles', 'data' => $in['data']]],
                    ['span' => 2, 'height' => 260, 'subtitle' => 'Bundles booked in · ' . $this->rangeLabel()]),

                $this->chartSpec('wh-store', 'donut', 'Stock by Warehouse',
                    $byWarehouse->pluck('name')->all(),
                    [['name' => 'Bundles', 'data' => $byWarehouse->pluck('val')->map(fn ($v) => (float) $v)->all()]],
                    ['subtitle' => 'On hand now']),

                $this->chartSpec('wh-top', 'hbar', 'Top Products in Stock',
                    $topStock->pluck('name')->all(),
                    [['name' => 'Bundles', 'data' => $topStock->pluck('val')->map(fn ($v) => (float) $v)->all()]],
                    ['height' => 300]),

                $this->chartSpec('wh-gate', 'bar', 'Receipts by Gate',
                    $byGate->pluck('name')->all(),
                    [['name' => 'Bundles', 'data' => $byGate->pluck('val')->map(fn ($v) => (float) $v)->all()]]),
            ],
        ];
    }

    /* ---------------- Waste ---------------- */

    protected function wasteSection(): array
    {
        [$from, $to] = $this->coreBounds();

        $entries = fn () => $this->db()->table('conversion_waste_entries as e')
            ->join('conversion_waste_runs as r', 'e.run_id', '=', 'r.id')
            ->when($from, fn ($q) => $q->whereBetween('r.production_date', [$from, $to]));

        $totalKg = (float) $entries()->sum('e.weight_kg');

        $runs = fn () => $this->db()->table('conversion_waste_runs')
            ->when($from, fn ($q) => $q->whereBetween('production_date', [$from, $to]));

        $confirmed = (int) (clone $runs())->whereNotNull('confirmed_at')->count();
        $open = (int) (clone $runs())->whereNull('confirmed_at')->count();
        $nil = (int) (clone $runs())->where('is_nil', true)->count();
        $perRun = $confirmed > 0 ? $totalKg / $confirmed : 0;

        $daily = $this->dateSeries('conversion_waste_runs', 'production_date', 'COUNT(*)');
        // Weight has to come through the join, so it is built separately and
        // aligned to the same labels.
        $weightSeries = $this->wasteWeightSeries();

        $byCause = $entries()
            ->leftJoin('core.waste_causes as c', 'e.cause_id', '=', 'c.id')
            ->selectRaw("COALESCE(c.name,'—') as name, SUM(e.weight_kg) as val")
            ->groupBy('name')->orderByDesc('val')->limit(10)->get();

        $byOrigin = $entries()
            ->leftJoin('core.waste_origins as o', 'e.origin_id', '=', 'o.id')
            ->selectRaw("COALESCE(o.label,'—') as name, SUM(e.weight_kg) as val")
            ->groupBy('name')->orderByDesc('val')->get();

        $byRef = $entries()
            ->whereNotNull('e.origin_ref')->where('e.origin_ref', '<>', '')
            ->selectRaw('e.origin_ref as name, SUM(e.weight_kg) as val')
            ->groupBy('e.origin_ref')->orderByDesc('val')->limit(10)->get();

        $byLine = $entries()
            ->selectRaw("COALESCE(r.line_name,'—') as name, SUM(e.weight_kg) as val")
            ->groupBy('name')->orderByDesc('val')->limit(10)->get();

        return [
            'tiles' => [
                $this->tile('Waste', $this->kg($totalKg), $this->rangeLabel(), 'brand'),
                $this->tile('Runs Confirmed', $this->num($confirmed), $this->rangeLabel()),
                $this->tile('Runs Open', $this->num($open), 'awaiting entry', $open > 0 ? 'neg' : 'pos'),
                $this->tile('Nil Returns', $this->num($nil), 'nothing to report'),
                $this->tile('Waste / Run', $this->kg($perRun), 'average'),
            ],
            'charts' => [
                $this->chartSpec('ws-time', 'line', 'Waste Over Time', $weightSeries['labels'],
                    [['name' => 'Weight', 'data' => $weightSeries['data']]],
                    ['span' => 2, 'height' => 260, 'valueFmt' => 'kg',
                        'subtitle' => 'Kilograms recorded · ' . $this->rangeLabel()]),

                $this->chartSpec('ws-cause', 'hbar', 'By Cause',
                    $byCause->pluck('name')->all(),
                    [['name' => 'Weight', 'data' => $byCause->pluck('val')->map(fn ($v) => (float) $v)->all()]],
                    ['valueFmt' => 'kg', 'height' => 300]),

                $this->chartSpec('ws-origin', 'donut', 'By Origin',
                    $byOrigin->pluck('name')->all(),
                    [['name' => 'Weight', 'data' => $byOrigin->pluck('val')->map(fn ($v) => (float) $v)->all()]],
                    ['valueFmt' => 'kg']),

                $this->chartSpec('ws-line', 'bar', 'By Line',
                    $byLine->pluck('name')->all(),
                    [['name' => 'Weight', 'data' => $byLine->pluck('val')->map(fn ($v) => (float) $v)->all()]],
                    ['valueFmt' => 'kg']),

                $this->chartSpec('ws-ref', 'hbar', 'By Grade Type / Group',
                    $byRef->pluck('name')->all(),
                    [['name' => 'Weight', 'data' => $byRef->pluck('val')->map(fn ($v) => (float) $v)->all()]],
                    ['valueFmt' => 'kg', 'height' => 300,
                        'subtitle' => 'What the waste was classified against']),
            ],
        ];
    }

    /** Waste weight per bucket — needs the entries join, so not coreSeries(). */
    protected function wasteWeightSeries(): array
    {
        $monthly = $this->isMonthly();
        $fmt = $monthly ? '%Y-%m' : '%Y-%m-%d';
        [$from, $to] = $this->coreBounds();

        $vals = $this->db()->table('conversion_waste_entries as e')
            ->join('conversion_waste_runs as r', 'e.run_id', '=', 'r.id')
            ->when($from, fn ($q) => $q->whereBetween('r.production_date', [$from, $to]))
            ->selectRaw("DATE_FORMAT(r.production_date, '{$fmt}') as bucket, SUM(e.weight_kg) as val")
            ->groupBy('bucket')->orderBy('bucket')->pluck('val', 'bucket');

        $start = $this->rangeStart() ?? Carbon::parse('2026-08-01');
        $cursor = $monthly ? $start->copy()->startOfMonth() : $start->copy()->startOfDay();
        $end = $this->rangeEnd();

        $labels = [];
        $data = [];
        $guard = 0;

        while ($cursor <= $end && $guard++ < 800) {
            $key = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');
            $labels[] = $monthly ? $cursor->format('M Y') : $cursor->format('j M');
            $data[] = round((float) ($vals[$key] ?? 0), 2);
            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /* ---------------- Transfers ---------------- */

    protected function transfersSection(): array
    {
        [$from, $to] = $this->coreBounds();

        $transfers = fn () => $this->core()->table('stock_transfers')
            ->where('module', 'finished-goods')
            ->when($from, fn ($q) => $q->whereBetween('date_of_transfer', [$from, $to]));

        $lines = fn () => $this->core()->table('stock_transfer_lines as l')
            ->join('stock_transfers as t', 'l.transfer_id', '=', 't.id')
            ->where('t.module', 'finished-goods')
            ->when($from, fn ($q) => $q->whereBetween('t.date_of_transfer', [$from, $to]));

        $count = (int) $transfers()->count();
        $sent = (float) $lines()->sum('l.bundles');
        $recv = (float) $lines()->sum('l.received_bundles');
        $short = max(0, $sent - $recv);

        // A snapshot, not a range figure: what is on a truck right now.
        $inTransit = (float) $this->core()->table('stock_transfer_lines as l')
            ->join('stock_transfers as t', 'l.transfer_id', '=', 't.id')
            ->where('t.status', TransferModel::DISPATCHED)
            ->where('t.is_historic', false)
            ->sum('l.bundles');

        $daily = $this->dateSeries('stock_transfers', 'date_of_transfer', 'COUNT(*)',
            fn ($q) => $q->where('module', 'finished-goods'), 'core');

        $byKind = $transfers()
            ->selectRaw('kind as name, COUNT(*) as val')
            ->groupBy('kind')->orderByDesc('val')->get();

        $byRoute = $lines()
            ->leftJoin('warehouses as wf', 't.from_warehouse_id', '=', 'wf.id')
            ->leftJoin('warehouses as wt', 't.to_warehouse_id', '=', 'wt.id')
            ->selectRaw("CONCAT(COALESCE(wf.name,'?'), ' → ', COALESCE(wt.name,'?')) as name, SUM(l.bundles) as val")
            ->groupBy('name')->orderByDesc('val')->limit(10)->get();

        $topProducts = $lines()
            ->selectRaw("COALESCE(l.product_name, CONCAT('#', l.productid)) as name, SUM(l.bundles) as val")
            ->groupBy('name')->orderByDesc('val')->limit(10)->get();

        return [
            'tiles' => [
                $this->tile('Transfers', $this->num($count), $this->rangeLabel(), 'brand'),
                $this->tile('Bundles Sent', $this->num($sent), $this->rangeLabel()),
                $this->tile('Bundles Received', $this->num($recv), $this->rangeLabel()),
                $this->tile('Shortfall', $this->num($short), 'sent but not received', $short > 0 ? 'neg' : 'pos'),
                $this->tile('In Transit', $this->num($inTransit), 'bundles on a truck now'),
            ],
            'charts' => [
                $this->chartSpec('tr-time', 'line', 'Transfers Over Time', $daily['labels'],
                    [['name' => 'Transfers', 'data' => $daily['data']]],
                    ['span' => 2, 'height' => 260, 'subtitle' => 'Truckloads · ' . $this->rangeLabel()]),

                $this->chartSpec('tr-route', 'hbar', 'By Route',
                    $byRoute->pluck('name')->all(),
                    [['name' => 'Bundles', 'data' => $byRoute->pluck('val')->map(fn ($v) => (float) $v)->all()]],
                    ['height' => 300]),

                $this->chartSpec('tr-kind', 'donut', 'Internal vs Inter-company',
                    $byKind->pluck('name')->map(fn ($k) => $k === TransferModel::INTER_COMPANY ? 'Inter-company' : 'Internal')->all(),
                    [['name' => 'Transfers', 'data' => $byKind->pluck('val')->map(fn ($v) => (float) $v)->all()]]),

                $this->chartSpec('tr-top', 'hbar', 'Top Products Moved',
                    $topProducts->pluck('name')->all(),
                    [['name' => 'Bundles', 'data' => $topProducts->pluck('val')->map(fn ($v) => (float) $v)->all()]],
                    ['height' => 300]),
            ],
        ];
    }
}
