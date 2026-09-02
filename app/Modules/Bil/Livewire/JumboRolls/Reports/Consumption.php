<?php

namespace Modules\Bil\Livewire\JumboRolls\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * Jumbo Rolls → Reports → Consumption. Reels and slices unwound on a converting
 * machine, over `factory_usage_reel`. Rebuilt from the legacy
 * `report_factory_usage_jumboreels` page.
 *
 * The row carries its own weight — the slice weight, not the reel's — because a
 * sliced reel is consumed a piece at a time. That is the number to sum: summing
 * production weight here would count a five-slice reel five times over. Product
 * and grade come from BPL production, joined on `reel_barcode` (the stored
 * parent code) so a slice resolves to the reel it came off.
 */
#[Title('Jumbo Roll Consumption Report')]
class Consumption extends JumboRollReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Consumption Report';
    }

    public function printKey(): string
    {
        return 'consumption';
    }

    public function pageKey(): string
    {
        return 'bil.jumbo_rolls.reports.consumption';
    }

    public function subtitle(): string
    {
        return 'Jumbo rolls unwound on a converting machine.';
    }

    protected function options(): array
    {
        $bil = DB::connection('bil');

        return $this->optCache ??= [
            'locations' => $bil->table('factory_usage_reel')->select('location')->distinct()
                ->orderBy('location')->pluck('location', 'location')->all(),
            'lines' => $bil->table('factory_usage_reel')->select('linename')->distinct()
                ->orderBy('linename')->pluck('linename', 'linename')->all(),
            'machines' => $bil->table('factory_usage_reel')->select('project')->distinct()
                ->orderBy('project')->pluck('project', 'project')->all(),
            'grades' => $this->grades(),
            'shifts' => ['day' => 'Day', 'night' => 'Night'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'location' => ['label' => 'Factory', 'options' => $o['locations']],
            'line' => ['label' => 'Line', 'options' => $o['lines']],
            'machine' => ['label' => 'Machine', 'options' => $o['machines']],
            'gradetype' => ['label' => 'Grade Type', 'options' => $o['grades']],
            'shift' => ['label' => 'Shift', 'options' => $o['shifts']],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['gradetype'];
    }

    protected function countQuery()
    {
        $q = DB::connection('bil')->table('factory_usage_reel as u')->where('u.is_deleted', 0);
        $this->applyDate($q, 'u.dateofuse', true);
        $this->applyFilters($q, [
            'location' => 'u.location',
            'line' => 'u.linename',
            'machine' => 'u.project',
            'shift' => 'u.shift',
        ]);

        return $q;
    }

    protected function base()
    {
        $q = DB::connection('bil')->table('factory_usage_reel as u')
            ->leftJoin('bpl_production as prod', 'prod.barcode', '=', 'u.reel_barcode')
            ->leftJoin('bpl_products as pr', 'pr.id', '=', 'prod.product_id')
            ->where('u.is_deleted', 0);

        $this->applyDate($q, 'u.dateofuse', true);
        $this->applyFilters($q, [
            'location' => 'u.location',
            'line' => 'u.linename',
            'machine' => 'u.project',
            'shift' => 'u.shift',
            'gradetype' => 'pr.gradetype',
        ]);

        return $q;
    }

    public function views(): array
    {
        $searchable = ['u.barcode', 'u.location', 'u.linename', 'u.project', 'u.pre_productname',
            'u.user', 'pr.productname', 'pr.gradetype'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Product', 'productname'],
                    ['Grade', 'gradetype'],
                    ['Factory', 'location'],
                    ['Line', 'linename'],
                    ['Machine', 'project'],
                    ['Product On Line', 'pre_productname'],
                    ['Shift', 'shift', fn ($r) => ucfirst((string) $r->shift)],
                    $this->dateCol('Date', 'dateofuse'),
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->select('u.id', 'u.barcode', 'pr.productname', 'pr.gradetype', 'u.location',
                        'u.linename', 'u.project', 'u.pre_productname', 'u.shift', 'u.dateofuse',
                        DB::raw('ROUND(u.weight, 2) as weight'))
                    ->orderByDesc('u.id'),
            ],
            'by_machine' => [
                'label' => 'Summary (by line, machine)',
                'type' => 'summary',
                'columns' => [
                    ['Factory', 'location'],
                    ['Line', 'linename'],
                    ['Machine', 'project'],
                    ['Pieces', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('u.location, u.linename, u.project, COUNT(*) as quantity, ROUND(SUM(u.weight), 2) as weight')
                    ->groupBy('u.location', 'u.linename', 'u.project')
                    ->orderBy('u.location')->orderBy('u.linename')->orderBy('u.project'),
            ],
            'by_grade' => [
                'label' => 'Summary (by grade)',
                'type' => 'summary',
                'columns' => [
                    ['Grade', 'gradetype'],
                    ['Pieces', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('pr.gradetype, COUNT(*) as quantity, ROUND(SUM(u.weight), 2) as weight')
                    ->groupBy('pr.gradetype')->orderByDesc(DB::raw('SUM(u.weight)')),
            ],
            'by_day' => [
                'label' => 'Summary (by day, shift)',
                'type' => 'summary',
                'columns' => [
                    $this->dateCol('Date', 'dateofuse'),
                    ['Shift', 'shift', fn ($r) => ucfirst((string) $r->shift)],
                    ['Pieces', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('u.dateofuse, u.shift, COUNT(*) as quantity, ROUND(SUM(u.weight), 2) as weight')
                    ->groupBy('u.dateofuse', 'u.shift')
                    ->orderByDesc('u.dateofuse')->orderBy('u.shift'),
            ],
        ];
    }

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_machine' => ['location', 'linename', 'project'],
            'by_grade' => ['gradetype'],
            'by_day' => ['dateofuse', 'shift'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Barcode', 'barcode'],
            ['Product', 'productname'],
            ['Machine', 'project'],
            ['Shift', 'shift', fn ($r) => ucfirst((string) $r->shift)],
            $this->dateCol('Date', 'dateofuse'),
            ['Weight (kg)', 'weight'],
        ];
    }

    public function detailSearchable(): array
    {
        return ['u.barcode', 'pr.productname', 'u.project'];
    }

    public function detailQuery(string $key)
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        $columns = [
            'location' => 'u.location',
            'linename' => 'u.linename',
            'project' => 'u.project',
            'gradetype' => 'pr.gradetype',
            'dateofuse' => 'u.dateofuse',
            'shift' => 'u.shift',
        ];

        $q = $this->base()->select('u.id', 'u.barcode', 'pr.productname', 'u.project', 'u.shift',
            'u.dateofuse', DB::raw('ROUND(u.weight, 2) as weight'));

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            $q->where($columns[$field], $value);
        }

        return $q->orderByDesc('u.id');
    }
}
