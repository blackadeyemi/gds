<?php

namespace Modules\Bil\Livewire\RawMaterials;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\StatisticsPage;

/**
 * Raw Materials → Statistics. An analytics dashboard over the raw-material flow
 * (stock, warehouse in/out, factory consumption, losses), built on the reusable
 * Modules\Core\Livewire\StatisticsPage base. Other modules get their own
 * statistics page the same way.
 *
 * Date columns differ per legacy table: deliveries/consumption store 'Y/m/d',
 * warehouse-entry/exit and factory-entrance store 'Y-m-d', damaged-goods is a
 * real DATE. Time-series buckets by day for ≤90-day ranges, by month for
 * 12-month / all-time.
 */
#[Title('Raw Materials Statistics')]
class Statistics extends StatisticsPage
{
    public function pageTitle(): string
    {
        return 'Raw Materials Statistics';
    }

    public function pageSubtitle(): string
    {
        return 'Stock, warehouse flow, factory consumption and losses at a glance.';
    }

    /**
     * Cap the range at 12 months (no all-time). The legacy tables are large
     * (120k–310k rows) and unbounded time-series/charset-join aggregation over
     * 25 years of history is slow (7–12s); a date-bounded range keeps every
     * query index-assisted. 12 months is the standard trend window anyway.
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
        return 'bil.raw-materials.statistics.export';
    }

    protected function exportPageKey(): ?string
    {
        return 'bil.raw_materials.statistics';
    }

    public function sections(): array
    {
        return [
            'overview' => 'Overview',
            'stock' => 'Stock',
            'flow' => 'Warehouse Flow',
            'consumption' => 'Consumption',
            'losses' => 'Losses & Returns',
        ];
    }

    protected function section(string $key): array
    {
        return match ($key) {
            'stock' => $this->stockSection(),
            'flow' => $this->flowSection(),
            'consumption' => $this->consumptionSection(),
            'losses' => $this->lossesSection(),
            default => $this->overviewSection(),
        };
    }

    /* ---------------- Shared query helpers ---------------- */

    protected function db()
    {
        return DB::connection('bil');
    }

    protected function isMonthly(): bool
    {
        return in_array($this->range, ['12m', 'all'], true);
    }

    /** [from, to] range as strings in the given separator, or [null, null] for all-time. */
    protected function bounds(string $sep): array
    {
        $start = $this->rangeStart();
        if (! $start) {
            return [null, null];
        }
        $fmt = $sep === '/' ? 'Y/m/d' : 'Y-m-d';

        return [$start->format($fmt), $this->rangeEnd()->format($fmt)];
    }

