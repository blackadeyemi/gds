<?php

namespace Modules\Bpl\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Jumbo Rolls → Warehouse Stock.
 *
 * What each BPL warehouse is holding — and the link between Warehouse Entry and
 * Warehouse Exit, because that is literally what stock is: a roll that has been
 * entered and not yet released.
 *
 * ## Two figures, deliberately
 *
 * **Held** is derived from the entry rows every time it is asked for: an entry
 * that Warehouse Exit has not released. See positionQuery() for exactly how
 * "not released" is tested and why.
 *
 * **Recorded** is `bpl_hardroll_stock` / `bpl_softroll_stock` — the maintained aggregates the
 * entry and exit screens increment, and the ones the legacy BPL reports read.
 *
 * Nothing had ever compared the two. That is the whole point of this page: an
 * aggregate that is only ever incremented drifts silently, and until now a
 * missed movement or a delete that did not reverse was permanent and
 * undetectable. Now it shows up as a row that disagrees — Stock mismatches
 * lists them, and Movement anomalies lists the smaller class of problem where
 * the entry rows themselves are inconsistent with the exit rows.
 *
 * ## Why the two streams look different
 *
 * `bpl_hardroll_stock` is keyed by PRODUCT, `bpl_softroll_stock` by GRADE — softroll stock
 * predates `bpl_products_softroll`. The grid keeps them side by side rather than
 * pretending they are the same shape: the Item column says which it is.
 *
 * Read-only. The screens that move rolls own the figures; a repair belongs in a
 * command that can be reviewed, not a button on a list.
 */
#[Title('BPL Warehouse Stock')]
class WarehouseStock extends DataGrid
{
    public function pageKey(): string { return 'bpl.jumbo-rolls.warehouse-stock'; }
    public function pageLabel(): string { return 'BPL Warehouse Stock'; }
    public function pageSubtitle(): string
    {
        return 'What each BPL warehouse holds — entries that have not been released — '
            . 'against the figure the stock tables record.';
    }

    public function defaultSort(): array { return ['weight', 'desc']; }

