<?php

namespace Modules\Bpl\Livewire\JumboRolls\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplWaitingAreaEntry;
use Modules\Bpl\Support\StockLocations;

/**
 * BPL → Jumbo Rolls → Reports → Waiting Area. Rolls that came back off a truck
 * and stood at a store until they went out again, over
 * `bpl_waiting_area_entry` and `bpl_waiting_area_exit` (the tables the legacy
 * called `bpl_quarantine` / `bpl_quarantine_storeexit`, still readable under
 * those names as views).
 *
 * The legacy had these as two unrelated pages — `report_bpl_quarantine` listed
 * the holds and `report_bpl_quarantine_exit` the releases, with nothing tying a
 * release back to the hold it ended. One row per HOLD, with its release beside
 * it, is the only shape that can answer the question anyone actually asks: how
 * long has this roll been standing there.
 *
 * **Days** is counted to the release for a hold that ended, and to today for
 * one that has not — so an open hold's age keeps growing, which is the point.
 * Its weight is on that store's stock for exactly that time; see the Waiting
 * Area screen for why holding ADDS stock and releasing takes it off.
 */
#[Title('BPL Waiting Area Report')]
class WaitingArea extends JumboRollReport
{
    public function title(): string
    {
        return 'Waiting Area Report';
    }

    public function printKey(): string
    {
        return 'waiting-area';
    }

    public function subtitle(): string
    {
        return 'Rolls held at a store after leaving it, and how long they stood there.';
    }

    /* ---------------- Filters ---------------- */

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    protected function options(): array
    {
        return $this->optCache ??= [
            'streams' => $this->shared('streams'),
            'stores' => StockLocations::options(StockLocations::STORE),
            'states' => ['none' => 'Still held', BplWaitingAreaEntry::RELEASED => 'Released'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'stream' => ['label' => 'Type', 'options' => $o['streams'], 'width' => 150],
            'place' => ['label' => 'Store', 'options' => $o['stores']],
            'status' => ['label' => 'State', 'options' => $o['states'], 'width' => 150],
        ];
    }

    /* ---------------- The query ---------------- */

    /**
     * One leg per stream: the hold, the roll it is, and the release that ended
     * it (if one has).
     *
     * The barcode identifies the stream — a hold row does not say which it is —
     * so the leg is chosen by which production table the barcode is in, the
     * same rule the Waiting Area screen resolves a scan with.
     */
    protected function leg(string $stream)
    {
        $soft = $stream === 'softroll';

        $q = $this->db()->table('bpl_waiting_area_entry as w')
            ->leftJoin('bpl_waiting_area_exit as x', function ($j) {
                $j->on('x.barcode', '=', 'w.barcode')->whereNull('x.deleted_at');
            })
            ->whereNull('w.deleted_at');

        if ($soft) {
            $q->join('bpl_softroll_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'w.barcode')->whereNull('prod.deleted_at');
            })
                ->leftJoin('bpl_products_softroll as pr', 'pr.id', '=', 'prod.product_id')
                ->leftJoin('bpl_grades as g', 'g.id', '=', 'prod.grade_id');

            $item = "COALESCE(`pr`.`productname`, `g`.`gradename`, CONCAT('Grade #', `prod`.`grade_id`))";
            $grade = '`g`.`type`';
            $rollNo = '`prod`.`softrollnumber`';
        } else {
            $q->join('bpl_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'w.barcode')->whereNull('prod.deleted_at');
            })
                ->leftJoin('bpl_products_hardroll as pr', 'pr.id', '=', 'prod.product_id');

            $item = "COALESCE(`pr`.`productname`, CONCAT('Product #', `prod`.`product_id`))";
            $grade = '`pr`.`gradetype`';
            $rollNo = '`prod`.`hardrollnumber`';
        }

        $this->applyDate($q, 'w.date', true);
        $this->applyNullable($q, 'status', 'w.status');
        $this->applyFilters($q, ['place' => 'w.location_id']);

        // Open holds count to today, closed ones to the day they were released.
        $days = "DATEDIFF(COALESCE(STR_TO_DATE(`x`.`date`, '%Y/%m/%d'), CURDATE()),"
            . " STR_TO_DATE(`w`.`date`, '%Y/%m/%d'))";

