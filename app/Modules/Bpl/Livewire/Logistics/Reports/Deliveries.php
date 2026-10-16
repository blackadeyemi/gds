<?php

namespace Modules\Bpl\Livewire\Logistics\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplDelivery;
use Modules\Bpl\Support\DeliveryFigures;

/**
 * BPL → Logistics → Reports → Deliveries. Every truck load that left the
 * loading bay, its waybill, and what the weighbridge made of it.
 *
 * Rebuilt from the legacy `report_bpl_delivery` page, which listed the
 * waybills and let a clerk enter the loaded weight — the entering half is now
 * the Weighbridge screen, and this is the reading half it left behind.
 *
 * ## The weighbridge check, as a report
 *
 * Each roll is weighed at production, so the rolls on a truck say what the
 * load should weigh; the weighbridge says what it does. `variance` is the
 * difference, and it is the one figure here nobody could see before.
 *
 * The Weighbridge screen refuses a net more than 150 kg SHORT of the rolls and
 * sets no upper bound, because real loads come out heavy: **22 of the 1,219
 * weighed loads are over 150 kg heavy, one by 13.7 tonnes**, and nothing at
 * all is more than 148 kg short. The **Weight variance** view lists them
 * heaviest first. A load 13 tonnes over its rolls is either a roll nobody
 * scanned or a figure typed wrong, and both are worth knowing.
 *
 * ## Two dates, one of them not like the others
 *
 * `bpl_delivery`.`date` is the usual legacy `Y/m/d`; the waybill's own
 * `bpl_delivery_barcode`.`date` is **`yy-mm-dd`**, written to match the
 * `DD-MM-YY-BPD-NNN` number rather than the schema. Everything date-ranged
 * here uses the delivery's. See Modules\Bpl\Support\DeliveryFigures.
 *
 * ## Internal moves
 *
 * A load to Belimpex is an inter-company transfer: no truck details, and its
 * waybill completes at once because nothing weighs it. Those rows have no
 * variance, and the Pending view leaves them out — they are not waiting for
 * anything.
 */
#[Title('BPL Deliveries Report')]
class Deliveries extends LogisticsReport
{
    public function title(): string
    {
        return 'Deliveries Report';
    }

    public function printKey(): string
    {
        return 'deliveries';
    }

    protected function headline(): string
    {
        return 'Truck loads and their waybills, with what the weighbridge made of each.';
    }

    /** Weights, not money — nothing here needs a currency. */
    protected function hasMoneyColumns(): bool
    {
        return false;
    }

