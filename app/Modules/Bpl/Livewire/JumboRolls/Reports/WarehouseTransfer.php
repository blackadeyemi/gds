<?php

namespace Modules\Bpl\Livewire\JumboRolls\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Support\StockLocations;

/**
 * BPL → Jumbo Rolls → Reports → Warehouse Transfer. Rolls moved from one BPL
 * store to another, over `bpl_warehouse_transfer`.
 *
 * The legacy `bpl_store_transfer` screen had no report at all: a transfer
 * adjusted both stores' stock and left nothing anyone could read back. gds
 * records one row per move with the weight that moved, which is what makes a
 * disputed store figure traceable.
 *
 * Unlike the scan reports this table carries its OWN weight — the figure that
 * was actually taken off one store and put on the other — rather than reading
 * it back off production. If a roll's production weight is later corrected,
 * the transfer still says what moved on the day.
 */
#[Title('BPL Warehouse Transfer Report')]
class WarehouseTransfer extends JumboRollReport
{
    public function title(): string
    {
        return 'Warehouse Transfer Report';
    }

    public function printKey(): string
    {
        return 'warehouse-transfer';
    }

    public function subtitle(): string
    {
        return 'Rolls moved between BPL stores, and the weight that moved with them.';
    }

    /* ---------------- Filters ---------------- */

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    protected function options(): array
    {
        return $this->optCache ??= [
            'streams' => $this->shared('streams'),
            'stores' => StockLocations::options(StockLocations::STORE),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'stream' => ['label' => 'Type', 'options' => $o['streams'], 'width' => 150],
            'from' => ['label' => 'From', 'options' => $o['stores']],
            'to' => ['label' => 'To', 'options' => $o['stores']],
        ];
    }

    /* ---------------- The query ---------------- */

    protected function base()
    {
        $q = $this->db()->table('bpl_warehouse_transfer as t')
            // The roll masters only supply the product name; the weight is the
            // transfer's own.
            ->leftJoin('bpl_production as hp', function ($j) {
                $j->on('hp.barcode', '=', 't.barcode')->where('t.stream', 'hardroll')->whereNull('hp.deleted_at');
            })
            ->leftJoin('bpl_products_hardroll as hpr', 'hpr.id', '=', 'hp.product_id')
            ->leftJoin('bpl_softroll_production as sp', function ($j) {
                $j->on('sp.barcode', '=', 't.barcode')->where('t.stream', 'softroll')->whereNull('sp.deleted_at');
            })
            ->leftJoin('bpl_grades as g', 'g.id', '=', 'sp.grade_id')
            ->whereNull('t.deleted_at');

        $this->applyDate($q, 't.date', true);
        $this->applyFilters($q, [
            'stream' => 't.stream',
            'from' => 't.from_location_id',
            'to' => 't.to_location_id',
        ]);

        return $q;
    }

    protected function select()
    {
        return $this->base()->selectRaw(
            '`t`.`id`, `t`.`stream`, `t`.`barcode`,'
            . " COALESCE(`hpr`.`productname`, `g`.`gradename`, '') as `item`,"
            . " COALESCE(`hpr`.`gradetype`, `g`.`type`, '') as `gradetype`,"
            . " COALESCE(`hp`.`hardrollnumber`, `sp`.`softrollnumber`, '') as `rollnumber`,"
            . ' ' . $this->placeName('`t`.`from_location_id`') . ' as `from_store`,'
            . ' ' . $this->placeName('`t`.`to_location_id`') . ' as `to_store`,'
            . ' ROUND(`t`.`weight`, 2) as `weight`,'
            . ' `t`.`user` as `user`, `t`.`date` as `date`'
        );
    }

    protected function countQuery()
    {
        $q = $this->db()->table('bpl_warehouse_transfer as t')->whereNull('t.deleted_at');
        $this->applyDate($q, 't.date', true);
        $this->applyFilters($q, [
            'stream' => 't.stream',
            'from' => 't.from_location_id',
            'to' => 't.to_location_id',
        ]);

        return $q;
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['t.barcode', 'hpr.productname', 'g.gradename', 't.user'];

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Type', 'stream', $this->streamCell()],
                    ['Roll No.', 'rollnumber'],
                    ['Product', 'item'],
                    ['From', 'from_store'],
                    ['To', 'to_store'],
                    ['Moved By', 'user'],
                    $this->dateCol('Date', 'date'),
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()->orderByDesc('t.id'),
                'totals' => ['weight'],
                'sortable' => ['barcode', 'stream', 'rollnumber', 'item', 'from_store', 'to_store', 'user', 'date', 'weight'],
            ],

            'by_route' => [
                'label' => 'Summary (by route)',
                'type' => 'summary',
                'columns' => [
                    ['From', 'from_store'],
                    ['To', 'to_store'],
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    $this->placeName('`t`.`from_location_id`') . ' as `from_store`,'
                    . ' ' . $this->placeName('`t`.`to_location_id`') . ' as `to_store`,'
                    . ' COUNT(*) as `quantity`, ROUND(SUM(`t`.`weight`), 2) as `weight`'
                )->groupBy('t.from_location_id', 't.to_location_id')
                    ->orderByDesc(DB::raw('SUM(`t`.`weight`)')),
            ],

            'by_day' => [
                'label' => 'Summary (by day)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Date', 'date'),
                    ['Rolls', 'quantity'],
                    ['Weight (kg)', 'weight', $this->kgCell('weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()
                    ->selectRaw('`t`.`date` as `date`, COUNT(*) as `quantity`, ROUND(SUM(`t`.`weight`), 2) as `weight`')
                    ->groupBy('t.date')->orderByDesc('t.date'),
            ],
        ];
    }
}