        return $q->selectRaw(
            '`w`.`id`,'
            . " '{$stream}' as `stream`,"
            . ' `w`.`barcode`,'
            . " {$rollNo} as `rollnumber`,"
            . " {$item} as `item`,"
            . " {$grade} as `gradetype`,"
            . ' ' . $this->placeName('`w`.`location_id`') . ' as `place`,'
            . ' `w`.`user` as `held_by`,'
            . ' `w`.`date` as `date`,'
            . " COALESCE(`x`.`date`, '') as `released_on`,"
            . " COALESCE(`x`.`user`, '') as `released_by`,"
            . " {$days} as `days`,"
            . ' ROUND(`prod`.`weight`, 2) as `weight`,'
            . ' `w`.`status` as `status`'
        );
    }

    protected function streamsInPlay(): array
    {
        $stream = (string) ($this->filters['stream'] ?? '');

        return in_array($stream, ['hardroll', 'softroll'], true) ? [$stream] : ['hardroll', 'softroll'];
    }

    protected function base()
    {
        $union = null;

        foreach ($this->streamsInPlay() as $stream) {
            $leg = $this->leg($stream);
            $union = $union ? $union->unionAll($leg) : $leg;
        }

        return $this->db()->query()->fromSub($union, 'r');
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['barcode', 'rollnumber', 'item', 'held_by', 'released_by'];

    public function views(): array
    {
        $state = $this->statusCell(
            [BplWaitingAreaEntry::RELEASED => ['Released', 'badge-muted']],
            'Still held',
            'badge-warning'
        );

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Type', 'stream', $this->streamCell()],
                    ['Roll No.', 'rollnumber'],
                    ['Product', 'item'],
                    ['Store', 'place'],
                    ['Held By', 'held_by'],
                    $this->dateCol('Held Since', 'date'),
                    $this->dateCol('Released', 'released_on'),
                    // An age, never a total — see totalFields() in the base.
                    ['Days', 'days'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                    ['State', 'status', $state],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->select('r.*')->orderByDesc('date')->orderByDesc('id'),
                'totals' => ['weight'],
                'sortable' => ['barcode', 'stream', 'rollnumber', 'item', 'place', 'held_by',
                    'date', 'released_on', 'days', 'weight'],
            ],

            'by_store' => [
                'label' => 'Summary (by store)',
                'type' => 'summary',
                'columns' => [
                    ['Store', 'place'],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                    ['Longest (days)', 'oldest'],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw('`place`, COUNT(*) as `quantity`, ROUND(SUM(`weight`), 2) as `weight`,'
                        . ' MAX(`days`) as `oldest`')
                    ->groupBy('place')->orderByDesc('quantity'),
            ],

            'by_day' => [
                'label' => 'Summary (by day)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Held Since', 'date'),
                    ['Store', 'place'],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw('`date`, `place`, COUNT(*) as `quantity`, ROUND(SUM(`weight`), 2) as `weight`')
                    ->groupBy('date', 'place')->orderByDesc('date')->orderBy('place'),
            ],
        ];
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_store' => ['place'],
            'by_day' => ['date', 'place'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Barcode', 'barcode'],
            ['Type', 'stream', $this->streamCell()],
            ['Product', 'item'],
            ['Store', 'place'],
            $this->dateCol('Held Since', 'date'),
            $this->dateCol('Released', 'released_on'),
            ['Days', 'days'],
            ['Weight (kg)', 'weight', $this->kgCell('weight')],
        ];
    }

    public function detailSearchable(): array
    {
        return ['barcode', 'rollnumber', 'item'];
    }

    public function detailQuery(string $key)
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        $q = $this->base()->select('r.*');

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            $q->where($field, $value);
        }

        return $q->orderByDesc(DB::raw('`days`'));
    }

    public function detailTitle(string $key): string
    {
        return implode(' · ', array_filter(
            $this->detailKeyParts($key),
            fn ($part) => (string) $part !== ''
        ));
    }
}
