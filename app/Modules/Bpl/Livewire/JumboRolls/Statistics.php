<?php

namespace Modules\Bpl\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\Concerns\LegacyStatQueries;
use Modules\Bpl\Support\StockLocations;
use Modules\Core\Livewire\StatisticsPage;

/**
 * BPL → Jumbo Rolls → Statistics. An analytics dashboard over the whole BPL
 * roll flow — what the machines make, what leaves the floor, what the stores
 * hold, what goes back out and what is standing still — on the same
 * Modules\Core\Livewire\StatisticsPage base the BIL dashboards use.
 *
 * ## Both streams, counted as rolls
 *
 * Hardrolls and softrolls live in parallel tables of the same shape, so every
 * figure here is the two added together and the charts split them where the
 * split means something. A softroll is a much lighter roll than a hardroll, so
 * a weight chart mixing them without the split would say nothing.
 *
 * ## Every date is legacy 'Y/m/d' TEXT
 *
 * `dateofmanufacture` and the movement tables' `date` are varchars, not dates.
 * They compare and sort correctly as strings but cannot be handed to MySQL's
 * date functions, so every series and count goes through LegacyStatQueries
 * with the '/' separator.
 *
 * ## Two ways out
 *
 * A finished roll leaves BPL either on a customer's truck (a waybill) or into
 * BIL's own factory (`factory_entrance_reel`, a view onto the `bil` schema on
 * this connection). The second is by far the busier door, so the Movements tab
 * shows both — counting only waybills makes four releases in five look lost.
 *
 * ## Which weight
 *
 * Production weight is the roll's, and it is already NET of the core/wrapper
 * allowance. The movement tables carry no weight at all — a scan is a scan —
 * so their weights are read back off production by barcode. Summing the two
 * against each other is therefore meaningful: the same kilogram is counted
 * once when it is made and once when it moves.
 */
#[Title('BPL Jumbo Roll Statistics')]
class Statistics extends StatisticsPage
{
    use LegacyStatQueries;

    public function pageTitle(): string
    {
        return 'BPL Jumbo Roll Statistics';
    }

    public function pageSubtitle(): string
    {
        return 'What the machines make, where it goes, and what is standing still.';
    }

    /**
     * No all-time option. `bpl_production` is 268,000 rows and the movement
     * tables 130,000 each; a 12-month window already answers every question
     * this dashboard is for, and the reports do the archaeology.
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
        return 'bpl.jumbo-rolls.statistics.export';
    }

    protected function exportPageKey(): ?string
    {
        return 'bpl.jumbo_rolls.statistics';
    }

    public function sections(): array
    {
        return [
            'overview' => 'Overview',
            'production' => 'Production',
            'movements' => 'Movements',
            'stock' => 'Stock',
            'waiting' => 'Waiting Area',
        ];
    }

    protected function section(string $key): array
    {
        return match ($key) {
            'production' => $this->productionSection(),
            'movements' => $this->movementsSection(),
            'stock' => $this->stockSection(),
            'waiting' => $this->waitingSection(),
            default => $this->overviewSection(),
        };
    }

    protected function db()
    {
        return DB::connection('bpl');
    }

    /* ---------------- Both streams, one figure ---------------- */

    /** The hardroll and softroll table for each thing that happens to a roll. */
    private const STREAMS = [
        'production' => ['bpl_production', 'bpl_softroll_production'],
        'factory_exit' => ['bpl_factoryexit', 'bpl_softroll_factoryexit'],
        'entry' => ['bpl_warehouse_entry', 'bpl_softroll_warehouse_entry'],
        'exit' => ['bpl_warehouse_exit', 'bpl_softroll_warehouse_exit'],
    ];

    /** Rows across both of a stage's tables, over the range. */
    private function countBoth(string $stage, string $dateCol): int
    {
        $live = fn ($q) => $q->whereNull('deleted_at');

        return array_sum(array_map(
            fn ($t) => $this->countOver($t, $dateCol, '/', $live),
            self::STREAMS[$stage]
        ));
    }

