<?php

namespace Modules\Bil\Livewire\JumboRolls\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * Jumbo Rolls → Reports → Returns. Reels sent back to BPL, over the `'return'`
 * rows of `factory_event`.
 *
 * `factory_event` holds both kinds of event, so this reads only `event =
 * 'return'`; the `'remain'` rows are leftovers still on the floor and belong to
 * a Remaining report, not here. The row carries what actually went back — a
 * whole reel's weight, or what was left of a part-used one — so its own weight
 * is the number to sum.
 *
 * `reason` is optional at entry, so the by-reason summary names the blanks
 * rather than dropping them: "not given" is the most useful row on that view if
 * it is the biggest.
 */
#[Title('Jumbo Roll Returns Report')]
class Returns extends JumboRollReport
{
    protected ?array $optCache = null;

    /** What the summary calls a return with no reason recorded. */
    private const NO_REASON = 'Not given';

    public function title(): string
    {
        return 'Returns Report';
    }

    public function printKey(): string
    {
        return 'returns';
    }

    public function pageKey(): string
    {
        return 'bil.jumbo_rolls.reports.returns';
    }

    public function subtitle(): string
    {
        return 'Jumbo rolls sent back to BPL, whole or as a remainder.';
    }

    protected function options(): array
    {
        return $this->optCache ??= [
            'grades' => $this->grades(),
            'reasons' => DB::connection('bil')->table('factory_event')
                ->where('event', 'return')->whereNotNull('reason')->where('reason', '<>', '')
                ->select('reason')->distinct()->orderBy('reason')
                ->pluck('reason', 'reason')->all(),
            'shapes' => ['whole' => 'Whole reel', 'part' => 'Remainder'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'gradetype' => ['label' => 'Grade Type', 'options' => $o['grades']],
            'shape' => ['label' => 'Returned As', 'options' => $o['shapes']],
            'reason' => ['label' => 'Reason', 'options' => $o['reasons']],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['gradetype'];
    }

    /**
     * Whole reel or remainder, told apart by the barcode.
     *
     * A remainder is logged under the parent code plus a suffix, so it has more
     * segments than the reel it came off. Nothing stores the distinction, and
     * inventing a column for it would mean backfilling a guess over history.
     */
    private const IS_REMAINDER = "e.`barcode` <> e.`reel_barcode`";

    protected function base()
    {
        $q = DB::connection('bil')->table('factory_event as e')
            ->leftJoin('bpl_production as prod', 'prod.barcode', '=', 'e.reel_barcode')
            ->leftJoin('bpl_products as pr', 'pr.id', '=', 'prod.product_id')
            ->where('e.event', 'return');

        $this->applyDate($q, 'e.date', true);
        $this->applyFilters($q, [
            'gradetype' => 'pr.gradetype',
            'reason' => 'e.reason',
        ]);

        $shape = $this->filters['shape'] ?? '';
        if ($shape === 'whole') {
            $q->whereRaw('NOT (' . self::IS_REMAINDER . ')');
        } elseif ($shape === 'part') {
            $q->whereRaw(self::IS_REMAINDER);
        }

        return $q;
    }

    public function views(): array
    {
        $searchable = ['e.barcode', 'e.productname', 'e.reason', 'pr.gradetype'];

        $shape = fn ($row) => $row->is_remainder
            ? '<span class="badge badge-warning">Remainder</span>'
            : '<span class="badge badge-success">Whole reel</span>';

        $reason = fn ($row) => trim((string) $row->reason) === ''
            ? '<span class="text-muted">' . self::NO_REASON . '</span>'
            : e($row->reason);

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Product', 'productname'],
                    ['Grade', 'gradetype'],
                    ['Returned As', 'is_remainder', $shape],
                    ['Reason', 'reason', $reason],
                    $this->dateCol('Date', 'date'),
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->select('e.id', 'e.barcode', 'e.productname', 'pr.gradetype', 'e.reason', 'e.date',
                        DB::raw('ROUND(e.weight, 2) as weight'),
                        DB::raw(self::IS_REMAINDER . ' as is_remainder'))
                    ->orderByDesc('e.id'),
            ],
            'by_reason' => [
                'label' => 'Summary (by reason)',
                'type' => 'summary',
                'columns' => [
                    ['Reason', 'reason'],
                    ['Returns', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw("COALESCE(NULLIF(e.`reason`, ''), '" . self::NO_REASON . "') as reason,"
                        . ' COUNT(*) as quantity, ROUND(SUM(e.`weight`), 2) as weight')
                    ->groupBy('reason')->orderByDesc(DB::raw('COUNT(*)')),
            ],
            'by_grade' => [
                'label' => 'Summary (by grade)',
                'type' => 'summary',
                'columns' => [
                    ['Grade', 'gradetype'],
                    ['Returns', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('pr.gradetype, COUNT(*) as quantity, ROUND(SUM(e.`weight`), 2) as weight')
                    ->groupBy('pr.gradetype')->orderByDesc(DB::raw('SUM(e.`weight`)')),
            ],
            'by_day' => [
                'label' => 'Summary (by day)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Date', 'date'),
                    ['Returns', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('e.`date`, COUNT(*) as quantity, ROUND(SUM(e.`weight`), 2) as weight')
                    ->groupBy('e.date')->orderByDesc('e.date'),
            ],
        ];
    }

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_reason' => ['reason'],
            'by_grade' => ['gradetype'],
            'by_day' => ['date'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Barcode', 'barcode'],
            ['Product', 'productname'],
            ['Reason', 'reason'],
            $this->dateCol('Date', 'date'),
            ['Weight (kg)', 'weight'],
        ];
    }

    public function detailSearchable(): array
    {
        return ['e.barcode', 'e.productname', 'e.reason'];
    }

    public function detailQuery(string $key)
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        $q = $this->base()->select('e.id', 'e.barcode', 'e.productname', 'e.reason', 'e.date',
            DB::raw('ROUND(e.weight, 2) as weight'));

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            match ($field) {
                // The summary shows blanks under a label; clicking it must find them.
                'reason' => $value === self::NO_REASON
                    ? $q->where(fn ($w) => $w->whereNull('e.reason')->orWhere('e.reason', ''))
                    : $q->where('e.reason', $value),
                'gradetype' => $q->where('pr.gradetype', $value),
                'date' => $q->where('e.date', $value),
                default => null,
            };
        }

        return $q->orderByDesc('e.id');
    }
}