    /* ---------------- Filters ---------------- */

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    protected function options(): array
    {
        return $this->optCache ??= [
            'customers' => $this->db()->table('bpl_delivery')
                ->whereNotNull('customer_name')->where('customer_name', '<>', '')
                ->select('customer_name')->distinct()->orderBy('customer_name')
                ->pluck('customer_name', 'customer_name')->all(),
            'vehicles' => $this->db()->table('bpl_delivery')
                ->whereNotNull('vechile_number')->where('vechile_number', '<>', '')
                ->select('vechile_number')->distinct()->orderBy('vechile_number')
                ->pluck('vechile_number', 'vechile_number')->all(),
            'status' => [
                'completed' => 'Weighed and completed',
                'pending' => 'Waiting for the weighbridge',
            ],
            'variance' => [
                'heavy' => 'More than 150 kg heavy',
                'short' => 'More than 150 kg short',
                'ok' => 'Within 150 kg',
            ],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'customer' => ['label' => 'Delivered To', 'options' => $o['customers'], 'width' => 250],
            'vehicle' => ['label' => 'Vehicle', 'options' => $o['vehicles'], 'width' => 170],
            'status' => ['label' => 'Waybill', 'options' => $o['status'], 'width' => 220],
            'variance' => ['label' => 'Weight Check', 'options' => $o['variance'], 'width' => 210],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['customer', 'vehicle', 'variance'];
    }

    /* ---------------- The query ---------------- */

    protected function base()
    {
        $q = $this->db()->query()->fromSub(DeliveryFigures::waybills(), 'f');

        // The DELIVERY's date. The waybill's own column is yy-mm-dd and would
        // silently match nothing.
        $this->applyDate($q, 'date', true);
        $this->applyFilters($q, [
            'customer' => 'customer',
            'vehicle' => 'vehicle',
            'status' => 'status',
        ]);

        $tolerance = DeliveryFigures::TOLERANCE;

        return match ((string) ($this->filters['variance'] ?? '')) {
            'heavy' => $q->where('variance', '>', $tolerance),
            'short' => $q->where('variance', '<', -$tolerance),
            'ok' => $q->whereNotNull('variance')->whereBetween('variance', [-$tolerance, $tolerance]),
            default => $q,
        };
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['waybill', 'customer', 'driver', 'vehicle', 'container', 'loader'];

    public function views(): array
    {
        $columns = [
            ['Waybill', 'waybill', fn ($r) => '<span class="mono">' . e((string) $r->waybill) . '</span>'],
            $this->dateCol('Date', 'date'),
            ['Delivered To', 'customer'],
            ['Vehicle', 'vehicle'],
            ['Driver', 'driver'],
            ['Rolls', 'rolls'],
            ['Rolls (t)', 'roll_weight', $this->tonnesCell('roll_weight')],
            ['Net (t)', 'net_weight', $this->tonnesCell('net_weight')],
            ['Variance (kg)', 'variance', fn ($r) => $this->varianceCell($r)],
            ['Waybill Status', 'status', fn ($r) => $this->statusCell($r)],
        ];

        // Rolls and their weight total honestly. `net_weight` does too, but
        // only across weighed loads — the unweighed carry NULL, which SUM
        // skips.
        $totals = ['rolls', 'roll_weight', 'net_weight'];

        $sortable = ['waybill', 'date', 'customer', 'vehicle', 'driver', 'rolls',
            'roll_weight', 'net_weight', 'variance'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->select('f.*')
                    ->orderByDesc('date')->orderByDesc('id'),
                'totals' => $totals,
                'sortable' => $sortable,
            ],

            'pending' => [
                'label' => 'Waiting for the weighbridge',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                // An internal move to Belimpex completes at once because
                // nothing weighs it, so it is not waiting for anything.
                'query' => fn () => $this->base()->select('f.*')
                    ->where('status', 'pending')
                    ->where('customer', '<>', BplDelivery::INTERNAL_CUSTOMER)
                    ->orderBy('date'),
                'totals' => ['rolls', 'roll_weight'],
                'sortable' => $sortable,
            ],

            'variance' => [
                'label' => 'Weight variance',
                'type' => 'table',
                'columns' => [
                    ['Waybill', 'waybill', fn ($r) => '<span class="mono">' . e((string) $r->waybill) . '</span>'],
                    $this->dateCol('Date', 'date'),
                    ['Delivered To', 'customer'],
                    ['Vehicle', 'vehicle'],
                    ['Rolls', 'rolls'],
                    ['Rolls (kg)', 'roll_weight', $this->kgCell('roll_weight')],
                    ['Tare (kg)', 'tare', $this->kgCell('tare')],
                    ['Loaded (kg)', 'full_weight', $this->kgCell('full_weight')],
                    ['Net (kg)', 'net_weight', $this->kgCell('net_weight')],
                    ['Variance (kg)', 'variance', fn ($r) => $this->varianceCell($r)],
                ],
                'searchable' => self::SEARCHABLE,
                // Heaviest first: the Weighbridge refuses anything more than
                // 150 kg short, so everything out of tolerance here is heavy —
                // a roll nobody scanned, or a figure typed wrong.
                'query' => fn () => $this->base()->select('f.*')
                    ->whereNotNull('variance')
                    ->whereRaw('ABS(`variance`) > ' . DeliveryFigures::TOLERANCE)
                    ->orderByDesc(DB::raw('ABS(`variance`)')),
                'totals' => ['rolls', 'roll_weight'],
                'sortable' => $sortable,
            ],

            'orphans' => [
                'label' => 'Broken links',
                'type' => 'table',
                'columns' => [
                    ['Problem', 'problem', fn ($r) => '<span class="badge badge-warning">'
                        . e((string) $r->problem) . '</span>'],
                    ['Waybill', 'waybill', fn ($r) => $r->waybill === ''
                        ? '<span class="text-muted">—</span>'
                        : '<span class="mono">' . e((string) $r->waybill) . '</span>'],
                    $this->dateCol('Date', 'date'),
                    ['Delivered To', 'customer'],
                    ['Vehicle', 'vehicle'],
                    ['Waybill Status', 'status'],
                ],
                'searchable' => [],
                // Deliberately NOT date-ranged. A waybill with no load has no
                // delivery to take a date from, so any range would hide the
                // very rows this view exists to show.
                'query' => fn () => $this->orphans(),
                'totals' => [],
                'sortable' => [],
            ],

            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Delivered To', 'customer'],
                    ['Loads', 'loads'],
                    ['Rolls', 'rolls'],
                    ['Rolls (t)', 'roll_weight', $this->tonnesCell('roll_weight')],
                    ['Avg Rolls / Load', 'avg_rolls'],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(NULLIF(`customer`, ''), '—') as `customer`,"
                    . ' COUNT(*) as `loads`,'
                    . ' SUM(`rolls`) as `rolls`,'
                    . ' ROUND(SUM(`roll_weight`), 2) as `roll_weight`,'
                    . ' ROUND(AVG(`rolls`), 1) as `avg_rolls`'
                )->groupBy('customer')->orderByDesc('roll_weight'),
                // An average is never summed.
                'totals' => ['loads', 'rolls', 'roll_weight'],
            ],

            'by_vehicle' => [
                'label' => 'Summary (by vehicle)',
                'type' => 'summary',
                'columns' => [
                    ['Vehicle', 'vehicle'],
                    ['Loads', 'loads'],
                    ['Rolls', 'rolls'],
                    ['Rolls (t)', 'roll_weight', $this->tonnesCell('roll_weight')],
                    ['Avg Tare (kg)', 'avg_tare', $this->kgCell('avg_tare')],
                ],
                'searchable' => self::SEARCHABLE,
                // The average tare is worth a glance: the same truck should
                // weigh the same empty every time.
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(NULLIF(`vehicle`, ''), '— none recorded —') as `vehicle`,"
                    . ' COUNT(*) as `loads`,'
                    . ' SUM(`rolls`) as `rolls`,'
                    . ' ROUND(SUM(`roll_weight`), 2) as `roll_weight`,'
                    . ' ROUND(AVG(`tare`), 2) as `avg_tare`'
                )->groupBy('vehicle')->orderByDesc('loads'),
                'totals' => ['loads', 'rolls', 'roll_weight'],
            ],