    /** The same series over both of a stage's tables, added bucket by bucket. */
    private function seriesBoth(string $stage, string $dateCol, string $agg = 'COUNT(*)'): array
    {
        $live = fn ($q) => $q->whereNull('deleted_at');
        [$hard, $soft] = self::STREAMS[$stage];

        $a = $this->series($hard, $dateCol, '/', $agg, $live);
        $b = $this->series($soft, $dateCol, '/', $agg, $live);

        // Both are built from the same range, so the buckets line up; the
        // guard is for the all-time case, which starts at the earliest row.
        if ($a['labels'] !== $b['labels']) {
            return $a['labels'] ? $a : $b;
        }

        return [
            'labels' => $a['labels'],
            'data' => array_map(fn ($x, $y) => $x + $y, $a['data'], $b['data']),
        ];
    }

    /**
     * A movement's weight over the range, read back off production by barcode.
     * The movement tables record a scan, not a weight.
     */
    private function weightMoved(string $stage): float
    {
        [$from, $to] = $this->bounds('/');
        [$hard, $soft] = self::STREAMS[$stage];

        $leg = fn (string $table, string $prod) => $this->db()->table($table . ' as m')
            ->join($prod . ' as prod', function ($j) {
                $j->on('prod.barcode', '=', 'm.barcode')->whereNull('prod.deleted_at');
            })
            ->whereNull('m.deleted_at')
            ->when($from, fn ($q) => $q->whereBetween('m.date', [$from, $to]))
            ->sum('prod.weight');

        return (float) $leg($hard, 'bpl_production') + (float) $leg($soft, 'bpl_softroll_production');
    }

    /* ---------------- The live position ---------------- */

    /**
     * Held stock by warehouse and stream, from the same query the Warehouse
     * Stock page totals — so the dashboard and the page can never disagree.
     */
    private function position()
    {
        return $this->db()->query()->fromSub((new WarehouseStock())->positionQuery(), 'p')
            ->selectRaw('`warehouse`, `stream`, SUM(`rolls`) as `rolls`, ROUND(SUM(`weight`), 2) as `weight`')
            ->groupBy('warehouse', 'stream')->get();
    }

    /* ---------------- Sections ---------------- */

