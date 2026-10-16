<?php

namespace Modules\Bpl\Livewire\JumboRolls\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * BPL → Jumbo Rolls → Reports → Production. Every roll off a paper machine,
 * over `bpl_production` and `bpl_softroll_production`.
 *
 * Rebuilt from the legacy `report_bpl_production` (+ `_belimpex`) and
 * `report_softroll_production` pages, and from
 * `report_bpl_production_remaining`, which was the same report with one extra
 * condition — the reels that had not left the factory. That is now the
 * **Status** filter ("Still on the floor") rather than a separate page, so the
 * two can never drift apart.
 *
 * ## Two spellings of the same machine
 *
 * A hardroll records `papermachine` as text ('PM2', 'PM3'); a softroll records
 * the machine NUMBER (2, 3) — the digit in its own M2…/M3… roll number. The
 * two screens were written years apart against the same two machines. The
 * filter offers the NAMES and each leg matches its own spelling, so "PM3"
 * means PM3 in both streams.
 *
 * ## Weight is already net
 *
 * `weight` is what the reel is worth after the core/wrapper allowance has come
 * off; `net_weight` is the amount that was DEDUCTED (not, despite the name, a
 * net figure). The default view shows both, so a reel's scale weight is
 * `Weight + Deducted` and the allowance is auditable from the report — see
 * Jumbo Rolls → Weight Allowances for the rule itself.
 */
#[Title('BPL Production Report')]
class Production extends JumboRollReport
{
    public function title(): string
    {
        return 'Production Report';
    }

    public function printKey(): string
    {
        return 'production';
    }

    public function subtitle(): string
    {
        return 'Every roll off a BPL paper machine, hardroll and softroll.';
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
            'customers' => $this->db()->table('bpl_customers')
                ->whereNotNull('customername')->where('customername', '<>', '')
                ->orderBy('customername')->pluck('customername', 'id')->all(),
            'holds' => ['hold' => 'On hold', 'none' => 'Not held'],
            'statuses' => ['none' => 'Still on the floor', 'Exited' => 'Left the factory'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'stream' => ['label' => 'Type', 'options' => $o['streams'], 'width' => 150],
            'machine' => ['label' => 'Paper Machine', 'options' => $o['machines'], 'width' => 150],
            'gradetype' => ['label' => 'Grade Type', 'options' => $o['grades']],
            'customer' => ['label' => 'Customer', 'options' => $o['customers'], 'width' => 240],
            'hold' => ['label' => 'Hold', 'options' => $o['holds'], 'width' => 150],
            'status' => ['label' => 'Status', 'options' => $o['statuses']],
        ];
    }

    /** Grade and customer name both come off joined masters. */
    protected function joinedFilterKeys(): array
    {
        return ['gradetype'];
    }

    /* ---------------- The query ---------------- */

