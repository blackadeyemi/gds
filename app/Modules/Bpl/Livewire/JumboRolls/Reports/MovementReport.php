<?php

namespace Modules\Bpl\Livewire\JumboRolls\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Shared body for the three scan reports — Factory Exit, Warehouse Entry and
 * Warehouse Exit.
 *
 * The six tables behind them (three movements × two streams) carry the SAME
 * seven columns: who scanned it, which barcode, at which place, on what date,
 * and what happened to it next. They differ only in the roll master each
 * stream joins for the weight and the product name. So the query, the four
 * views, the filters and the drill-down are written once here, and a concrete
 * report supplies its tables, its wording and its status vocabulary.
 *
 * Neither movement table carries a weight — the weight is the ROLL's, taken
 * from production, so a report and a reprint of it always agree with what BPL
 * says it made.
 */
abstract class MovementReport extends JumboRollReport
{
    /* ---------------- What a concrete report supplies ---------------- */

    /** ['hardroll' => table, 'softroll' => table]. */
    abstract protected function tables(): array;

    /** Heading for the date column ('Date of Exit', 'Date Received', …). */
    abstract protected function dateLabel(): string;

    /** Heading for the place column ('Factory', 'Warehouse'). */
    abstract protected function placeLabel(): string;

    /** Which places the filter offers: the paper machine floors or the stores. */
    abstract protected function placeOptions(): array;

    /**
     * The `status` filter's options and how a value reads in a cell.
     *
     * @return array{options:array<string,string>, map:array<string,array{0:string,1:string}>, null:string, nullClass:string}
     */
    abstract protected function statusSpec(): array;

    /* ---------------- Filters ---------------- */

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    protected function options(): array
    {
        return $this->optCache ??= [
            'streams' => $this->shared('streams'),
            'places' => $this->placeOptions(),
            'grades' => $this->shared('grades'),
            'statuses' => $this->statusSpec()['options'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'stream' => ['label' => 'Type', 'options' => $o['streams'], 'width' => 150],
            'place' => ['label' => $this->placeLabel(), 'options' => $o['places']],
            'gradetype' => ['label' => 'Grade Type', 'options' => $o['grades']],
            'status' => ['label' => 'Status', 'options' => $o['statuses']],
        ];
    }

    /**
     * Grade lives on the joined product master, so a join-free count would be
     * wrong whenever it is set. Type and place are on the movement row itself
     * and stay cheap.
     */
    protected function joinedFilterKeys(): array
    {
        return ['gradetype'];
    }

    /* ---------------- The query ---------------- */

    /**
     * One leg of the union, filtered INSIDE rather than outside.
     *
     * Pushing the date and the place into each leg is what lets MySQL use the
     * (deleted_at, date) index added for these reports; filtering the union
     * from outside would read both tables whole and throw most of it away.
     */
    protected function leg(string $stream)
    {
        $table = $this->tables()[$stream];

        $q = $this->db()->table($table . ' as m')->whereNull('m.deleted_at');

        if ($stream === 'softroll') {
            $q->leftJoin('bpl_softroll_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'm.barcode')->whereNull('prod.deleted_at');
            })
                ->leftJoin('bpl_products_softroll as pr', 'pr.id', '=', 'prod.product_id')
                ->leftJoin('bpl_grades as g', 'g.id', '=', 'prod.grade_id');

            $item = "COALESCE(`pr`.`productname`, `g`.`gradename`, CONCAT('Grade #', `prod`.`grade_id`))";
            $grade = '`g`.`type`';
            $rollNo = '`prod`.`softrollnumber`';
        } else {
            $q->leftJoin('bpl_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'm.barcode')->whereNull('prod.deleted_at');
            })
                ->leftJoin('bpl_products_hardroll as pr', 'pr.id', '=', 'prod.product_id');

            $item = "COALESCE(`pr`.`productname`, CONCAT('Product #', `prod`.`product_id`))";
            $grade = '`pr`.`gradetype`';
            $rollNo = '`prod`.`hardrollnumber`';
        }

        $this->applyDate($q, 'm.date', true);
        $this->applyNullable($q, 'status', 'm.status');
        $this->applyFilters($q, ['place' => 'm.location_id']);

        if (($this->filters['gradetype'] ?? '') !== '') {
            $q->whereRaw($grade . ' = ?', [$this->filters['gradetype']]);
        }

