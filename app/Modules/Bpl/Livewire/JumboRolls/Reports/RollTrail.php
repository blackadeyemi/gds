<?php

namespace Modules\Bpl\Livewire\JumboRolls\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplWaitingAreaEntry;

/**
 * BPL → Jumbo Rolls → Reports → Roll Trail. One row per roll, carrying every
 * date in its life: made, off the factory floor, into a store, out of it, and
 * then whichever of the TWO ways out it took.
 *
 * ## A roll leaves BPL by one of two doors
 *
 * It goes on a truck to a customer, against a waybill in `bpl_delivery_details`
 * — or it goes to BIL's own factory, where it is scanned into
 * `factory_entrance_reel` and never touches a BPL waybill at all. The second
 * door is the busier one: of the rolls released from a store in the last year
 * and not loaded on a waybill, four in five were received at BIL.
 *
 * Missing that leg is what made the first version of this report useless — it
 * flagged 18,401 perfectly normal rolls as "never loaded". Both doors are
 * followed here, and only the ~3,900 that went through neither are a gap.
 *
 * ## What it replaces
 *
 * The legacy `report_hardroll_movement` page had two options — "Not Scanned in
 * Store" and "Not Scanned in Factory" — each a hand-written anti-join between
 * two of the movement tables, hardrolls only, with no way to see the roll's
 * other scans. Those two questions are the **Gaps** view here, generalised to
 * both streams and to three further breaks nobody had a page for: a roll
 * `bpl_production` calls 'Exited' with no exit row behind it, a roll BIL
 * received that BPL never scanned out, and a roll released from a store that
 * went through neither door.
 *
 * ## Why scalar subqueries rather than joins
 *
 * A barcode can legitimately appear more than once in a movement table — a
 * roll released, put in the waiting area and released again — so joining the
 * six of them would multiply one roll into a dozen rows and every count with
 * it. Each stage is read as its own scalar subquery on the indexed `barcode`
 * column, which cannot fan out; the roll is always exactly one row.
 *
 * The range applies to the date the roll was MADE, so the report follows a
 * day's production forwards rather than mixing partial lives.
 */
#[Title('BPL Roll Trail Report')]
class RollTrail extends JumboRollReport
{
    public function title(): string
    {
        return 'Roll Trail Report';
    }

    public function printKey(): string
    {
        return 'roll-trail';
    }

    public function subtitle(): string
    {
        return 'Every scan in a roll\'s life, and the breaks in the chain.';
    }

    /* ---------------- The stages, named once ---------------- */

    /**
     * Where the roll got to, from the dates the leg selected. Read top down:
     * the first line that matches is the furthest it went.
     */
    private const STAGE = "CASE"
        . " WHEN `waybill` <> '' THEN 'Delivered'"
        . " WHEN `bil_date` <> '' THEN 'Received at BIL'"
        . ' WHEN `held` = 1 THEN \'Waiting area\''
        . " WHEN `exit_date` <> '' THEN 'Released'"
        . " WHEN `entry_date` <> '' THEN 'In a store'"
        . " WHEN `fx_date` <> '' THEN 'Off the factory floor'"
        . " ELSE 'On the factory floor' END";

    /**
     * A break in the chain, or '' when the roll's trail reads properly.
     *
     * The last rule tests BOTH doors — no waybill AND not received at BIL —
     * and applies to both streams, because softrolls go to BIL too (1,160 of
     * them). Testing only the waybill flagged four normal rolls in five.
     */
    private const PROBLEM = "CASE"
        . " WHEN `fx_date` = '' AND `prod_status` = 'Exited' THEN 'Marked exited, no exit scan'"
        . " WHEN `fx_date` = '' AND `entry_date` <> '' THEN 'In a store with no factory exit'"
        . " WHEN `fx_date` = '' AND `bil_date` <> '' THEN 'At BIL with no BPL scans behind it'"
        . " WHEN `fx_date` <> '' AND `entry_date` = '' THEN 'Left the factory, never reached a store'"
        . " WHEN `exit_date` <> '' AND `waybill` = '' AND `bil_date` = '' AND `held` = 0"
        . " THEN 'Released from the store, went nowhere'"
        . " ELSE '' END";

    /* ---------------- How far back it will look ---------------- */

    /**
     * The widest span this report will follow, in days.
     *
     * Every other report reads one table and can be asked for a decade. This
     * one resolves six movement lookups per roll, and the summary views have
     * to resolve them for every roll in the range, not just a page. Measured
     * on dev: a month is 17ms, a quarter 0.5s, a year 2.5s — and all time,
     * 268,000 rolls, is half a minute of a page that looks hung.
     *
     * So the range is CLAMPED rather than refused. The report still answers,
     * over the most recent year of whatever was asked for, and says on the
     * page and in the printout that it did; returning a year's figures under a
     * decade's heading would be the worse failure.
     *
     * (Before 2026_09_25_150000 this was 150x worse still: the waybill lookup
     * compared a latin1 column to a utf8mb3 one and could not use its index,
     * so a quarter took 77 seconds. Fixing the column, not capping the range,
     * was what made this report usable.)
     */
    public const MAX_DAYS = 366;