            'by_day' => [
                'label' => 'Summary (by day)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Date', 'date'),
                    ['Loads', 'loads'],
                    ['Customers', 'customers'],
                    ['Rolls', 'rolls'],
                    ['Rolls (t)', 'roll_weight', $this->tonnesCell('roll_weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    '`date`, COUNT(*) as `loads`,'
                    . ' COUNT(DISTINCT `customer`) as `customers`,'
                    . ' SUM(`rolls`) as `rolls`,'
                    . ' ROUND(SUM(`roll_weight`), 2) as `roll_weight`'
                )->groupBy('date')->orderByDesc('date'),
                'totals' => ['loads', 'rolls', 'roll_weight'],
            ],

            'by_month' => [
                'label' => 'Summary (by month)',
                'type' => 'summary',
                'columns' => [
                    ['Month', 'month'],
                    ['Loads', 'loads'],
                    ['Customers', 'customers'],
                    ['Rolls', 'rolls'],
                    ['Rolls (t)', 'roll_weight', $this->tonnesCell('roll_weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    'LEFT(`date`, 7) as `month`, COUNT(*) as `loads`,'
                    . ' COUNT(DISTINCT `customer`) as `customers`,'
                    . ' SUM(`rolls`) as `rolls`,'
                    . ' ROUND(SUM(`roll_weight`), 2) as `roll_weight`'
                )->groupBy('month')->orderByDesc('month'),
                'totals' => ['loads', 'rolls', 'roll_weight'],
            ],
        ];
    }