    /**
     * A filled time series {labels, data} for a string-date column, bucketed by
     * day or month. $where receives the builder to add table-specific filters.
     */
    protected function series(string $table, string $dateCol, string $sep, string $agg = 'COUNT(*)', ?callable $where = null): array
    {
        $monthly = $this->isMonthly();
        $len = $monthly ? 7 : 10;

        $q = $this->db()->table($table)->selectRaw("LEFT(`{$dateCol}`, {$len}) as bucket, {$agg} as val");
        [$from, $to] = $this->bounds($sep);
        if ($from) {
            $q->whereBetween($dateCol, [$from, $to]);
        }
        if ($where) {
            $where($q);
        }
        $vals = $q->groupBy('bucket')->orderBy('bucket')->pluck('val', 'bucket');

        // Continuous buckets from range start (or earliest present, for all-time) to now.
        $start = $this->rangeStart();
        if (! $start) {
            // All-time: start at the earliest VALID bucket. Legacy date columns
            // hold dirty values (empty strings, a stray far-future date), so
            // ignore anything that doesn't begin with a real year.
            $validKeys = $vals->keys()->filter(fn ($k) => preg_match('/^(19|20)\d{2}/', (string) $k));
            if ($validKeys->isEmpty()) {
                return ['labels' => [], 'data' => []];
            }
            $minKey = str_replace('/', '-', (string) $validKeys->min());
            $start = Carbon::parse($monthly ? $minKey . '-01' : $minKey);
        }

        $labels = [];
        $data = [];
        $fmt = $sep === '/' ? 'Y/m/d' : 'Y-m-d';
        $cursor = $monthly ? $start->copy()->startOfMonth() : $start->copy()->startOfDay();
        $end = $this->rangeEnd();
        $guard = 0;
        while ($cursor <= $end && $guard++ < 800) {
            $key = substr($cursor->format($fmt), 0, $len);
            $labels[] = $monthly ? $cursor->format('M Y') : $cursor->format('j M');
            $data[] = (float) ($vals[$key] ?? 0);
            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** Count rows in a table over the current range (date column + separator). */
    protected function countOver(string $table, string $dateCol, string $sep, ?callable $where = null): int
    {
        $q = $this->db()->table($table);
        [$from, $to] = $this->bounds($sep);
        if ($from) {
            $q->whereBetween($dateCol, [$from, $to]);
        }
        if ($where) {
            $where($q);
        }

        return (int) $q->count();
    }

    protected function sumOver(string $table, string $dateCol, string $sep, string $col, ?callable $where = null): float
    {
        $q = $this->db()->table($table);
        [$from, $to] = $this->bounds($sep);
        if ($from) {
            $q->whereBetween($dateCol, [$from, $to]);
        }
        if ($where) {
            $where($q);
        }

        return (float) $q->sum($col);
    }

    /**
     * Top N groups of a table over the range, grouped by an INDEXED base column
     * (fast) — returns rows of {key, cnt}. Resolve key→name with nameMap().
     */
    protected function topBy(string $table, string $dateCol, string $sep, string $keyCol, int $limit = 10)
    {
        $q = $this->db()->table($table)->selectRaw("`{$keyCol}` as `key`, COUNT(*) as cnt");
        [$from, $to] = $this->bounds($sep);
        if ($from) {
            $q->whereBetween($dateCol, [$from, $to]);
        }

        return $q->groupBy($keyCol)->orderByDesc('cnt')->limit($limit)->get();
    }

    /** Map key-column values to a display name for a lookup table. */
    protected function nameMap(string $table, string $keyCol, string $nameCol, $keys): array
    {
        $keys = collect($keys)->filter(fn ($k) => $k !== null && $k !== '')->all();
        if (! $keys) {
            return [];
        }

        return $this->db()->table($table)->whereIn($keyCol, $keys)->pluck($nameCol, $keyCol)->all();
    }

    protected function kg(float $v): string
    {
        return $this->figure($v, 'kg');
    }

    protected function num(float $v): string
    {
        return $this->figure($v);
    }

    /* ---------------- Sections ---------------- */

    protected function overviewSection(): array
    {
        $products = (int) $this->db()->table('rawmaterials_products')->count();
        $suppliers = (int) $this->db()->table('rawmaterials_supplier')->count();
        $stockQty = (float) $this->db()->table('rawmaterials_stock')->where('quantity', '>', 0)->sum('quantity');
        $stockWt = (float) $this->db()->table('rawmaterials_stock')->where('quantity', '>', 0)->sum('weight');
        $onFloor = (int) $this->db()->table('factory_entrance_rawmaterials')->whereNull('status')->count();

        $received = $this->countOver('rawmaterials_supplier_deliveries', 'dateofcreation', '/');
        $exited = $this->countOver('rawmaterials_warehouse_exit', 'dateofcreation', '-');
        $consumed = $this->countOver('factory_usage_rawmaterials', 'dateofuse', '/', fn ($q) => $q->where('is_deleted', 0));

        // Warehouse flow: received vs exited over the range (same unit → one axis).
        $recv = $this->series('rawmaterials_supplier_deliveries', 'dateofcreation', '/');
        $exit = $this->series('rawmaterials_warehouse_exit', 'dateofcreation', '-');
        $labels = $recv['labels'] ?: $exit['labels'];

        // Stock by group (identity → donut).
        $byGroup = $this->db()->table('rawmaterials_stock as s')
            ->leftJoin('rawmaterials_products as p', 's.productid', '=', 'p.id')
            ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
            ->where('s.quantity', '>', 0)
            ->selectRaw("COALESCE(g.groupname, 'Ungrouped') as name, SUM(s.quantity) as qty")
            ->groupBy('name')->orderByDesc('qty')->limit(8)->get();

        return [
            'tiles' => [
                $this->tile('Products', $this->num($products)),
                $this->tile('Suppliers', $this->num($suppliers)),
                $this->tile('In Stock', $this->num($stockQty), 'items', 'brand'),
                $this->tile('Stock Weight', $this->kg($stockWt)),
                $this->tile('On Factory Floor', $this->num($onFloor), 'items'),
                $this->tile('Received', $this->num($received), $this->rangeLabel()),
                $this->tile('Exited', $this->num($exited), $this->rangeLabel()),
                $this->tile('Consumed', $this->num($consumed), $this->rangeLabel()),
            ],
            'charts' => [
                $this->chartSpec('wh-flow', 'line', 'Warehouse Flow', $labels, [
                    ['name' => 'Received', 'data' => $recv['data']],
                    ['name' => 'Exited', 'data' => $exit['data']],
                ], ['span' => 2, 'subtitle' => 'Items in vs out · ' . $this->rangeLabel(), 'height' => 260]),
                $this->chartSpec('stock-group', 'donut', 'Stock by Group',
                    $byGroup->pluck('name')->all(),
                    [['name' => 'Quantity', 'data' => $byGroup->pluck('qty')->map(fn ($v) => (float) $v)->all()]],
                    ['subtitle' => 'Current on-hand quantity']),
            ],
        ];
    }

    protected function stockSection(): array
    {
        $base = fn () => $this->db()->table('rawmaterials_stock as s')
            ->leftJoin('rawmaterials_products as p', 's.productid', '=', 'p.id')
            ->leftJoin('rawmaterials_groups as g', 'p.groupid', '=', 'g.id')
            ->leftJoin('rawmaterials_subgroups as sg', 'p.subgroupid', '=', 'sg.id')
            ->where('s.quantity', '>', 0);

        $lines = (int) (clone $base())->count();
        $qty = (float) (clone $base())->sum('s.quantity');
        $wt = (float) (clone $base())->sum('s.weight');
        $distinct = (int) (clone $base())->distinct()->count('s.productid');

        $byGroup = (clone $base())->selectRaw("COALESCE(g.groupname,'Ungrouped') as name, SUM(s.quantity) as qty")
            ->groupBy('name')->orderByDesc('qty')->limit(10)->get();
        $topProducts = (clone $base())->selectRaw("COALESCE(p.productname,'—') as name, SUM(s.weight) as wt")
            ->groupBy('name')->orderByDesc('wt')->limit(10)->get();
        $byLocation = (clone $base())->selectRaw('s.location as name, SUM(s.quantity) as qty')
            ->groupBy('s.location')->orderByDesc('qty')->limit(8)->get();
        $bySub = (clone $base())->selectRaw("COALESCE(sg.subgroupname,'—') as name, SUM(s.quantity) as qty")
            ->groupBy('name')->orderByDesc('qty')->limit(10)->get();

        return [
            'tiles' => [
                $this->tile('Stock Lines', $this->num($lines)),
                $this->tile('Total Quantity', $this->num($qty), 'items', 'brand'),
                $this->tile('Total Weight', $this->kg($wt)),
                $this->tile('Distinct Products', $this->num($distinct)),
            ],
            'charts' => [
                $this->chartSpec('stk-group', 'bar', 'Quantity by Group',
                    $byGroup->pluck('name')->all(),
                    [['name' => 'Quantity', 'data' => $byGroup->pluck('qty')->map(fn ($v) => (float) $v)->all()]]),
                $this->chartSpec('stk-loc', 'donut', 'Quantity by Location',
                    $byLocation->pluck('name')->all(),
                    [['name' => 'Quantity', 'data' => $byLocation->pluck('qty')->map(fn ($v) => (float) $v)->all()]]),
                $this->chartSpec('stk-top', 'hbar', 'Top Products by Weight',
                    $topProducts->pluck('name')->all(),
                    [['name' => 'Weight', 'data' => $topProducts->pluck('wt')->map(fn ($v) => round((float) $v))->all()]],
                    ['valueFmt' => 'kg', 'height' => 300]),
                $this->chartSpec('stk-sub', 'hbar', 'Quantity by Sub-Group',
                    $bySub->pluck('name')->all(),
                    [['name' => 'Quantity', 'data' => $bySub->pluck('qty')->map(fn ($v) => (float) $v)->all()]],
                    ['height' => 300]),
            ],
        ];
    }

    protected function flowSection(): array
    {
        $received = $this->countOver('rawmaterials_supplier_deliveries', 'dateofcreation', '/');
        $recvWt = $this->sumOver('rawmaterials_supplier_deliveries', 'dateofcreation', '/', 'weight');
        $entered = $this->countOver('rawmaterials_warehouse_entry', 'dateofcreation', '-');
        $exited = $this->countOver('rawmaterials_warehouse_exit', 'dateofcreation', '-');

        $deliveries = $this->series('rawmaterials_supplier_deliveries', 'dateofcreation', '/');
        $entry = $this->series('rawmaterials_warehouse_entry', 'dateofcreation', '-');
        $exit = $this->series('rawmaterials_warehouse_exit', 'dateofcreation', '-');

        // Top products received + receipts by supplier (identity), over range.
        // Group by the indexed base-table id/code (NOT a joined name string —
        // that needs a temp table + collation filesort, ~6s on 45k rows), then
        // resolve names in PHP.
        [$from, $to] = $this->bounds('/');
        $topRecv = $this->topBy('rawmaterials_supplier_deliveries', 'dateofcreation', '/', 'productid', 10);
        $bySupplier = $this->topBy('rawmaterials_supplier_deliveries', 'dateofcreation', '/', 'suppliercode', 7);
        $prodNames = $this->nameMap('rawmaterials_products', 'id', 'productname', $topRecv->pluck('key'));
        $supNames = $this->nameMap('rawmaterials_supplier', 'suppliercode', 'suppliername', $bySupplier->pluck('key'));

        return [
            'tiles' => [
                $this->tile('Received', $this->num($received), $this->rangeLabel(), 'brand'),
                $this->tile('Received Weight', $this->kg($recvWt)),
                $this->tile('Warehouse Entries', $this->num($entered)),
                $this->tile('Warehouse Exits', $this->num($exited)),
            ],
            'charts' => [
                $this->chartSpec('flow-inout', 'line', 'Entries vs Exits', $entry['labels'] ?: $exit['labels'], [
                    ['name' => 'Entries', 'data' => $entry['data']],
                    ['name' => 'Exits', 'data' => $exit['data']],
                ], ['span' => 2, 'subtitle' => $this->rangeLabel(), 'height' => 260]),
                $this->chartSpec('flow-deliv', 'bar', 'Supplier Deliveries', $deliveries['labels'],
                    [['name' => 'Deliveries', 'data' => $deliveries['data']]], ['subtitle' => 'Items received']),
                $this->chartSpec('flow-supplier', 'donut', 'Receipts by Supplier',
                    $bySupplier->map(fn ($r) => $supNames[$r->key] ?? ($r->key ?: 'Unknown'))->all(),
                    [['name' => 'Deliveries', 'data' => $bySupplier->pluck('cnt')->map(fn ($v) => (float) $v)->all()]]),
                $this->chartSpec('flow-top', 'hbar', 'Top Products Received',
                    $topRecv->map(fn ($r) => $prodNames[$r->key] ?? '—')->all(),
                    [['name' => 'Deliveries', 'data' => $topRecv->pluck('cnt')->map(fn ($v) => (float) $v)->all()]],
                    ['span' => 2, 'height' => 300]),
            ],
        ];
    }

    protected function consumptionSection(): array
    {
        $notDeleted = fn ($q) => $q->where('is_deleted', 0);
        $count = $this->countOver('factory_usage_rawmaterials', 'dateofuse', '/', $notDeleted);
        $weight = $this->sumOver('factory_usage_rawmaterials', 'dateofuse', '/', 'weight', $notDeleted);

        [$from, $to] = $this->bounds('/');
        $scoped = fn () => $this->db()->table('factory_usage_rawmaterials as f')
            ->where('f.is_deleted', 0)
            ->when($from, fn ($q) => $q->whereBetween('f.dateofuse', [$from, $to]));

        $distinct = (int) (clone $scoped())->distinct()->count('f.project');

        // Consumption weight over time.
        $overTime = $this->series('factory_usage_rawmaterials', 'dateofuse', '/', 'ROUND(SUM(weight))', $notDeleted);

        $byFactory = (clone $scoped())->selectRaw('f.location as name, COUNT(*) as cnt')
            ->groupBy('f.location')->orderByDesc('cnt')->limit(10)->get();
        $byShift = (clone $scoped())->selectRaw("COALESCE(NULLIF(f.shift,''),'—') as name, COUNT(*) as cnt")
            ->groupBy('name')->orderByDesc('cnt')->get();
        // Group by the resolved product id (indexed), not the joined name
        // string, then map ids to names in PHP — the CONVERT charset join is
        // already the costly part; grouping on a string on top of it made it far
        // worse.
        $topMatRaw = $this->db()->table('factory_usage_rawmaterials as f')
            ->leftJoin('rawmaterials_warehouse_entry as r', 'r.barcode', '=', DB::raw('CONVERT(f.barcode USING latin1)'))
            ->where('f.is_deleted', 0)
            ->when($from, fn ($q) => $q->whereBetween('f.dateofuse', [$from, $to]))
            ->selectRaw('r.productid as pid, ROUND(SUM(f.weight)) as wt')
            ->groupBy('r.productid')->orderByDesc('wt')->limit(10)->get();
        $matNames = $this->nameMap('rawmaterials_products', 'id', 'productname', $topMatRaw->pluck('pid'));

        return [
            'tiles' => [
                $this->tile('Consumed', $this->num($count), $this->rangeLabel(), 'brand'),
                $this->tile('Consumed Weight', $this->kg($weight)),
                $this->tile('Products on Line', $this->num($distinct)),
            ],
            'charts' => [
                $this->chartSpec('con-time', 'line', 'Consumption Weight', $overTime['labels'],
                    [['name' => 'Weight', 'data' => $overTime['data']]],
                    ['span' => 2, 'valueFmt' => 'kg', 'subtitle' => $this->rangeLabel(), 'height' => 260]),
                $this->chartSpec('con-factory', 'bar', 'Consumption by Factory',
                    $byFactory->pluck('name')->all(),
                    [['name' => 'Items', 'data' => $byFactory->pluck('cnt')->map(fn ($v) => (float) $v)->all()]]),
                $this->chartSpec('con-shift', 'donut', 'By Shift',
                    $byShift->pluck('name')->all(),
                    [['name' => 'Items', 'data' => $byShift->pluck('cnt')->map(fn ($v) => (float) $v)->all()]]),
                $this->chartSpec('con-top', 'hbar', 'Top Raw Materials Consumed',
                    $topMatRaw->map(fn ($r) => $matNames[$r->pid] ?? '—')->all(),
                    [['name' => 'Weight', 'data' => $topMatRaw->pluck('wt')->map(fn ($v) => (float) $v)->all()]],
                    ['span' => 2, 'valueFmt' => 'kg', 'height' => 300]),
            ],
        ];
    }

    protected function lossesSection(): array
    {
        $dmgCount = $this->countOver('damagedgoods_rawmaterial', 'entrance_date', '-');
        $dmgWeight = $this->sumOver('damagedgoods_rawmaterial', 'entrance_date', '-', 'weight');
        $returns = (int) $this->db()->table('return_approval')->count();
        $returnsPending = (int) $this->db()->table('return_approval')->where('status', 'pending')->count();

        $dmgTime = $this->series('damagedgoods_rawmaterial', 'entrance_date', '-');

        [$from, $to] = $this->bounds('-');
        $dmgByProduct = $this->db()->table('damagedgoods_rawmaterial as d')
            ->leftJoin('rawmaterials_products as p', 'd.product_id', '=', 'p.id')
            ->when($from, fn ($q) => $q->whereBetween('d.entrance_date', [$from, $to]))
            ->selectRaw("COALESCE(p.productname,'—') as name, COUNT(*) as cnt")
            ->groupBy('name')->orderByDesc('cnt')->limit(10)->get();

        $returnsByStatus = $this->db()->table('return_approval')
            ->selectRaw("COALESCE(NULLIF(status,''),'—') as name, COUNT(*) as cnt")
            ->groupBy('name')->orderByDesc('cnt')->get();

        return [
            'tiles' => [
                $this->tile('Damaged', $this->num($dmgCount), $this->rangeLabel(), 'neg'),
                $this->tile('Damaged Weight', $this->kg($dmgWeight)),
                $this->tile('Returns (all time)', $this->num($returns)),
                $this->tile('Returns Pending', $this->num($returnsPending), null, $returnsPending > 0 ? 'neg' : null),
            ],
            'charts' => [
                $this->chartSpec('loss-time', 'bar', 'Damaged Goods', $dmgTime['labels'],
                    [['name' => 'Damaged', 'data' => $dmgTime['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('loss-product', 'hbar', 'Damaged by Product',
                    $dmgByProduct->pluck('name')->all(),
                    [['name' => 'Items', 'data' => $dmgByProduct->pluck('cnt')->map(fn ($v) => (float) $v)->all()]]),
                $this->chartSpec('loss-returns', 'donut', 'Returns by Status',
                    $returnsByStatus->pluck('name')->all(),
                    [['name' => 'Returns', 'data' => $returnsByStatus->pluck('cnt')->map(fn ($v) => (float) $v)->all()]]),
            ],
        ];
    }
}
