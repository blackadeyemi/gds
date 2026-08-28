<?php

namespace Modules\Bil\Livewire\FinishedGoods\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Core\Models\MachineLine;
use Modules\Core\Models\WasteCause;
use Modules\Core\Models\WasteOrigin;

/**
 * BIL → Finished Goods → Reports → Conversion Waste. The reporting half of the
 * rebuilt factory_production_waste.php.
 *
 * Unlike the legacy report_factory_waste.php, everything here lives on ONE
 * connection: entries, runs, causes and origins are all `core`, so this is the
 * rare BIL report that can genuinely join its lookups instead of resolving them
 * per row in PHP.
 *
 * Five views, because waste gets asked about in five different ways: what was
 * thrown away (default), why (by cause), what it was made of (by origin), where
 * it came off (by line) — and, the one the legacy screen could not answer at
 * all, whether anybody accounted for it (by run).
 *
 * That last view is the point of the rebuild. A run with no confirmation is not
 * a run with no waste; it is a run nobody has looked at, and only this report
 * can tell the difference.
 */
#[Title('Conversion Waste Report')]
class ConversionWaste extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Conversion Waste Report';
    }

    public function printKey(): string
    {
        return 'conversion-waste';
    }

    public function subtitle(): string
    {
        return 'Waste weighed off the converting lines — by entry, by cause, by origin, by line, and by run.';
    }

    protected function reportPageKey(): string
    {
        return 'bil.finished_goods.reports.conversion_waste';
    }

    protected function printRouteName(): string
    {
        return 'bil.finished-goods.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bil.finished-goods.reports.download';
    }

    protected function options(): array
    {
        return $this->optCache ??= [
            'lines' => MachineLine::treeOrder()->get()
                ->mapWithKeys(fn ($l) => [$l->id => ($l->parent_id ? '— ' : '') . $l->name])->all(),
            'shifts' => ['day' => 'Day', 'night' => 'Night'],
            'causes' => WasteCause::withTrashed()->ordered()->pluck('name', 'id')->all(),
            'origins' => WasteOrigin::withTrashed()->ordered()->pluck('label', 'id')->all(),
            'statuses' => ['confirmed' => 'Confirmed', 'open' => 'Not yet confirmed'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'line' => ['label' => 'Line', 'options' => $o['lines']],
            'shift' => ['label' => 'Shift', 'options' => $o['shifts']],
            'origin' => ['label' => 'Origin', 'options' => $o['origins']],
            'cause' => ['label' => 'Cause', 'options' => $o['causes']],
            'status' => ['label' => 'Run status', 'options' => $o['statuses']],
        ];
    }

    /**
     * Entries, joined to the run they belong to.
     *
     * `production_date` is a real DATE, so the ISO range compares directly —
     * and it goes through applyDate() so a single-day range reaches MySQL as a
     * BETWEEN it can collapse to an equality.
     */
    protected function base()
    {
        $f = $this->filters;

        $q = DB::connection('bil')->table('conversion_waste_entries as e')
            ->join('conversion_waste_runs as r', 'e.run_id', '=', 'r.id')
            ->leftJoin('core.waste_causes as c', 'e.cause_id', '=', 'c.id')
            ->leftJoin('core.waste_origins as o', 'e.origin_id', '=', 'o.id');

        return $this->applyDate($q, 'r.production_date')
            ->when($f['line'] ?? '', fn ($q, $v) => $q->where('r.line_id', $v))
            ->when($f['shift'] ?? '', fn ($q, $v) => $q->where('r.shift', $v))
            ->when($f['origin'] ?? '', fn ($q, $v) => $q->where('e.origin_id', $v))
            ->when($f['cause'] ?? '', fn ($q, $v) => $q->where('e.cause_id', $v))
            ->when($f['status'] ?? '', fn ($q, $v) => $v === 'confirmed'
                ? $q->whereNotNull('r.confirmed_at')
                : $q->whereNull('r.confirmed_at'))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('r.product_name', 'like', $term)
                  ->orWhere('r.line_name', 'like', $term)
                  ->orWhere('c.name', 'like', $term)
                  ->orWhere('o.label', 'like', $term)
                  ->orWhere('e.origin_ref', 'like', $term);
            }));
    }

    /** The runs themselves, for the compliance view — no entry join. */
    protected function runBase()
    {
        $f = $this->filters;

        $q = DB::connection('bil')->table('conversion_waste_runs as r');

        return $this->applyDate($q, 'r.production_date')
            ->when($f['line'] ?? '', fn ($q, $v) => $q->where('r.line_id', $v))
            ->when($f['shift'] ?? '', fn ($q, $v) => $q->where('r.shift', $v))
            ->when($f['status'] ?? '', fn ($q, $v) => $v === 'confirmed'
                ? $q->whereNotNull('r.confirmed_at')
                : $q->whereNull('r.confirmed_at'))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('r.product_name', 'like', $term)
                  ->orWhere('r.line_name', 'like', $term);
            }));
    }

    /** kg to three places, the precision the column stores. */
    protected function kg($v): string
    {
        return number_format((float) $v, 3);
    }

    public function views(): array
    {
        $weight = fn ($r) => $this->kg($r->weight_kg ?? 0);

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    $this->dateCol('Date', 'production_date'),
                    ['Shift', 'shift', fn ($r) => ucfirst((string) $r->shift)],
                    ['Line', 'line_name'],
                    ['Product', 'product_name'],
                    ['Origin', 'origin'],
                    ['Classification', 'origin_ref'],
                    ['Cause', 'cause'],
                    ['Weight (kg)', 'weight_kg', $weight],
                    ['Entered by', 'username'],
                ],
                'searchable' => ['r.product_name', 'r.line_name', 'c.name', 'o.label', 'e.origin_ref'],
                'sortable' => ['production_date', 'shift', 'line_name', 'product_name',
                    'origin', 'origin_ref', 'cause', 'weight_kg', 'username'],
                'query' => fn () => $this->base()
                    ->select('e.id', 'r.production_date', 'r.shift', 'r.line_name', 'r.product_name',
                        'o.label as origin', 'e.origin_ref', 'c.name as cause', 'e.weight_kg', 'e.username')
                    // Along the (production_date, id) index — see the note on
                    // applyDate() and the Conversion Output report.
                    ->orderByDesc('r.production_date')->orderByDesc('e.id'),
            ],

            'by_cause' => [
                'label' => 'Summary (by cause)',
                'type' => 'summary',
                'columns' => [
                    ['Cause', 'cause'],
                    ['Entries', 'entries'],
                    ['Weight (kg)', 'weight_kg', $weight],
                ],
                'searchable' => ['c.name'],
                'query' => fn () => $this->base()
                    ->selectRaw('c.name as cause, COUNT(*) as entries, SUM(e.weight_kg) as weight_kg')
                    ->groupBy('c.name')
                    ->orderByDesc(DB::raw('SUM(e.weight_kg)')),
            ],

            'by_origin' => [
                'label' => 'Summary (by origin)',
                'type' => 'summary',
                'columns' => [
                    ['Origin', 'origin'],
                    ['Classification', 'origin_ref'],
                    ['Entries', 'entries'],
                    ['Weight (kg)', 'weight_kg', $weight],
                ],
                'searchable' => ['o.label', 'e.origin_ref'],
                'query' => fn () => $this->base()
                    ->selectRaw('o.label as origin, e.origin_ref, COUNT(*) as entries, SUM(e.weight_kg) as weight_kg')
                    ->groupBy('o.label', 'e.origin_ref')
                    ->orderBy('o.label')->orderByDesc(DB::raw('SUM(e.weight_kg)')),
            ],

            'by_line' => [
                'label' => 'Summary (by line, product)',
                'type' => 'summary',
                'columns' => [
                    ['Line', 'line_name'],
                    ['Product', 'product_name'],
                    ['Runs', 'runs'],
                    ['Entries', 'entries'],
                    ['Weight (kg)', 'weight_kg', $weight],
                ],
                'searchable' => ['r.line_name', 'r.product_name'],
                'query' => fn () => $this->base()
                    ->selectRaw('r.line_name, r.product_name, COUNT(DISTINCT r.id) as runs,
                                 COUNT(*) as entries, SUM(e.weight_kg) as weight_kg')
                    ->groupBy('r.line_name', 'r.product_name')
                    ->orderByDesc(DB::raw('SUM(e.weight_kg)')),
            ],

            /*
             * The view the legacy report could not produce: one row per run,
             * confirmed or not. A run with no entries and no confirmation is
             * the thing worth finding — it is not "no waste", it is nobody
             * having looked.
             */
            'by_run' => [
                'label' => 'Runs & confirmation',
                'type' => 'table',
                'columns' => [
                    $this->dateCol('Date', 'production_date'),
                    ['Shift', 'shift', fn ($r) => ucfirst((string) $r->shift)],
                    ['Line', 'line_name'],
                    ['Product', 'product_name'],
                    ['Entries', 'entries', fn ($r) => (int) ($r->entries ?? 0)],
                    ['Weight (kg)', 'weight_kg', $weight],
                    ['Status', 'confirmed_at', function ($r) {
                        if (! $r->confirmed_at) {
                            return '<span class="badge badge-danger">Open</span>';
                        }

                        return $r->is_nil
                            ? '<span class="badge badge-muted">Nil return</span>'
                            : '<span class="badge badge-success">Confirmed</span>';
                    }],
                    ['Confirmed by', 'confirmed_by_name'],
                ],
                'searchable' => ['r.line_name', 'r.product_name'],
                'sortable' => ['production_date', 'shift', 'line_name', 'product_name', 'confirmed_by_name'],
                'query' => fn () => $this->runBase()
                    // Aggregated in a subquery rather than a join, so a run with
                    // no entries still appears — which is the whole point of
                    // this view.
                    ->leftJoinSub(
                        DB::connection('bil')->table('conversion_waste_entries')
                            ->groupBy('run_id')
                            ->selectRaw('run_id, COUNT(*) as entries, SUM(weight_kg) as weight_kg'),
                        'agg',
                        'agg.run_id',
                        '=',
                        'r.id'
                    )
                    ->select('r.id', 'r.production_date', 'r.shift', 'r.line_name', 'r.product_name',
                        'r.confirmed_at', 'r.confirmed_by_name', 'r.is_nil',
                        'agg.entries', 'agg.weight_kg')
                    ->orderByDesc('r.production_date')->orderByDesc('r.id'),
            ],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }
}