    /**
     * The two rows either side of a broken link.
     *
     * A waybill and its load are joined only through
     * `bpl_delivery_details` — there is no delivery id on the waybill row and
     * no waybill on the delivery — so a failure between the two writes leaves
     * an orphan at one end. gds writes all three tables in one transaction
     * (they were MyISAM until 2026_09_23_100000, so the legacy could not), and
     * this view is what the years before that left behind: one waybill with no
     * load and one load with no waybill.
     */
    protected function orphans()
    {
        $waybillsWithoutLoad = $this->db()->table('bpl_delivery_barcode as w')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_delivery_details as dd')
                ->whereColumn('dd.delivery_barcode', 'w.delivery_barcode'))
            ->selectRaw(
                "'Waybill with no load behind it' as `problem`,"
                . ' `w`.`delivery_barcode` as `waybill`,'
                . " '' as `date`, '' as `customer`, '' as `vehicle`,"
                . ' `w`.`status`'
            );

        $loadsWithoutWaybill = $this->db()->table('bpl_delivery as d')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_delivery_details as dd')
                ->whereColumn('dd.bpl_delivery_id', 'd.id'))
            ->selectRaw(
                "'Load with no waybill' as `problem`,"
                . " '' as `waybill`,"
                . ' `d`.`date`,'
                . " COALESCE(`d`.`customer_name`, '') as `customer`,"
                . " COALESCE(`d`.`vechile_number`, '') as `vehicle`,"
                . " '' as `status`"
            );

        return $this->db()->query()
            ->fromSub($waybillsWithoutLoad->unionAll($loadsWithoutWaybill), 'o')
            ->orderBy('problem');
    }

    /* ---------------- Cells ---------------- */

    private function varianceCell($row): string
    {
        if ($row->variance === null) {
            return '<span class="text-muted">—</span>';
        }

        $v = (float) $row->variance;
        $out = ($v > 0 ? '+' : '') . number_format($v, 0);

        if (abs($v) <= DeliveryFigures::TOLERANCE) {
            return '<span class="text-muted">' . $out . '</span>';
        }

        return '<span class="badge ' . (abs($v) > 1000 ? 'badge-danger' : 'badge-warning')
            . '" title="The weighbridge and the rolls disagree by more than '
            . DeliveryFigures::TOLERANCE . ' kg">' . $out . '</span>';
    }

    private function statusCell($row): string
    {
        if ((string) $row->status === 'completed') {
            return trim((string) $row->customer) === BplDelivery::INTERNAL_CUSTOMER
                ? '<span class="badge badge-muted" title="An internal move — nothing weighs it">Internal</span>'
                : '<span class="badge badge-success">Completed</span>';
        }

        return '<span class="badge badge-warning">Pending</span>';
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_customer' => ['customer'],
            'by_vehicle' => ['vehicle'],
            'by_day' => ['date'],
            'by_month' => ['month'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Waybill', 'waybill'],
            $this->dateCol('Date', 'date'),
            ['Delivered To', 'customer'],
            ['Vehicle', 'vehicle'],
            ['Driver', 'driver'],
            ['Rolls', 'rolls'],
            ['Rolls (t)', 'roll_weight', $this->tonnesCell('roll_weight')],
            ['Variance (kg)', 'variance', fn ($r) => $this->varianceCell($r)],
        ];
    }

    public function detailSearchable(): array
    {
        return ['waybill', 'driver', 'vehicle'];
    }

    public function detailQuery(string $key)
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        $q = $this->base()->select('f.*');

        $columns = [
            'customer' => DB::raw("COALESCE(NULLIF(`customer`, ''), '—')"),
            'vehicle' => DB::raw("COALESCE(NULLIF(`vehicle`, ''), '— none recorded —')"),
            'date' => DB::raw('`date`'),
            'month' => DB::raw('LEFT(`date`, 7)'),
        ];

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            $q->where($columns[$field], $value);
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