        return $q->selectRaw(
            '`m`.`id`,'
            . " '{$stream}' as `stream`,"
            . ' `m`.`barcode`,'
            . " {$rollNo} as `rollnumber`,"
            . " {$item} as `item`,"
            . " {$grade} as `gradetype`,"
            . ' ' . $this->placeName('`m`.`location_id`') . ' as `place`,'
            . ' `m`.`user` as `user`,'
            . ' `m`.`date` as `date`,'
            . ' ROUND(`prod`.`weight`, 2) as `weight`,'
            . ' `m`.`status` as `status`'
        );
    }

    /** Which streams the Type filter leaves in play. */
    protected function streamsInPlay(): array
    {
        $stream = $this->filters['stream'] ?? '';

        return $stream !== '' && $stream !== 'all' && isset($this->tables()[$stream])
            ? [$stream]
            : ['hardroll', 'softroll'];
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

    /**
     * Counting: the movement tables alone, with only their own columns
     * filtered. Used whenever no joined filter and no search is active.
     */
    protected function countQuery()
    {
        $legs = null;

        foreach ($this->streamsInPlay() as $stream) {
            $q = $this->db()->table($this->tables()[$stream] . ' as m')
                ->whereNull('m.deleted_at')->selectRaw('1 as `n`');
            $this->applyDate($q, 'm.date', true);
            $this->applyNullable($q, 'status', 'm.status');
            $this->applyFilters($q, ['place' => 'm.location_id']);

            $legs = $legs ? $legs->unionAll($q) : $q;
        }

        return $this->db()->query()->fromSub($legs, 'c');
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['barcode', 'rollnumber', 'item', 'gradetype', 'user'];

    public function views(): array
    {
        $spec = $this->statusSpec();
        $place = $this->placeLabel();

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Type', 'stream', $this->streamCell()],
                    ['Roll No.', 'rollnumber'],
                    ['Product', 'item'],
                    ['Grade', 'gradetype'],
                    [$place, 'place'],
                    ['Scanned By', 'user'],
                    $this->dateCol($this->dateLabel(), 'date'),
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                    ['Status', 'status', $this->statusCell($spec['map'], $spec['null'], $spec['nullClass'])],
                ],
                'searchable' => self::SEARCHABLE,
                // The date first: within a day the union's two id spaces are
                // separate sequences, so id alone would interleave the streams
                // in no meaningful order.
                'query' => fn () => $this->base()->select('r.*')->orderByDesc('date')->orderByDesc('id'),
                'totals' => ['weight'],
                'sortable' => ['barcode', 'stream', 'rollnumber', 'item', 'gradetype', 'place', 'user', 'date', 'weight'],
            ],

            'by_place_grade' => [
                'label' => 'Summary (by ' . strtolower($place) . ', grade)',
                'type' => 'summary',
                'columns' => [
                    [$place, 'place'],
                    ['Grade', 'gradetype'],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw('`place`, `gradetype`, COUNT(*) as `quantity`, ROUND(SUM(`weight`), 2) as `weight`')
                    ->groupBy('place', 'gradetype')
                    ->orderBy('place')->orderBy('gradetype'),
            ],

            'by_item' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Type', 'stream', $this->streamCell()],
                    ['Grade', 'gradetype'],
                    ['Product', 'item'],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw('`stream`, `gradetype`, `item`, COUNT(*) as `quantity`, ROUND(SUM(`weight`), 2) as `weight`')
                    ->groupBy('stream', 'gradetype', 'item')
                    ->orderByDesc(DB::raw('SUM(`weight`)')),
            ],

            'by_day' => [
                'label' => 'Summary (by day)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol($this->dateLabel(), 'date'),
                    [$place, 'place'],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw('`date`, `place`, COUNT(*) as `quantity`, ROUND(SUM(`weight`), 2) as `weight`')
                    ->groupBy('date', 'place')
                    ->orderByDesc('date')->orderBy('place'),
            ],
        ];
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_place_grade' => ['place', 'gradetype'],
            'by_item' => ['stream', 'gradetype', 'item'],
            'by_day' => ['date', 'place'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Barcode', 'barcode'],
            ['Type', 'stream', $this->streamCell()],
            ['Roll No.', 'rollnumber'],
            ['Product', 'item'],
            [$this->placeLabel(), 'place'],
            ['Scanned By', 'user'],
            $this->dateCol($this->dateLabel(), 'date'),
            ['Weight (kg)', 'weight', $this->kgCell('weight')],
        ];
    }

    public function detailSearchable(): array
    {
        return ['barcode', 'rollnumber', 'item', 'user'];
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

        return $q->orderByDesc('date')->orderByDesc('id');
    }

    public function detailTitle(string $key): string
    {
        return implode(' · ', array_filter(
            $this->detailKeyParts($key),
            fn ($part) => (string) $part !== ''
        ));
    }
}
