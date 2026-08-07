<?php

namespace Modules\Bil\Livewire\Machines\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Core\Models\MachineLine;

/**
 * BIL → Machines → Reports → Conversion History: every changeover, from
 * conversion_setup_history (renamed from factory_preproduction_history).
 *
 * A row is written each time a line is set to a different product or target, by
 * the Conversion Setup page and by the legacy screen through its compatibility
 * view — so this is the full record of what each line has run, ~25k rows going
 * back years.
 *
 * Read-only: the setup page owns the lifecycle, and a changeover already
 * happened — editing the log would rewrite history rather than correct it.
 *
 * Extends RawMaterialReport for the date range, filters, pagination, export and
 * print. That base is namespaced under RawMaterials but is generic (see the
 * Services report for the same note).
 */
#[Title('Conversion History')]
class ConversionHistory extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Conversion History';
    }

    public function printKey(): string
    {
        return 'conversion-history';
    }

    public function subtitle(): string
    {
        return 'Every line changeover — what each line was set to convert, the bundle target, and who set it.';
    }

    public function readOnly(): bool
    {
        return true;
    }

    protected function reportPageKey(): string
    {
        return 'bil.machines.reports.conversion_history';
    }

    protected function printRouteName(): string
    {
        return 'bil.machines.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bil.machines.reports.download';
    }

    protected function options(): array
    {
        return $this->optCache ??= [
            // Line names as recorded on the log, plus any line that exists now,
            // so a line renamed since a changeover is still selectable.
            'lines' => MachineLine::treeOrder()->get()
                ->mapWithKeys(fn ($l) => [$l->name => ($l->parent_id ? '— ' : '') . $l->name])->all(),
            'users' => DB::connection('bil')->table('conversion_setup_history')
                ->distinct()->orderBy('username')->pluck('username', 'username')->all(),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'line' => ['label' => 'Line', 'options' => $o['lines']],
            'username' => ['label' => 'Set By', 'options' => $o['users']],
        ];
    }

    /** `date_modified` is a real timestamp, so the range compares as dates. */
    protected function base()
    {
        $f = $this->filters;

        return DB::connection('bil')->table('conversion_setup_history as h')
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('h.date_modified', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('h.date_modified', '<=', $this->dateTo))
            ->when($f['line'] ?? '', fn ($q, $v) => $q->where('h.linename', $v))
            ->when($f['username'] ?? '', fn ($q, $v) => $q->where('h.username', $v))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('h.linename', 'like', $term)
                  ->orWhere('h.productname', 'like', $term)
                  ->orWhere('h.username', 'like', $term);
            }));
    }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Line', 'linename'],
                    ['Product', 'productname'],
                    ['Bundle Target', 'quantity'],
                    ['Set By', 'username'],
                    $this->dateCol('Changed', 'date_modified'),
                ],
                'searchable' => ['h.linename', 'h.productname', 'h.username'],
                // id is chronological, so newest-first via the PK.
                'query' => fn () => $this->base()
                    ->select('h.id', 'h.linename', 'h.productname', 'h.quantity', 'h.username', 'h.date_modified')
                    ->orderByDesc('h.id'),
            ],
            'by_line' => [
                'label' => 'Summary (by line)',
                'type' => 'summary',
                'columns' => [
                    ['Line', 'linename'],
                    ['Changeovers', 'changeovers'],
                    ['Products Run', 'products'],
                ],
                'searchable' => ['h.linename'],
                'query' => fn () => $this->base()
                    ->selectRaw('h.linename, COUNT(*) as changeovers, COUNT(DISTINCT h.productname) as products')
                    ->groupBy('h.linename')
                    ->orderByRaw('COUNT(*) DESC'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Product', 'productname'],
                    ['Times Set Up', 'changeovers'],
                    // Not aliased `lines`: LINES is a reserved word in MySQL.
                    ['Lines Used', 'line_count'],
                ],
                'searchable' => ['h.productname'],
                'query' => fn () => $this->base()
                    ->selectRaw('h.productname, COUNT(*) as changeovers, COUNT(DISTINCT h.linename) as line_count')
                    ->groupBy('h.productname')
                    ->orderByRaw('COUNT(*) DESC'),
            ],
        ];
    }
}
