<?php

namespace Modules\Bil\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\Concerns\LegacyStatQueries;
use Modules\Core\Livewire\StatisticsPage;

/**
 * Jumbo Rolls → Statistics. An analytics dashboard over the jumbo-roll flow —
 * where the stock is standing, what arrives, what gets unwound and what goes
 * back — on the same Modules\Core\Livewire\StatisticsPage base the Raw
 * Materials dashboard uses.
 *
 * Every jumbo date column is legacy 'Y/m/d' text (`dateofentrance`,
 * `dateofuse`, and the `date` added to `factory_event`), so every series and
 * count passes the '/' separator.
 *
 * Weights come from two different places by design: an entrance carries no
 * weight of its own and takes BPL's production weight, while consumption and
 * returns carry the weight of the PIECE that moved. Summing production weight
 * against consumption would count a five-slice reel five times.
 */
#[Title('Jumbo Roll Statistics')]
class Statistics extends StatisticsPage
{
    use LegacyStatQueries;

    public function pageTitle(): string
    {
        return 'Jumbo Roll Statistics';
    }

    public function pageSubtitle(): string
    {
        return 'Where the reels are, what arrives, what is unwound and what goes back.';
    }

    /** @see RawMaterials\Statistics::rangeOptions() — same reasoning, same tables' size. */
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
        return 'bil.jumbo-rolls.statistics.export';
    }

    protected function exportPageKey(): ?string
    {
        return 'bil.jumbo_rolls.statistics';
    }

    public function sections(): array
    {
        return [
            'overview' => 'Overview',
            'stock' => 'Stock',
            'entrance' => 'Factory Entrance',
            'consumption' => 'Consumption',
            'returns' => 'Returns',
        ];
    }

    protected function section(string $key): array
    {
        return match ($key) {
            'stock' => $this->stockSection(),
            'entrance' => $this->entranceSection(),
            'consumption' => $this->consumptionSection(),
            'returns' => $this->returnsSection(),
            default => $this->overviewSection(),
        };
    }

    protected function db()
    {
        return DB::connection('bil');
    }

    protected function customer(): int
    {
        return (int) config('bil.jumbo_roll_customer_id');
    }

    /* ---------------- Stock, borrowed from the Stock page ---------------- */

    /**
     * The live position, by place — the same three legs the Stock page unions,
     * so the dashboard and the page can never disagree.
     */
    protected function position()
    {
        return $this->db()->query()->fromSub((new Stock())->positionQuery(), 's')
            ->selectRaw('s.place, s.location, COUNT(*) as reels, ROUND(SUM(s.weight), 2) as weight')
            ->groupBy('s.place', 's.location')->get();
    }

    /* ---------------- Sections ---------------- */

    protected function overviewSection(): array
    {
        $position = $this->position();

        $received = $this->countOver('factory_entrance_reel', 'dateofentrance', '/', fn ($q) => $q->where('is_deleted', 0));
        $consumed = $this->countOver('factory_usage_reel', 'dateofuse', '/', fn ($q) => $q->where('is_deleted', 0));
        $consumedKg = $this->sumOver('factory_usage_reel', 'dateofuse', '/', 'weight', fn ($q) => $q->where('is_deleted', 0));
        $returned = $this->countOver('factory_event', 'date', '/', fn ($q) => $q->where('event', 'return'));

        // Arriving vs being unwound — same unit, one axis.
        $in = $this->series('factory_entrance_reel', 'dateofentrance', '/', 'COUNT(*)', fn ($q) => $q->where('is_deleted', 0));
        $out = $this->series('factory_usage_reel', 'dateofuse', '/', 'COUNT(*)', fn ($q) => $q->where('is_deleted', 0));

        return [
            'tiles' => [
                $this->tile('Reels in Stock', $this->num((float) $position->sum('reels')), 'right now', 'brand'),
                $this->tile('Stock Weight', $this->kg((float) $position->sum('weight'))),
                $this->tile('Received', $this->num($received), $this->rangeLabel()),
                $this->tile('Consumed', $this->num($consumed), $this->rangeLabel()),
                $this->tile('Consumed Weight', $this->kg($consumedKg), $this->rangeLabel()),
                $this->tile('Returned to BPL', $this->num($returned), $this->rangeLabel(), $returned > 0 ? 'neg' : null),
            ],
            'charts' => [
                $this->chartSpec('jr-flow', 'line', 'Received vs Consumed', $in['labels'] ?: $out['labels'], [
                    ['name' => 'Received', 'data' => $in['data']],
                    ['name' => 'Consumed', 'data' => $out['data']],
                ], ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('jr-where', 'donut', 'Stock by Place',
                    $position->groupBy('place')->keys()->all(),
                    [['name' => 'Reels', 'data' => $position->groupBy('place')
                        ->map(fn ($g) => (float) $g->sum('reels'))->values()->all()]]),
            ],
        ];
    }

    protected function stockSection(): array
    {
        $position = $this->position();
        $byPlace = $position->groupBy('place');

        $onFloor = (int) $this->db()->table('factory_entrance_reel')
            ->where('is_deleted', 0)->whereNull('status')->count();
        $partUsed = (int) $this->db()->table('factory_entrance_reel')
            ->where('is_deleted', 0)->where('status', 'mid')->count();

        return [
            'tiles' => [
                $this->tile('Total Reels', $this->num((float) $position->sum('reels')), null, 'brand'),
                $this->tile('Total Weight', $this->kg((float) $position->sum('weight'))),
                $this->tile('Untouched on Floor', $this->num($onFloor)),
                $this->tile('Part Used', $this->num($partUsed), null, $partUsed > 0 ? 'neg' : null),
            ],
            'charts' => [
                $this->chartSpec('jr-stock-loc', 'hbar', 'Stock by Location',
                    $position->map(fn ($r) => $r->location)->all(),
                    [['name' => 'Weight', 'data' => $position->map(fn ($r) => (float) $r->weight)->all()]],
                    ['span' => 2, 'valueFmt' => 'kg']),
                $this->chartSpec('jr-stock-place', 'donut', 'Weight by Place',
                    $byPlace->keys()->all(),
                    [['name' => 'Weight', 'data' => $byPlace->map(fn ($g) => (float) $g->sum('weight'))->values()->all()]],
                    ['valueFmt' => 'kg']),
            ],
        ];
    }

    protected function entranceSection(): array
    {
        $count = $this->countOver('factory_entrance_reel', 'dateofentrance', '/', fn ($q) => $q->where('is_deleted', 0));
        $time = $this->series('factory_entrance_reel', 'dateofentrance', '/', 'COUNT(*)', fn ($q) => $q->where('is_deleted', 0));

        [$from, $to] = $this->bounds('/');
        $scoped = fn ($q) => $q->where('f.is_deleted', 0)
            ->when($from, fn ($w) => $w->whereBetween('f.dateofentrance', [$from, $to]));

        $byFactory = $scoped($this->db()->table('factory_entrance_reel as f'))
            ->selectRaw("COALESCE(NULLIF(f.location,''),'—') as name, COUNT(*) as cnt")
            ->groupBy('name')->orderByDesc('cnt')->get();

        $byGrade = $scoped($this->db()->table('factory_entrance_reel as f')
            ->leftJoin('bpl_production as prod', 'prod.barcode', '=', 'f.barcode')
            ->leftJoin('bpl_products as pr', 'pr.id', '=', 'prod.product_id'))
            ->selectRaw("COALESCE(NULLIF(pr.gradetype,''),'—') as name, COUNT(*) as cnt, SUM(prod.weight) as wt")
            ->groupBy('name')->orderByDesc('cnt')->limit(10)->get();

        return [
            'tiles' => [
                $this->tile('Reels Received', $this->num($count), $this->rangeLabel(), 'brand'),
                $this->tile('Weight Received', $this->kg((float) $byGrade->sum('wt')), $this->rangeLabel()),
                $this->tile('Factories Receiving', $this->num((float) $byFactory->count())),
            ],
            'charts' => [
                $this->chartSpec('jr-in-time', 'bar', 'Reels Received', $time['labels'],
                    [['name' => 'Reels', 'data' => $time['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('jr-in-factory', 'donut', 'By Factory',
                    $byFactory->pluck('name')->all(),
                    [['name' => 'Reels', 'data' => $byFactory->pluck('cnt')->map(fn ($v) => (float) $v)->all()]]),
                $this->chartSpec('jr-in-grade', 'hbar', 'By Grade Type',
                    $byGrade->pluck('name')->all(),
                    [['name' => 'Reels', 'data' => $byGrade->pluck('cnt')->map(fn ($v) => (float) $v)->all()]]),
            ],
        ];
    }

    protected function consumptionSection(): array
    {
        $pieces = $this->countOver('factory_usage_reel', 'dateofuse', '/', fn ($q) => $q->where('is_deleted', 0));
        $weight = $this->sumOver('factory_usage_reel', 'dateofuse', '/', 'weight', fn ($q) => $q->where('is_deleted', 0));
        $time = $this->series('factory_usage_reel', 'dateofuse', '/', 'SUM(`weight`)', fn ($q) => $q->where('is_deleted', 0));

        [$from, $to] = $this->bounds('/');
        $scoped = fn ($q) => $q->where('u.is_deleted', 0)
            ->when($from, fn ($w) => $w->whereBetween('u.dateofuse', [$from, $to]));

        $byMachine = $scoped($this->db()->table('factory_usage_reel as u'))
            ->selectRaw("COALESCE(NULLIF(u.project,''),'—') as name, SUM(u.weight) as wt")
            ->groupBy('name')->orderByDesc('wt')->limit(12)->get();

        $byShift = $scoped($this->db()->table('factory_usage_reel as u'))
            ->selectRaw("COALESCE(NULLIF(u.shift,''),'—') as name, SUM(u.weight) as wt")
            ->groupBy('name')->orderByDesc('wt')->get();

        return [
            'tiles' => [
                $this->tile('Pieces Unwound', $this->num($pieces), $this->rangeLabel(), 'brand'),
                $this->tile('Weight Unwound', $this->kg($weight), $this->rangeLabel()),
                $this->tile('Machines Running', $this->num((float) $byMachine->count()), $this->rangeLabel()),
            ],
            'charts' => [
                $this->chartSpec('jr-use-time', 'bar', 'Weight Unwound', $time['labels'],
                    [['name' => 'Weight', 'data' => $time['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel(), 'valueFmt' => 'kg']),
                $this->chartSpec('jr-use-machine', 'hbar', 'By Machine',
                    $byMachine->pluck('name')->all(),
                    [['name' => 'Weight', 'data' => $byMachine->pluck('wt')->map(fn ($v) => (float) $v)->all()]],
                    ['valueFmt' => 'kg']),
                $this->chartSpec('jr-use-shift', 'donut', 'By Shift',
                    $byShift->pluck('name')->map(fn ($n) => ucfirst((string) $n))->all(),
                    [['name' => 'Weight', 'data' => $byShift->pluck('wt')->map(fn ($v) => (float) $v)->all()]],
                    ['valueFmt' => 'kg']),
            ],
        ];
    }

    protected function returnsSection(): array
    {
        $isReturn = fn ($q) => $q->where('event', 'return');

        $count = $this->countOver('factory_event', 'date', '/', $isReturn);
        $weight = $this->sumOver('factory_event', 'date', '/', 'weight', $isReturn);
        $time = $this->series('factory_event', 'date', '/', 'COUNT(*)', $isReturn);

        [$from, $to] = $this->bounds('/');
        $scoped = fn () => $this->db()->table('factory_event as e')->where('e.event', 'return')
            ->when($from, fn ($w) => $w->whereBetween('e.date', [$from, $to]));

        $byReason = $scoped()
            ->selectRaw("COALESCE(NULLIF(e.reason,''),'Not given') as name, COUNT(*) as cnt")
            ->groupBy('name')->orderByDesc('cnt')->limit(10)->get();

        $byGrade = $scoped()
            ->leftJoin('bpl_production as prod', 'prod.barcode', '=', 'e.reel_barcode')
            ->leftJoin('bpl_products as pr', 'pr.id', '=', 'prod.product_id')
            ->selectRaw("COALESCE(NULLIF(pr.gradetype,''),'—') as name, COUNT(*) as cnt")
            ->groupBy('name')->orderByDesc('cnt')->limit(10)->get();

        // A remainder is logged under a longer barcode than the reel it came off.
        $shapes = $scoped()
            ->selectRaw("IF(e.barcode <> e.reel_barcode, 'Remainder', 'Whole reel') as name, COUNT(*) as cnt")
            ->groupBy('name')->orderByDesc('cnt')->get();

        return [
            'tiles' => [
                $this->tile('Returned to BPL', $this->num($count), $this->rangeLabel(), $count > 0 ? 'neg' : null),
                $this->tile('Weight Returned', $this->kg($weight), $this->rangeLabel()),
                $this->tile('Reasons Recorded', $this->num((float) $byReason->where('name', '<>', 'Not given')->count())),
            ],
            'charts' => [
                $this->chartSpec('jr-ret-time', 'bar', 'Returns', $time['labels'],
                    [['name' => 'Returns', 'data' => $time['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('jr-ret-reason', 'hbar', 'By Reason',
                    $byReason->pluck('name')->all(),
                    [['name' => 'Returns', 'data' => $byReason->pluck('cnt')->map(fn ($v) => (float) $v)->all()]]),
                $this->chartSpec('jr-ret-shape', 'donut', 'Whole vs Remainder',
                    $shapes->pluck('name')->all(),
                    [['name' => 'Returns', 'data' => $shapes->pluck('cnt')->map(fn ($v) => (float) $v)->all()]]),
                $this->chartSpec('jr-ret-grade', 'hbar', 'By Grade Type',
                    $byGrade->pluck('name')->all(),
                    [['name' => 'Returns', 'data' => $byGrade->pluck('cnt')->map(fn ($v) => (float) $v)->all()]]),
            ],
        ];
    }
}