    protected function overviewSection(): array
    {
        $position = $this->position();

        $made = $this->countBoth('production', 'dateofmanufacture');
        $left = $this->countBoth('factory_exit', 'date');
        $received = $this->countBoth('entry', 'date');
        $released = $this->countBoth('exit', 'date');

        $madeSeries = $this->seriesBoth('production', 'dateofmanufacture');
        $receivedSeries = $this->seriesBoth('entry', 'date');
        $releasedSeries = $this->seriesBoth('exit', 'date');

        // Rolls that left the floor but no store has taken in — the chain's
        // most common break, and the one the Roll Trail report lists by name.
        $inTransit = max(0, $left - $received);

        return [
            'tiles' => [
                $this->tile('Rolls in Store', $this->num((float) $position->sum('rolls')), 'right now', 'brand'),
                $this->tile('Stock Weight', $this->kg((float) $position->sum('weight')), 'right now'),
                $this->tile('Made', $this->num($made), $this->rangeLabel()),
                $this->tile('Left the Factory', $this->num($left), $this->rangeLabel()),
                $this->tile('Received into Store', $this->num($received), $this->rangeLabel()),
                $this->tile('Released', $this->num($released), $this->rangeLabel()),
                $this->tile('Not Yet Received', $this->num($inTransit), 'left the floor in this window',
                    $inTransit > 0 ? 'neg' : null),
            ],
            'charts' => [
                $this->chartSpec('bjr-flow', 'line', 'Made, Received and Released',
                    $madeSeries['labels'] ?: $receivedSeries['labels'], [
                        ['name' => 'Made', 'data' => $madeSeries['data']],
                        ['name' => 'Received', 'data' => $receivedSeries['data']],
                        ['name' => 'Released', 'data' => $releasedSeries['data']],
                    ], ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('bjr-where', 'donut', 'Stock Weight by Store',
                    $position->groupBy('warehouse')->keys()->all(),
                    [['name' => 'Weight', 'data' => $position->groupBy('warehouse')
                        ->map(fn ($g) => (float) $g->sum('weight'))->values()->all()]],
                    ['valueFmt' => 'kg']),
            ],
        ];
    }

    protected function productionSection(): array
    {
        [$from, $to] = $this->bounds('/');

        $scoped = fn ($table) => $this->db()->table($table . ' as prod')
            ->whereNull('prod.deleted_at')
            ->when($from, fn ($q) => $q->whereBetween('prod.dateofmanufacture', [$from, $to]));

        $rolls = $this->countBoth('production', 'dateofmanufacture');
        $weight = (float) $scoped('bpl_production')->sum('prod.weight')
            + (float) $scoped('bpl_softroll_production')->sum('prod.weight');
        $time = $this->seriesBoth('production', 'dateofmanufacture', 'SUM(`weight`)');

        // The machine's two spellings: 'PM3' on a hardroll, the bare number on
        // a softroll. Spelled out so one bar means one machine.
        $byMachine = $scoped('bpl_production')
            ->selectRaw("COALESCE(NULLIF(`prod`.`papermachine`,''),'—') as `name`, SUM(`prod`.`weight`) as `wt`")
            ->groupBy('name')->pluck('wt', 'name');

        foreach ($scoped('bpl_softroll_production')
            ->selectRaw("CONCAT('PM', `prod`.`papermachine`) as `name`, SUM(`prod`.`weight`) as `wt`")
            ->groupBy('prod.papermachine')->get() as $row) {
            $byMachine[$row->name] = (float) ($byMachine[$row->name] ?? 0) + (float) $row->wt;
        }

        $byGrade = $scoped('bpl_production')
            ->leftJoin('bpl_products_hardroll as pr', 'pr.id', '=', 'prod.product_id')
            ->selectRaw("COALESCE(NULLIF(`pr`.`gradetype`,''),'—') as `name`, COUNT(*) as `cnt`, SUM(`prod`.`weight`) as `wt`")
            ->groupBy('name')->orderByDesc('wt')->limit(12)->get();

        $hardrolls = (int) $scoped('bpl_production')->count();
        $softrolls = (int) $scoped('bpl_softroll_production')->count();

        // The allowance actually taken off in this window — the rule is on the
        // Weight Allowances screen; this is what it cost.
        $deducted = (float) $scoped('bpl_production')->sum('prod.net_weight');

        return [
            'tiles' => [
                $this->tile('Rolls Made', $this->num($rolls), $this->rangeLabel(), 'brand'),
                $this->tile('Weight Made', $this->kg($weight), 'net of the allowance'),
                $this->tile('Allowance Deducted', $this->kg($deducted), $this->rangeLabel()),
                $this->tile('Hardrolls', $this->num($hardrolls), $this->rangeLabel()),
                $this->tile('Softrolls', $this->num($softrolls), $this->rangeLabel()),
            ],
            'charts' => [
                $this->chartSpec('bjr-made-time', 'bar', 'Weight Made', $time['labels'],
                    [['name' => 'Weight', 'data' => $time['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel(), 'valueFmt' => 'kg']),
                $this->chartSpec('bjr-made-machine', 'donut', 'By Paper Machine',
                    array_keys($byMachine->all()),
                    [['name' => 'Weight', 'data' => array_map('floatval', array_values($byMachine->all()))]],
                    ['valueFmt' => 'kg']),
                $this->chartSpec('bjr-made-grade', 'hbar', 'By Grade Type (hardroll)',
                    $byGrade->pluck('name')->all(),
                    [['name' => 'Weight', 'data' => $byGrade->pluck('wt')->map(fn ($v) => (float) $v)->all()]],
                    ['valueFmt' => 'kg']),
                $this->chartSpec('bjr-made-split', 'donut', 'Hardroll vs Softroll',
                    ['Hardroll', 'Softroll'],
                    [['name' => 'Rolls', 'data' => [(float) $hardrolls, (float) $softrolls]]]),
            ],
        ];
    }

    protected function movementsSection(): array
    {
        [$from, $to] = $this->bounds('/');

        $left = $this->countBoth('factory_exit', 'date');
        $received = $this->countBoth('entry', 'date');
        $released = $this->countBoth('exit', 'date');

        $leftSeries = $this->seriesBoth('factory_exit', 'date');
        $receivedSeries = $this->seriesBoth('entry', 'date');
        $releasedSeries = $this->seriesBoth('exit', 'date');

        // Which store, for each direction. location_id resolved to the name so
        // the two streams' rows land on the same bar.
        $byStore = function (string $stage) use ($from, $to) {
            $out = [];

            foreach (self::STREAMS[$stage] as $table) {
                foreach ($this->db()->table($table . ' as m')
                    ->whereNull('m.deleted_at')
                    ->when($from, fn ($q) => $q->whereBetween('m.date', [$from, $to]))
                    ->selectRaw(StockLocations::nameExpr('`m`.`location_id`') . ' as `name`, COUNT(*) as `cnt`')
                    ->groupBy('m.location_id')->get() as $row) {
                    $out[$row->name] = ($out[$row->name] ?? 0) + (int) $row->cnt;
                }
            }

            arsort($out);

            return $out;
        };

        $intoStore = $byStore('entry');
        $outOfStore = $byStore('exit');

        // A roll leaves BPL by one of two doors, and the dashboard has to show
        // both or the figures look alarming for no reason. Delivery stamps
        // 'yes' on the exit row when a truck takes one; the rest mostly go to
        // BIL's own factory, which scans them into `factory_entrance_reel`
        // (a view onto the bil schema on this connection) and never touches a
        // BPL waybill. Four of every five releases go that way.
        $loaded = 0;

        foreach (self::STREAMS['exit'] as $table) {
            $loaded += (int) $this->db()->table($table)
                ->whereNull('deleted_at')->where('status', 'yes')
                ->when($from, fn ($q) => $q->whereBetween('date', [$from, $to]))
                ->count();
        }

        // Counted on BIL's receipt date, which is the window that means
        // something for "what did BIL take this month".
        $toBil = (int) $this->db()->table('factory_entrance_reel')
            ->where('is_deleted', 0)
            ->when($from, fn ($q) => $q->whereBetween('dateofentrance', [$from, $to]))
            ->count();

        return [
            'tiles' => [
                $this->tile('Left the Factory', $this->num($left), $this->rangeLabel(), 'brand'),
                $this->tile('Received into Store', $this->num($received), $this->rangeLabel()),
                $this->tile('Released', $this->num($released), $this->rangeLabel()),
                $this->tile('Weight Received', $this->kg($this->weightMoved('entry')), $this->rangeLabel()),
                $this->tile('Loaded on a Waybill', $this->num($loaded), 'to a customer'),
                $this->tile('Received at BIL', $this->num($toBil), 'into the BIL factory'),
            ],
            'charts' => [
                $this->chartSpec('bjr-move-time', 'line', 'Scans by Day',
                    $leftSeries['labels'] ?: $receivedSeries['labels'], [
                        ['name' => 'Factory exit', 'data' => $leftSeries['data']],
                        ['name' => 'Into store', 'data' => $receivedSeries['data']],
                        ['name' => 'Out of store', 'data' => $releasedSeries['data']],
                    ], ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('bjr-move-in', 'hbar', 'Received by Store',
                    array_keys($intoStore),
                    [['name' => 'Rolls', 'data' => array_map('floatval', array_values($intoStore))]]),
                $this->chartSpec('bjr-move-out', 'hbar', 'Released by Store',
                    array_keys($outOfStore),
                    [['name' => 'Rolls', 'data' => array_map('floatval', array_values($outOfStore))]]),
                $this->chartSpec('bjr-move-door', 'donut', 'Which Way Out',
                    ['On a waybill', 'To the BIL factory'],
                    [['name' => 'Rolls', 'data' => [(float) $loaded, (float) $toBil]]],
                    ['subtitle' => 'The two doors a roll leaves BPL by']),
            ],
        ];
    }

    /**
     * The live position. A snapshot, not a window — the range control does not
     * apply here, and the tiles say so.
     */
    protected function stockSection(): array
    {
        $position = $this->position();

        $byItem = $this->db()->query()->fromSub((new WarehouseStock())->positionQuery(), 'p')
            ->selectRaw('`item`, SUM(`rolls`) as `rolls`, ROUND(SUM(`weight`), 2) as `weight`')
            ->groupBy('item')->orderByDesc('weight')->limit(12)->get();

        // What the maintained stock tables say, against what the movements do.
        // The gap is the drift the Warehouse Stock page's mismatch view lists.
        $recorded = (float) $this->db()->table('bpl_hardroll_stock')->sum('weight')
            + (float) $this->db()->table('bpl_softroll_stock')->sum('weight');
        $held = (float) $position->sum('weight');
        $drift = $recorded - $held;

        $byStream = $position->groupBy('stream');

        return [
            'tiles' => [
                $this->tile('Rolls Held', $this->num((float) $position->sum('rolls')), 'right now', 'brand'),
                $this->tile('Weight Held', $this->kg($held), 'from the movements'),
                $this->tile('Recorded Weight', $this->kg($recorded), 'from the stock tables'),
                $this->tile('Difference', $this->kg($drift), 'recorded less held',
                    abs($drift) > 1 ? 'neg' : 'pos'),
            ],
            'charts' => [
                $this->chartSpec('bjr-stock-store', 'hbar', 'Held by Store',
                    $position->groupBy('warehouse')->keys()->all(),
                    [['name' => 'Weight', 'data' => $position->groupBy('warehouse')
                        ->map(fn ($g) => (float) $g->sum('weight'))->values()->all()]],
                    ['span' => 2, 'valueFmt' => 'kg']),
                $this->chartSpec('bjr-stock-stream', 'donut', 'Hardroll vs Softroll',
                    $byStream->keys()->map(fn ($s) => ucfirst((string) $s))->all(),
                    [['name' => 'Weight', 'data' => $byStream->map(fn ($g) => (float) $g->sum('weight'))->values()->all()]],
                    ['valueFmt' => 'kg']),
                $this->chartSpec('bjr-stock-item', 'hbar', 'Held by Product',
                    $byItem->pluck('item')->all(),
                    [['name' => 'Weight', 'data' => $byItem->pluck('weight')->map(fn ($v) => (float) $v)->all()]],
                    ['span' => 2, 'valueFmt' => 'kg']),
            ],
        ];
    }

    /**
     * The waiting area: rolls that left a store, came back and are standing at
     * one. Small numbers by design — the interesting figure is how LONG.
     */
    protected function waitingSection(): array
    {
        [$from, $to] = $this->bounds('/');

        $open = $this->db()->table('bpl_waiting_area_entry as w')
            ->whereNull('w.deleted_at')
            ->where(fn ($q) => $q->whereNull('w.status')->orWhere('w.status', '<>', 'Released'));

        $openRows = (clone $open)
            ->selectRaw(
                StockLocations::nameExpr('`w`.`location_id`') . ' as `store`,'
                . " DATEDIFF(CURDATE(), STR_TO_DATE(`w`.`date`, '%Y/%m/%d')) as `days`"
            )->get();

        $held = $this->countOver('bpl_waiting_area_entry', 'date', '/', fn ($q) => $q->whereNull('deleted_at'));
        $releases = $this->countOver('bpl_waiting_area_exit', 'date', '/', fn ($q) => $q->whereNull('deleted_at'));

        $byStore = $openRows->groupBy('store')->map(fn ($g) => (float) $g->count());
        $time = $this->series('bpl_waiting_area_entry', 'date', '/', 'COUNT(*)', fn ($q) => $q->whereNull('deleted_at'));

        // How long the open holds have stood, in the bands people act on.
        $bands = ['0–7 days' => 0, '8–14 days' => 0, '15–30 days' => 0, 'Over 30 days' => 0];

        foreach ($openRows as $row) {
            $d = (int) $row->days;
            $key = $d <= 7 ? '0–7 days' : ($d <= 14 ? '8–14 days' : ($d <= 30 ? '15–30 days' : 'Over 30 days'));
            $bands[$key]++;
        }

        $longest = (int) ($openRows->max('days') ?? 0);

        return [
            'tiles' => [
                $this->tile('Standing Now', $this->num((float) $openRows->count()), 'right now',
                    $openRows->count() > 0 ? 'neg' : 'brand'),
                $this->tile('Longest Hold', $this->num((float) $longest) . ' days', 'of the open holds',
                    $longest > 14 ? 'neg' : null),
                $this->tile('Held', $this->num($held), $this->rangeLabel()),
                $this->tile('Released', $this->num($releases), $this->rangeLabel()),
            ],
            'charts' => [
                $this->chartSpec('bjr-wait-time', 'bar', 'Rolls Held', $time['labels'],
                    [['name' => 'Held', 'data' => $time['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('bjr-wait-age', 'donut', 'How Long They Have Stood',
                    array_keys($bands),
                    [['name' => 'Rolls', 'data' => array_map('floatval', array_values($bands))]],
                    ['subtitle' => 'Open holds, right now']),
                $this->chartSpec('bjr-wait-store', 'hbar', 'Standing by Store',
                    $byStore->keys()->all(),
                    [['name' => 'Rolls', 'data' => $byStore->values()->all()]]),
            ],
        ];
    }
}