    /** The range actually queried: [from, to] as Y-m-d, both always set. */
    protected function effectiveRange(): array
    {
        $to = $this->dateTo !== '' ? $this->dateTo : now()->format('Y-m-d');
        $earliest = \Carbon\Carbon::parse($to)->subDays(self::MAX_DAYS - 1)->format('Y-m-d');
        $from = $this->dateFrom !== '' ? $this->dateFrom : $earliest;

        return [max($from, $earliest), $to];
    }

    /** Whether the asked-for range was wider than the report will follow. */
    protected function rangeWasClamped(): bool
    {
        return $this->effectiveRange()[0] !== ($this->dateFrom !== '' ? $this->dateFrom : '');
    }

    /**
     * The clamped range, applied to the date the roll was MADE. Overrides the
     * framework's version, which would use the raw dateFrom/dateTo.
     */
    protected function applyDate($q, string $column, bool $slash = false)
    {
        [$from, $to] = $this->effectiveRange();

        $fmt = fn (string $d) => $slash ? str_replace('-', '/', $d) : $d;

        return $q->whereBetween($column, [$fmt($from), $fmt($to)]);
    }

    /** Say so, in the place the framework already keeps its range warnings. */
    public function slowCountNotice(): ?string
    {
        if (! $this->rangeWasClamped()) {
            return null;
        }

        [$from] = $this->effectiveRange();

        return 'Following every roll through six movement tables is the most expensive '
            . 'question in this module, so the Roll Trail covers a year at a time. These '
            . 'figures start at ' . $this->fmtDate($from) . ' — move the dates back a year '
            . 'at a time to go further.';
    }

    /** …and in the printout, so an export can never claim a range it did not read. */
    public function reportContext(): array
    {
        $out = parent::reportContext();

        if ($this->rangeWasClamped()) {
            [$from, $to] = $this->effectiveRange();
            $out[] = ['Range actually read', $this->fmtDate($from) . ' — ' . $this->fmtDate($to)];
        }

        return $out;
    }

