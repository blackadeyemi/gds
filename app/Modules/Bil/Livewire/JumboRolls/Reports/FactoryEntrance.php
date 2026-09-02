<?php

namespace Modules\Bil\Livewire\JumboRolls\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Core\Models\FactoryGate;

/**
 * Jumbo Rolls → Reports → Factory Entrance. Reels scanned onto a BIL factory
 * floor, over `factory_entrance_reel`. Rebuilt from the legacy
 * `report_factory_entrance_jumboreels` page.
 *
 * The row carries only a barcode and a location, so product, grade and weight
 * all come from BPL production — joined through the `bpl_production` /
 * `bpl_products` compatibility views on this connection.
 *
 * Read-only. A wrong entrance is corrected by re-scanning (the entrance screen
 * re-enters a deleted row in place); editing the weight here would put the
 * report at odds with what BPL says it made.
 */
#[Title('Jumbo Roll Factory Entrance Report')]
class FactoryEntrance extends JumboRollReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Factory Entrance Report';
    }

    public function printKey(): string
    {
        return 'factory-entrance';
    }

    public function pageKey(): string
    {
        return 'bil.jumbo_rolls.reports.factory_entrance';
    }

    public function subtitle(): string
    {
        return 'Jumbo rolls received onto a BIL factory floor.';
    }

    public function readOnly(): bool
    {
        return true;
    }

    /**
     * Gates, for the filter.
     *
     * Only gds movements carry a `gate_id`; the historic rows were backfilled
     * from `location`, so this filter covers all of history for jumbo rolls —
     * unlike the raw-material version, where the backfill was impossible.
     */
    protected function options(): array
    {
        return $this->optCache ??= [
            'gates' => FactoryGate::query()->direction(FactoryGate::IN)->ordered()->pluck('name', 'id')->all(),
            'locations' => DB::connection('bil')->table('factory_entrance_reel')
                ->select('location')->distinct()->orderBy('location')
                ->pluck('location', 'location')->all(),
            'grades' => $this->grades(),
            'statuses' => [
                'null' => 'On floor',
                'mid' => 'Part used',
                'yes' => 'Consumed',
                'return' => 'Returned',
                'blocked' => 'Blocked',
            ],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'location' => ['label' => 'Factory', 'options' => $o['locations']],
            'gate' => ['label' => 'Factory Gate', 'options' => $o['gates']],
            'gradetype' => ['label' => 'Grade Type', 'options' => $o['grades']],
            'status' => ['label' => 'Status', 'options' => $o['statuses']],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['gradetype'];
    }

    /** Fast total: the entrance table alone, with only its own columns filtered. */
    protected function countQuery()
    {
        $q = DB::connection('bil')->table('factory_entrance_reel as f')->where('f.is_deleted', 0);
        $this->applyDate($q, 'f.dateofentrance', true);
        $this->applyStatus($q, 'f.status');
        $this->applyFilters($q, ['location' => 'f.location', 'gate' => 'f.gate_id']);

        return $q;
    }

    protected function base()
    {
        $q = DB::connection('bil')->table('factory_entrance_reel as f')
            ->leftJoin('bpl_production as prod', 'prod.barcode', '=', 'f.barcode')
            ->leftJoin('bpl_products as pr', 'pr.id', '=', 'prod.product_id')
            ->where('f.is_deleted', 0);

        $this->applyDate($q, 'f.dateofentrance', true);
        $this->applyStatus($q, 'f.status');
        $this->applyFilters($q, [
            'location' => 'f.location',
            'gate' => 'f.gate_id',
            'gradetype' => 'pr.gradetype',
        ]);

        return $q;
    }

    public function views(): array
    {
        $searchable = ['f.barcode', 'f.location', 'f.user', 'prod.hardrollnumber', 'pr.productname', 'pr.gradetype'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Hardroll Number', 'hardrollnumber'],
                    ['Product', 'productname'],
                    ['Grade', 'gradetype'],
                    ['Factory', 'location'],
                    ['Received By', 'user'],
                    $this->dateCol('Date', 'dateofentrance'),
                    ['Weight (kg)', 'weight'],
                    ['Status', 'status', $this->statusBadge()],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->select('f.id', 'f.barcode', 'prod.hardrollnumber', 'pr.productname', 'pr.gradetype',
                        'f.location', 'f.user', 'f.dateofentrance', 'f.status',
                        DB::raw('ROUND(prod.weight, 2) as weight'))
                    // id is chronological — ordering on the joined or date columns
                    // would filesort the whole range.
                    ->orderByDesc('f.id'),
            ],
            'by_factory_grade' => [
                'label' => 'Summary (by factory, grade)',
                'type' => 'summary',
                'columns' => [
                    ['Factory', 'location'],
                    ['Grade', 'gradetype'],
                    ['Reels', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('f.location, pr.gradetype, COUNT(*) as quantity, ROUND(SUM(prod.weight), 2) as weight')
                    ->groupBy('f.location', 'pr.gradetype')
                    ->orderBy('f.location')->orderBy('pr.gradetype'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Grade', 'gradetype'],
                    ['Product', 'productname'],
                    ['Reels', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('pr.gradetype, pr.productname, COUNT(*) as quantity, ROUND(SUM(prod.weight), 2) as weight')
                    ->groupBy('pr.gradetype', 'pr.productname')
                    ->orderByDesc(DB::raw('SUM(prod.weight)')),
            ],
            'by_day' => [
                'label' => 'Summary (by day)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Date', 'dateofentrance'),
                    ['Factory', 'location'],
                    ['Reels', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('f.dateofentrance, f.location, COUNT(*) as quantity, ROUND(SUM(prod.weight), 2) as weight')
                    ->groupBy('f.dateofentrance', 'f.location')
                    ->orderByDesc('f.dateofentrance')->orderBy('f.location'),
            ],
        ];
    }

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_factory_grade' => ['location', 'gradetype'],
            'by_product' => ['gradetype', 'productname'],
            'by_day' => ['dateofentrance', 'location'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Barcode', 'barcode'],
            ['Hardroll Number', 'hardrollnumber'],
            ['Product', 'productname'],
            ['Factory', 'location'],
            $this->dateCol('Date', 'dateofentrance'),
            ['Weight (kg)', 'weight'],
            ['Status', 'status', $this->statusBadge()],
        ];
    }

    public function detailSearchable(): array
    {
        return ['f.barcode', 'prod.hardrollnumber', 'pr.productname'];
    }

    public function detailQuery(string $key)
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        $columns = [
            'location' => 'f.location',
            'gradetype' => 'pr.gradetype',
            'productname' => 'pr.productname',
            'dateofentrance' => 'f.dateofentrance',
        ];

        $q = $this->base()->select('f.id', 'f.barcode', 'prod.hardrollnumber', 'pr.productname',
            'f.location', 'f.dateofentrance', 'f.status', DB::raw('ROUND(prod.weight, 2) as weight'));

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            $q->where($columns[$field], $value);
        }

        return $q->orderByDesc('f.id');
    }
}