    public function views(): array
    {
        return [
            // First, so it is the default: no data_pages row is configured for
            // this page, and DataGrid opens on the first declared view.
            'by_barcode' => [
                'label' => 'By barcode',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Type', 'stream', fn ($r) => $this->streamBadge($r)],
                    ['Warehouse', 'warehouse'],
                    ['Item', 'item'],
                    ['Roll No', 'rollnumber'],
                    ['Weight (kg)', 'weight', fn ($r) => number_format((float) $r->weight, 2)],
                    ['Received', 'received'],
                    ['Days', 'days'],
                ],
                'query' => fn () => $this->filteredRolls(),
                // Weight only. `days` is an age: never totalled.
                'totals' => ['weight'],
                'searchable' => ['barcode', 'rollnumber', 'item'],
                'sortable' => ['barcode', 'warehouse', 'item', 'rollnumber', 'weight', 'received', 'days'],
            ],

            'by_item' => [
                'label' => 'By item',
                'type' => 'table',
                'columns' => [
                    ['Warehouse', 'warehouse'],
                    ['Type', 'stream', fn ($r) => $this->streamBadge($r)],
                    ['Item', 'item'],
                    ['Rolls Held', 'rolls'],
                    ['Weight Held (kg)', 'weight', fn ($r) => number_format((float) $r->weight, 2)],
                    ['Recorded Rolls', 'recorded_qty'],
                    ['Recorded Weight', 'recorded_weight', fn ($r) => number_format((float) $r->recorded_weight, 2)],
                    ['Agrees', 'agrees', fn ($r) => $this->agreesCell($r)],
                ],
                'query' => fn () => $this->filtered(),
                'totals' => ['rolls', 'weight', 'recorded_qty', 'recorded_weight'],
                'searchable' => ['warehouse', 'item'],
                'sortable' => ['warehouse', 'item', 'rolls', 'weight', 'recorded_qty', 'recorded_weight'],
            ],

            'by_warehouse' => [
                'label' => 'By warehouse',
                'type' => 'table',
                'columns' => [
                    ['Warehouse', 'warehouse'],
                    ['Items', 'items'],
                    ['Rolls Held', 'rolls'],
                    ['Weight Held (kg)', 'weight', fn ($r) => number_format((float) $r->weight, 2)],
                ],
                'query' => fn () => DB::connection('bpl')->query()
                    ->fromSub($this->filtered(), 'p')
                    ->groupBy('warehouse')
                    ->selectRaw('`warehouse`, COUNT(*) as `items`, SUM(`rolls`) as `rolls`, SUM(`weight`) as `weight`')
                    ->orderByDesc('weight'),
                'totals' => ['items', 'rolls', 'weight'],
                'searchable' => [],
                'sortable' => [],
            ],

            'mismatches' => [
                'label' => 'Stock mismatches',
                'type' => 'table',
                'columns' => [
                    ['Warehouse', 'warehouse'],
                    ['Type', 'stream', fn ($r) => $this->streamBadge($r)],
                    ['Item', 'item'],
                    ['Rolls Held', 'rolls'],
                    ['Recorded Rolls', 'recorded_qty'],
                    ['Roll Difference', 'qty_diff', fn ($r) => $this->diffCell((float) $r->rolls - (float) $r->recorded_qty)],
                    ['Weight Held (kg)', 'weight', fn ($r) => number_format((float) $r->weight, 2)],
                    ['Recorded Weight', 'recorded_weight', fn ($r) => number_format((float) $r->recorded_weight, 2)],
                ],
                // Only what needs looking at. A stock table that has never been
                // reconciled will have a tail of these; the page exists so the
                // tail can be seen and worked down.
                'query' => fn () => DB::connection('bpl')->query()
                    ->fromSub($this->filtered(), 'p')
                    ->whereRaw('`rolls` <> `recorded_qty` OR ABS(`weight` - `recorded_weight`) > 0.01')
                    ->orderByDesc(DB::connection('bpl')->raw('ABS(`weight` - `recorded_weight`)')),
                'totals' => ['rolls', 'recorded_qty', 'weight', 'recorded_weight'],
                'searchable' => [],
                'sortable' => [],
            ],
            'anomalies' => [
                'label' => 'Movement anomalies',
                'type' => 'table',
                'columns' => [
                    ['Type', 'stream', fn ($r) => $this->streamBadge($r)],
                    ['Barcode', 'barcode'],
                    ['Warehouse', 'warehouse'],
                    ['Received', 'entry_date'],
                    ['Problem', 'problem', fn ($r) => $this->problemCell($r)],
                ],
                // The exact anti-join the position deliberately does not do —
                // ~1.5s, so it runs only when somebody asks for it.
                'query' => fn () => $this->anomalyQuery(),
                'searchable' => [],
                'sortable' => [],
            ],
        ];
    }

    /**
     * Entries whose `status` disagrees with the exit table, either way round:
     * released without the flag being stamped, or flagged with no release
     * behind it. Both mean a roll is counted wrongly somewhere.
     */
    protected function anomalyQuery()
    {
        $conn = DB::connection('bpl');

        $leg = fn (string $entry, string $exit, string $stream) => $conn->table($entry . ' as we')
            ->leftJoin($exit . ' as wx', function ($j) {
                $j->on('wx.barcode', '=', 'we.barcode')->whereNull('wx.deleted_at');
            })
            ->whereNull('we.deleted_at')
            ->whereRaw('(`we`.`status` IS NULL) <> (`wx`.`barcode` IS NULL)')
            ->selectRaw(
                "'{$stream}' as `stream`, `we`.`barcode`, `we`.`date` as `entry_date`,"
                . ' ' . $this->warehouseName('`we`.`location_id`') . ' as `warehouse`,'
                . " CASE WHEN `we`.`status` IS NULL THEN 'released without the flag'"
                . " ELSE 'flagged with no release' END as `problem`"
            );

        return $conn->query()->fromSub(
            $leg('bpl_warehouse_entry', 'bpl_warehouse_exit', 'hardroll')
                ->unionAll($leg('bpl_softroll_warehouse_entry', 'bpl_softroll_warehouse_exit', 'softroll')),
            'anomaly'
        );
    }

    /* ---------------- Filters ---------------- */

    /**
     * The filters, outermost first. Each narrows the options offered by the
     * ones after it: picking PM3 Store leaves Item offering only what PM3 Store
     * holds.
     *
     * filter key => the column it filters on.
     */
    private const FILTER_CASCADE = [
        'warehouse' => 'warehouse',
        'stream' => 'stream',
        'item' => 'item',
    ];

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    /**
     * Options come from the POSITION, not the master tables.
     *
     * Offering all 4,388 hardroll products when 392 items are held makes the
     * dropdown useless, and offering a warehouse that holds none of what is
     * already selected is the same mistake one level down. The position is a
     * few hundred rows, so it is fetched once and the cascade applied in
     * memory rather than as a query per dropdown.
     */
    protected function options(): array
    {
        if ($this->optCache !== null) {
            return $this->optCache;
        }

        $pool = DB::connection('bpl')->query()
            ->fromSub($this->positionQuery(), 'p')
            ->select('warehouse', 'stream', 'item')
            ->get();

        $options = [];

        foreach (self::FILTER_CASCADE as $filter => $column) {
            $options[$filter] = $pool->pluck($column)
                ->filter(fn ($v) => (string) $v !== '')
                ->unique()->sort()->values()
                ->mapWithKeys(fn ($v) => [$v => $v])->all();

            // Everything after this dropdown sees only what this choice leaves.
            $chosen = $this->filters[$filter] ?? '';
            if ($chosen !== '' && $chosen !== 'all') {
                $pool = $pool->where($column, $chosen);
            }
        }

        return $this->optCache = $options;
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'warehouse' => ['label' => 'Warehouse', 'options' => $o['warehouse'], 'width' => 210],
            // Hardroll or softroll. Two values, but the column it filters is the
            // one thing a reader most often wants to split this page by.
            'stream' => ['label' => 'Type', 'options' => $o['stream'], 'width' => 150],
            'item' => ['label' => 'Item', 'options' => $o['item'], 'width' => 260],
        ];
    }

    /**
     * Changing a filter clears the ones it narrows, and drops the options
     * cache — what each dropdown may offer has just changed.
     */
    public function updatedFilters($value = null, $key = null): void
    {
        $order = array_keys(self::FILTER_CASCADE);
        $position = $key === null ? false : array_search($key, $order, true);

        if ($position !== false) {
            foreach (array_slice($order, $position + 1) as $filter) {
                $this->filters[$filter] = '';
            }
        }

        $this->optCache = null;

        parent::updatedFilters($value, $key);
    }

    /** The position with the chosen filters applied — what every view reads. */
    protected function filtered()
    {
        $q = DB::connection('bpl')->query()->fromSub($this->positionQuery(), 'f')
            ->select('f.*');

        return $this->applyFilters($q, self::FILTER_CASCADE);
    }

    /** Held rolls with the chosen filters applied — the By barcode view. */
    protected function filteredRolls()
    {
        $q = DB::connection('bpl')->query()->fromSub($this->rollQuery(), 'r')->select('r.*');

        return $this->applyFilters($q, self::FILTER_CASCADE);
    }

    /**
     * One row per held roll, both streams. Same definition of "held" as
     * positionQuery() (entry live, status NULL) and the same three filter
     * columns, so the filters and the item totals agree with this list.
     */
    protected function rollQuery()
    {
        $conn = DB::connection('bpl');
        $days = "DATEDIFF(CURDATE(), STR_TO_DATE(`we`.`date`, '%Y/%m/%d'))";

        $hardroll = $conn->table('bpl_warehouse_entry as we')
            ->join('bpl_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'we.barcode')->whereNull('prod.deleted_at');
            })
            ->leftJoin('bpl_products_hardroll as pr', 'pr.id', '=', 'prod.product_id')
            ->whereNull('we.deleted_at')
            ->whereNull('we.status')
            ->selectRaw(
                '`we`.`barcode`,'
                . " 'hardroll' as `stream`,"
                . ' ' . $this->warehouseName('`we`.`location_id`') . ' as `warehouse`,'
                . " COALESCE(`pr`.`productname`, CONCAT('Product #', `prod`.`product_id`)) as `item`,"
                . ' `prod`.`hardrollnumber` as `rollnumber`,'
                . ' ROUND(`prod`.`weight`, 2) as `weight`,'
                . ' `we`.`date` as `received`,'
                . " {$days} as `days`"
            );

        $softroll = $conn->table('bpl_softroll_warehouse_entry as we')
            ->join('bpl_softroll_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'we.barcode')->whereNull('prod.deleted_at');
            })
            ->leftJoin('bpl_grades as g', 'g.id', '=', 'prod.grade_id')
            ->whereNull('we.deleted_at')
            ->whereNull('we.status')
            ->selectRaw(
                '`we`.`barcode`,'
                . " 'softroll' as `stream`,"
                . ' ' . $this->warehouseName('`we`.`location_id`') . ' as `warehouse`,'
                . " COALESCE(`g`.`type`, CONCAT('Grade #', `prod`.`grade_id`)) as `item`,"
                . ' `prod`.`softrollnumber` as `rollnumber`,'
                . ' ROUND(`prod`.`weight`, 2) as `weight`,'
                . ' `we`.`date` as `received`,'
                . " {$days} as `days`"
            );

        return $conn->query()->fromSub($hardroll->unionAll($softroll), 'rolls');
    }

    /* ---------------- The position ---------------- */

    /**
     * Held stock for both streams, with the recorded figure beside it.
     *
     * Held is `warehouse_entry.status IS NULL` — the flag Warehouse Exit stamps
     * — rather than an anti-join against the exit table.
     *
     * The anti-join is the purer definition (the exit row is the movement; the
     * status is a denormalised copy) and it was what this used at first. But it
     * has to scan all 133,176 entries and probe the exit table once each: 2.2 s
     * a go, four times a render, and the page took 14 seconds. The flag is
     * indexed and returns the identical 392 groups in 187 ms.
     *
     * That trade is only safe because the disagreement is VISIBLE rather than
     * assumed away: the Movement anomalies view runs the exact anti-join on
     * demand and lists every row where the two differ — 3 of 2,631 today.
     * Without that view this shortcut would be the sort of quiet approximation
     * that makes a stock figure stop meaning anything.
     */
    protected function positionQuery()
    {
        $conn = DB::connection('bpl');

        $hardroll = $conn->table('bpl_warehouse_entry as we')
            ->join('bpl_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'we.barcode')->whereNull('prod.deleted_at');
            })
            ->leftJoin('bpl_products_hardroll as pr', 'pr.id', '=', 'prod.product_id')
            ->leftJoin('bpl_hardroll_stock as st', function ($j) {
                $j->on('st.location_id', '=', 'we.location_id')->on('st.product_id', '=', 'prod.product_id');
            })
            ->whereNull('we.deleted_at')
            ->whereNull('we.status')
            ->groupBy('we.location_id', 'prod.product_id', 'pr.productname', 'st.quantity', 'st.weight')
            ->selectRaw(
                $this->warehouseName('`we`.`location_id`') . ' as `warehouse`,'
                . " 'hardroll' as `stream`,"
                . " COALESCE(`pr`.`productname`, CONCAT('Product #', `prod`.`product_id`)) as `item`,"
                . ' COUNT(*) as `rolls`,'
                . ' ROUND(SUM(`prod`.`weight`), 2) as `weight`,'
                . ' COALESCE(`st`.`quantity`, 0) as `recorded_qty`,'
                . ' ROUND(COALESCE(`st`.`weight`, 0), 2) as `recorded_weight`'
            );

        $softroll = $conn->table('bpl_softroll_warehouse_entry as we')
            ->join('bpl_softroll_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'we.barcode')->whereNull('prod.deleted_at');
            })
            ->leftJoin('bpl_grades as g', 'g.id', '=', 'prod.grade_id')
            // bpl_softroll_stock keys location_id and grade_id as VARCHAR, so the
            // join is on the value, not the type — MySQL coerces.
            ->leftJoin('bpl_softroll_stock as st', function ($j) {
                $j->on('st.location_id', '=', 'we.location_id')->on('st.grade_id', '=', 'prod.grade_id');
            })
            ->whereNull('we.deleted_at')
            ->whereNull('we.status')
            ->groupBy('we.location_id', 'prod.grade_id', 'g.type', 'st.quantity', 'st.weight')
            ->selectRaw(
                $this->warehouseName('`we`.`location_id`') . ' as `warehouse`,'
                . " 'softroll' as `stream`,"
                . " COALESCE(`g`.`type`, CONCAT('Grade #', `prod`.`grade_id`)) as `item`,"
                . ' COUNT(*) as `rolls`,'
                . ' ROUND(SUM(`prod`.`weight`), 2) as `weight`,'
                . ' COALESCE(`st`.`quantity`, 0) as `recorded_qty`,'
                . ' ROUND(COALESCE(`st`.`weight`, 0), 2) as `recorded_weight`'
            );

        return $conn->query()->fromSub($hardroll->unionAll($softroll), 'position');
    }

    /**
     * The warehouse's name from the core master, falling back to the legacy
     * location name.
     *
     * A scalar subquery rather than a join so it cannot multiply rows, and
     * scoped by company: `legacy_location_id` is only unique WITHIN a company —
     * the BIL raw-material stores use the same small integers — so the scoping
     * is load-bearing.
     */
    protected function warehouseName(string $legacyIdExpr): string
    {
        $companyId = (int) DB::connection('core')->table('companies')->where('code', 'BPL')->value('id');

        return 'COALESCE('
            . "(SELECT `name` FROM `core`.`warehouses` WHERE `legacy_location_id` = {$legacyIdExpr}"
            . " AND `company_id` = {$companyId} AND `deleted_at` IS NULL LIMIT 1),"
            . "(SELECT `location` FROM `bpl_stock_locations` WHERE `id` = {$legacyIdExpr} LIMIT 1),"
            . "CONCAT('Location #', {$legacyIdExpr}))";
    }

    /* ---------------- Cells ---------------- */

    private function streamBadge($row): string
    {
        return '<span class="badge ' . ($row->stream === 'softroll' ? 'badge-muted' : 'badge-success') . '">'
            . e($row->stream) . '</span>';
    }

    private function agreesCell($row): string
    {
        $sameCount = (int) $row->rolls === (int) $row->recorded_qty;
        $sameWeight = abs((float) $row->weight - (float) $row->recorded_weight) <= 0.01;

        if ($sameCount && $sameWeight) {
            return '<span class="badge badge-success">Yes</span>';
        }

        $what = ! $sameCount && ! $sameWeight ? 'count &amp; weight' : (! $sameCount ? 'count' : 'weight');

        return '<span class="badge badge-warning" title="The recorded stock table differs from the movements">'
            . $what . '</span>';
    }

    private function problemCell($row): string
    {
        return '<span class="badge badge-warning">' . e($row->problem) . '</span>';
    }

    private function diffCell(float $diff): string
    {
        if (abs($diff) < 0.005) {
            return '0';
        }

        return '<span class="badge badge-warning">' . ($diff > 0 ? '+' : '') . e(rtrim(rtrim(number_format($diff, 2, '.', ''), '0'), '.')) . '</span>';
    }
}