    /* ---------------- Filters ---------------- */

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    protected function options(): array
    {
        return $this->optCache ??= [
            'streams' => $this->shared('streams'),
            'machines' => $this->machines(),
            'grades' => $this->shared('grades'),
            'stages' => [
                'On the factory floor' => 'On the factory floor',
                'Off the factory floor' => 'Off the factory floor',
                'In a store' => 'In a store',
                'Waiting area' => 'Waiting area',
                'Released' => 'Released',
                'Received at BIL' => 'Received at BIL',
                'Delivered' => 'Delivered',
            ],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'stream' => ['label' => 'Type', 'options' => $o['streams'], 'width' => 150],
            'machine' => ['label' => 'Paper Machine', 'options' => $o['machines'], 'width' => 150],
            'gradetype' => ['label' => 'Grade Type', 'options' => $o['grades']],
            'stage' => ['label' => 'Got As Far As', 'options' => $o['stages'], 'width' => 210],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        // Grade comes off the product master, and the stage is derived from the
        // movement subqueries — neither can be counted from production alone.
        return ['gradetype', 'stage'];
    }

    /* ---------------- The query ---------------- */

    /**
     * The day BIL took this roll into its own factory, or ''.
     *
     * `factory_entrance_reel` is a view on this connection onto the `bil`
     * schema, and its `fer_stats_cover` index covers exactly these three
     * columns, so the lookup is a key read rather than a scan.
     */
    private const BIL_RECEIPT = "COALESCE((SELECT `dateofentrance` FROM `factory_entrance_reel`"
        . " WHERE `barcode` = `prod`.`barcode` AND `is_deleted` = 0 ORDER BY `id` DESC LIMIT 1), '')";

    /** The most recent live row's `$column` in `$table` for this roll. */
    private function stageOf(string $table, string $column = 'date'): string
    {
        return "COALESCE((SELECT `{$column}` FROM `{$table}` WHERE `barcode` = `prod`.`barcode`"
            . " AND `deleted_at` IS NULL ORDER BY `id` DESC LIMIT 1), '')";
    }

    protected function leg(string $stream)
    {
        $soft = $stream === 'softroll';

        if ($soft) {
            $q = $this->db()->table('bpl_softroll_production as prod')
                ->leftJoin('bpl_products_softroll as pr', 'pr.id', '=', 'prod.product_id')
                ->leftJoin('bpl_grades as g', 'g.id', '=', 'prod.grade_id')
                ->whereNull('prod.deleted_at');

            $item = "COALESCE(`pr`.`productname`, `g`.`gradename`, CONCAT('Grade #', `prod`.`grade_id`))";
            $grade = '`g`.`type`';
            $rollNo = '`prod`.`softrollnumber`';
            $machine = $this->softrollMachineExpr();
            $fx = $this->stageOf('bpl_softroll_factoryexit');
            $entry = $this->stageOf('bpl_softroll_warehouse_entry');
            $entryPlace = $this->placeName(
                "COALESCE((SELECT `location_id` FROM `bpl_softroll_warehouse_entry`"
                . " WHERE `barcode` = `prod`.`barcode` AND `deleted_at` IS NULL ORDER BY `id` DESC LIMIT 1), 0)"
            );
            $exit = $this->stageOf('bpl_softroll_warehouse_exit');
            // Only hardrolls travel on a waybill.
            $waybill = "''";

            if (($m = (string) ($this->filters['machine'] ?? '')) !== '') {
                $q->where('prod.papermachine', $this->softrollMachineValue($m) ?? -1);
            }
        } else {
            $q = $this->db()->table('bpl_production as prod')
                ->leftJoin('bpl_products_hardroll as pr', 'pr.id', '=', 'prod.product_id')
                ->whereNull('prod.deleted_at');

            $item = "COALESCE(`pr`.`productname`, CONCAT('Product #', `prod`.`product_id`))";
            $grade = '`pr`.`gradetype`';
            $rollNo = '`prod`.`hardrollnumber`';
            $machine = '`prod`.`papermachine`';
            $fx = $this->stageOf('bpl_factoryexit');
            $entry = $this->stageOf('bpl_warehouse_entry');
            $entryPlace = $this->placeName(
                "COALESCE((SELECT `location_id` FROM `bpl_warehouse_entry`"
                . " WHERE `barcode` = `prod`.`barcode` AND `deleted_at` IS NULL ORDER BY `id` DESC LIMIT 1), 0)"
            );
            $exit = $this->stageOf('bpl_warehouse_exit');
            $waybill = "COALESCE((SELECT `delivery_barcode` FROM `bpl_delivery_details`"
                . " WHERE `barcode` = `prod`.`barcode` ORDER BY `id` DESC LIMIT 1), '')";

            if (($m = (string) ($this->filters['machine'] ?? '')) !== '') {
                $q->where('prod.papermachine', $m);
            }
        }

        // Standing in a waiting area right now: one table for both streams.
        $held = 'EXISTS(SELECT 1 FROM `bpl_waiting_area_entry` `wa` WHERE `wa`.`barcode` = `prod`.`barcode`'
            . " AND `wa`.`deleted_at` IS NULL AND (`wa`.`status` IS NULL OR `wa`.`status` <> '"
            . BplWaitingAreaEntry::RELEASED . "'))";

        $this->applyDate($q, 'prod.dateofmanufacture', true);

        if (($this->filters['gradetype'] ?? '') !== '') {
            $q->whereRaw($grade . ' = ?', [$this->filters['gradetype']]);
        }

        return $q->selectRaw(
            '`prod`.`id`,'
            . " '{$stream}' as `stream`,"
            . ' `prod`.`barcode`,'
            . " {$rollNo} as `rollnumber`,"
            . " {$machine} as `machine`,"
            . " {$item} as `item`,"
            . " {$grade} as `gradetype`,"
            . ' `prod`.`dateofmanufacture` as `made`,'
            . ' ROUND(`prod`.`weight`, 2) as `weight`,'
            . " COALESCE(`prod`.`status`, '') as `prod_status`,"
            . " {$fx} as `fx_date`,"
            . " {$entry} as `entry_date`,"
            . " {$entryPlace} as `store`,"
            . " {$exit} as `exit_date`,"
            . " {$waybill} as `waybill`,"
            . ' ' . self::BIL_RECEIPT . ' as `bil_date`,'
            . " {$held} as `held`"
        );
    }

    protected function streamsInPlay(): array
    {
        $stream = (string) ($this->filters['stream'] ?? '');

        return in_array($stream, ['hardroll', 'softroll'], true) ? [$stream] : ['hardroll', 'softroll'];
    }

    /**
     * The rolls, with their stage and any break in the chain worked out from
     * the dates. Wrapped twice so `stage` and `problem` are real columns the
     * filters, the views and the sort can all address by name.
     */
    protected function base()
    {
        $union = null;

        foreach ($this->streamsInPlay() as $stream) {
            $leg = $this->leg($stream);
            $union = $union ? $union->unionAll($leg) : $leg;
        }

        $withStage = $this->db()->query()->fromSub($union, 'l')
            ->selectRaw('`l`.*, ' . self::STAGE . ' as `stage`, ' . self::PROBLEM . ' as `problem`');

        $q = $this->db()->query()->fromSub($withStage, 'r');

        return $this->applyFilters($q, ['stage' => 'stage']);
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['barcode', 'rollnumber', 'item', 'gradetype', 'waybill'];

    public function views(): array
    {
        $trailColumns = [
            ['Barcode', 'barcode'],
            ['Type', 'stream', $this->streamCell()],
            ['Roll No.', 'rollnumber'],
            ['Product', 'item'],
            ['Machine', 'machine'],
            $this->dateCol('Made', 'made'),
            $this->dateCol('Left Factory', 'fx_date'),
            $this->dateCol('Into Store', 'entry_date'),
            ['Store', 'store'],
            $this->dateCol('Out of Store', 'exit_date'),
            ['Waybill', 'waybill'],
            $this->dateCol('Into BIL', 'bil_date'),
            ['Weight (kg)', 'weight', $this->kgCell('weight')],
            ['Got As Far As', 'stage', $this->stageCell()],
        ];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $trailColumns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->select('r.*')->orderByDesc('made')->orderByDesc('id'),
                'totals' => ['weight'],
                'sortable' => ['barcode', 'stream', 'rollnumber', 'item', 'machine', 'made',
                    'fx_date', 'entry_date', 'store', 'exit_date', 'waybill', 'bil_date', 'weight', 'stage'],
            ],

            'gaps' => [
                'label' => 'Gaps in the chain',
                'type' => 'table',
                'columns' => array_merge(
                    array_slice($trailColumns, 0, -1),
                    [['Problem', 'problem', $this->problemCell()]]
                ),
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->select('r.*')
                    ->where('problem', '<>', '')
                    ->orderBy('problem')->orderByDesc('made'),
                'totals' => ['weight'],
                'sortable' => ['barcode', 'stream', 'rollnumber', 'item', 'made', 'weight', 'problem'],
            ],

            'by_stage' => [
                'label' => 'Summary (by stage)',
                'type' => 'summary',
                'columns' => [
                    ['Got As Far As', 'stage', $this->stageCell()],
                    ['Type', 'stream', $this->streamCell()],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw('`stage`, `stream`, COUNT(*) as `quantity`, ROUND(SUM(`weight`), 2) as `weight`')
                    ->groupBy('stage', 'stream')->orderByDesc('quantity'),
            ],

            'by_problem' => [
                'label' => 'Summary (by problem)',
                'type' => 'summary',
                'columns' => [
                    ['Problem', 'problem', $this->problemCell()],
                    ['Type', 'stream', $this->streamCell()],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw('`problem`, `stream`, COUNT(*) as `quantity`, ROUND(SUM(`weight`), 2) as `weight`')
                    ->where('problem', '<>', '')
                    ->groupBy('problem', 'stream')->orderByDesc('quantity'),
            ],
        ];
    }

    /* ---------------- Cells ---------------- */

    private function stageCell(): callable
    {
        $tone = [
            'On the factory floor' => 'badge-warning',
            'Off the factory floor' => 'badge',
            'In a store' => 'badge-success',
            'Waiting area' => 'badge-warning',
            'Released' => 'badge',
            'Received at BIL' => 'badge-muted',
            'Delivered' => 'badge-muted',
        ];

        return fn ($r) => '<span class="' . ($tone[$r->stage ?? ''] ?? 'badge') . '">'
            . e((string) ($r->stage ?? '')) . '</span>';
    }

    private function problemCell(): callable
    {
        return fn ($r) => ($p = (string) ($r->problem ?? '')) === ''
            ? '' : '<span class="badge badge-warning">' . e($p) . '</span>';
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_stage' => ['stage', 'stream'],
            'by_problem' => ['problem', 'stream'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Barcode', 'barcode'],
            ['Roll No.', 'rollnumber'],
            ['Product', 'item'],
            $this->dateCol('Made', 'made'),
            $this->dateCol('Left Factory', 'fx_date'),
            $this->dateCol('Into Store', 'entry_date'),
            $this->dateCol('Out of Store', 'exit_date'),
            ['Waybill', 'waybill'],
            $this->dateCol('Into BIL', 'bil_date'),
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

        return $q->orderByDesc('made')->orderByDesc(DB::raw('`id`'));
    }

    public function detailTitle(string $key): string
    {
        return implode(' · ', array_filter(
            $this->detailKeyParts($key),
            fn ($part) => (string) $part !== ''
        ));
    }
}