    protected function leg(string $stream)
    {
        $machine = (string) ($this->filters['machine'] ?? '');

        if ($stream === 'softroll') {
            $q = $this->db()->table('bpl_softroll_production as prod')
                ->leftJoin('bpl_products_softroll as pr', 'pr.id', '=', 'prod.product_id')
                ->leftJoin('bpl_grades as g', 'g.id', '=', 'prod.grade_id')
                ->whereNull('prod.deleted_at');

            $item = "COALESCE(`pr`.`productname`, `g`.`gradename`, CONCAT('Grade #', `prod`.`grade_id`))";
            $grade = '`g`.`type`';
            $rollNo = '`prod`.`softrollnumber`';
            // The machine number spelled out, so both legs read 'PM3'.
            $machineExpr = $this->softrollMachineExpr();
            $customer = "''";
            $deducted = '0';

            if ($machine !== '') {
                $q->where('prod.papermachine', $this->softrollMachineValue($machine) ?? -1);
            }
            // A softroll is never held and has no customer of its own.
            if (($this->filters['hold'] ?? '') === 'hold') {
                $q->whereRaw('1 = 0');
            }
            if (($this->filters['customer'] ?? '') !== '') {
                $q->whereRaw('1 = 0');
            }
        } else {
            $q = $this->db()->table('bpl_production as prod')
                ->leftJoin('bpl_products_hardroll as pr', 'pr.id', '=', 'prod.product_id')
                ->leftJoin('bpl_customers as c', 'c.id', '=', 'prod.customer_id')
                ->whereNull('prod.deleted_at');

            $item = "COALESCE(`pr`.`productname`, CONCAT('Product #', `prod`.`product_id`))";
            $grade = '`pr`.`gradetype`';
            $rollNo = '`prod`.`hardrollnumber`';
            $machineExpr = '`prod`.`papermachine`';
            $customer = "COALESCE(`c`.`customername`, '')";
            $deducted = 'ROUND(COALESCE(`prod`.`net_weight`, 0), 2)';

            if ($machine !== '') {
                $q->where('prod.papermachine', $machine);
            }
            if (($this->filters['customer'] ?? '') !== '') {
                $q->where('prod.customer_id', $this->filters['customer']);
            }
            // `hold` is '' as often as it is NULL, so "not held" has to say both.
            if (($hold = (string) ($this->filters['hold'] ?? '')) !== '') {
                $hold === 'hold'
                    ? $q->where('prod.hold', 'hold')
                    : $q->where(fn ($w) => $w->whereNull('prod.hold')->orWhere('prod.hold', '<>', 'hold'));
            }
        }

        $this->applyDate($q, 'prod.dateofmanufacture', true);
        $this->applyNullable($q, 'status', 'prod.status');

        if (($this->filters['gradetype'] ?? '') !== '') {
            $q->whereRaw($grade . ' = ?', [$this->filters['gradetype']]);
        }

        return $q->selectRaw(
            '`prod`.`id`,'
            . " '{$stream}' as `stream`,"
            . ' `prod`.`barcode`,'
            . " {$rollNo} as `rollnumber`,"
            . " {$machineExpr} as `machine`,"
            . " {$item} as `item`,"
            . " {$grade} as `gradetype`,"
            . " {$customer} as `customer`,"
            . ' `prod`.`brightness`,'
            . ' ROUND(`prod`.`weight`, 2) as `weight`,'
            . " {$deducted} as `deducted`,"
            . ' `prod`.`username` as `user`,'
            . ' `prod`.`dateofmanufacture` as `date`,'
            . ' `prod`.`status` as `status`'
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

    /**
     * The production tables alone. Every filter but grade lives on them, so
     * this covers all but the joined case — and `(deleted_at, dateofmanufacture)`
     * is indexed, which is what makes a day's report instant against 268,000
     * rows.
     */
    protected function countQuery()
    {
        $legs = null;
        $machine = (string) ($this->filters['machine'] ?? '');

        foreach ($this->streamsInPlay() as $stream) {
            $soft = $stream === 'softroll';
            $q = $this->db()->table($soft ? 'bpl_softroll_production as prod' : 'bpl_production as prod')
                ->whereNull('prod.deleted_at')->selectRaw('1 as `n`');

            $this->applyDate($q, 'prod.dateofmanufacture', true);
            $this->applyNullable($q, 'status', 'prod.status');

            if ($machine !== '') {
                $q->where('prod.papermachine', $soft ? ($this->softrollMachineValue($machine) ?? -1) : $machine);
            }

            if ($soft) {
                if (($this->filters['hold'] ?? '') === 'hold' || ($this->filters['customer'] ?? '') !== '') {
                    $q->whereRaw('1 = 0');
                }
            } else {
                if (($this->filters['customer'] ?? '') !== '') {
                    $q->where('prod.customer_id', $this->filters['customer']);
                }
                if (($hold = (string) ($this->filters['hold'] ?? '')) !== '') {
                    $hold === 'hold'
                        ? $q->where('prod.hold', 'hold')
                        : $q->where(fn ($w) => $w->whereNull('prod.hold')->orWhere('prod.hold', '<>', 'hold'));
                }
            }

            $legs = $legs ? $legs->unionAll($q) : $q;
        }

        return $this->db()->query()->fromSub($legs, 'c');
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['barcode', 'rollnumber', 'item', 'gradetype', 'customer', 'user'];

    public function views(): array
    {
        $status = $this->statusCell(
            ['Exited' => ['Left the factory', 'badge-muted']],
            'On the floor',
            'badge-success'
        );

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Type', 'stream', $this->streamCell()],
                    ['Roll No.', 'rollnumber'],
                    ['Machine', 'machine'],
                    ['Grade', 'gradetype'],
                    ['Product', 'item'],
                    ['Customer', 'customer'],
                    $this->dateCol('Made', 'date'),
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                    ['Deducted (kg)', 'deducted', $this->kgCell('deducted')],
                    ['Status', 'status', $status],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->select('r.*')->orderByDesc('date')->orderByDesc('id'),
                'totals' => ['weight', 'deducted'],
                'sortable' => ['barcode', 'stream', 'rollnumber', 'machine', 'gradetype', 'item',
                    'customer', 'date', 'weight', 'deducted'],
            ],

            'by_machine_grade' => [
                'label' => 'Summary (by machine, grade)',
                'type' => 'summary',
                'columns' => [
                    ['Machine', 'machine'],
                    ['Grade', 'gradetype'],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw('`machine`, `gradetype`, COUNT(*) as `quantity`, ROUND(SUM(`weight`), 2) as `weight`')
                    ->groupBy('machine', 'gradetype')
                    ->orderBy('machine')->orderBy('gradetype'),
            ],

            'by_grade' => [
                'label' => 'Summary (by grade)',
                'type' => 'summary',
                'columns' => [
                    ['Grade', 'gradetype'],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw('`gradetype`, COUNT(*) as `quantity`, ROUND(SUM(`weight`), 2) as `weight`')
                    ->groupBy('gradetype')
                    ->orderByDesc(DB::raw('SUM(`weight`)')),
            ],

            'by_product' => [
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

            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customer'],
                    ['Grade', 'gradetype'],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw("COALESCE(NULLIF(`customer`, ''), '—') as `customer`, `gradetype`,"
                        . ' COUNT(*) as `quantity`, ROUND(SUM(`weight`), 2) as `weight`')
                    ->groupBy('customer', 'gradetype')
                    ->orderBy('customer')->orderBy('gradetype'),
            ],

            'by_day' => [
                'label' => 'Summary (by day)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Made', 'date'),
                    ['Machine', 'machine'],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw('`date`, `machine`, COUNT(*) as `quantity`, ROUND(SUM(`weight`), 2) as `weight`')
                    ->groupBy('date', 'machine')
                    ->orderByDesc('date')->orderBy('machine'),
            ],
        ];
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_machine_grade' => ['machine', 'gradetype'],
            'by_grade' => ['gradetype'],
            'by_product' => ['stream', 'gradetype', 'item'],
            'by_customer' => ['customer', 'gradetype'],
            'by_day' => ['date', 'machine'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Barcode', 'barcode'],
            ['Type', 'stream', $this->streamCell()],
            ['Roll No.', 'rollnumber'],
            ['Machine', 'machine'],
            ['Product', 'item'],
            ['Customer', 'customer'],
            $this->dateCol('Made', 'date'),
            ['Weight (kg)', 'weight', $this->kgCell('weight')],
        ];
    }

    public function detailSearchable(): array
    {
        return ['barcode', 'rollnumber', 'item', 'customer'];
    }

    public function detailQuery(string $key)
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        $q = $this->base()->select('r.*');

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            // The customer summary prints an em dash for "no customer"; the
            // rows behind it are the ones with none.
            $field === 'customer' && $value === '—'
                ? $q->where(fn ($w) => $w->whereNull('customer')->orWhere('customer', ''))
                : $q->where($field, $value);
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
