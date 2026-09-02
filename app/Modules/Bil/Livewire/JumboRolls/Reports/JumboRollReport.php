<?php

namespace Modules\Bil\Livewire\JumboRolls\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;

/**
 * Shared base for the Jumbo Rolls reports.
 *
 * Sits on the same report framework every other BIL report uses (filters, view
 * switching, search, sort, paging, print, export) and adds the handful of
 * things all three jumbo reports need:
 *
 *   - the print/download routes for this module;
 *   - the grade-type list, which every one of them filters on;
 *   - a status filter that can express NULL, since "on the floor" IS null and
 *     the framework's plain `where` cannot say that;
 *   - the shared reading of a reel's status.
 */
abstract class JumboRollReport extends RawMaterialReport
{
    /** The page key this report is gated by — used by the export routes. */
    abstract public function pageKey(): string;

    protected function printRouteName(): string
    {
        return 'bil.jumbo-rolls.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bil.jumbo-rolls.reports.download';
    }

    /** Jumbo reports are read-outs; corrections belong on the screens that write. */
    public function readOnly(): bool
    {
        return true;
    }

    /** Grade types that BPL actually produces, for the filter. */
    protected function grades(): array
    {
        return DB::connection('bil')->table('bpl_products')
            ->whereNotNull('gradetype')->where('gradetype', '<>', '')
            ->select('gradetype')->distinct()->orderBy('gradetype')
            ->pluck('gradetype', 'gradetype')->all();
    }

    /**
     * Apply the status filter, where the option 'null' means IS NULL.
     *
     * `factory_entrance_reel.status` uses NULL for "on the floor, untouched" —
     * the most interesting value of the lot — so the filter has to be able to
     * ask for it. `applyFilters()` builds a plain equality and never can.
     */
    protected function applyStatus($q, string $column)
    {
        $value = $this->filters['status'] ?? '';

        if ($value === '' || $value === 'all') {
            return $q;
        }

        return $value === 'null' ? $q->whereNull($column) : $q->where($column, $value);
    }

    /** How a reel's status reads in a table cell. */
    protected function statusBadge(): callable
    {
        $tone = [
            'mid' => 'badge-warning',
            'yes' => 'badge',
            'return' => 'badge-danger',
            'blocked' => 'badge-danger',
        ];

        $label = [
            'mid' => 'Part used',
            'yes' => 'Consumed',
            'return' => 'Returned',
            'blocked' => 'Blocked',
        ];

        return function ($row) use ($tone, $label) {
            $status = $row->status ?? null;

            if ($status === null || $status === '') {
                return '<span class="badge badge-success">On floor</span>';
            }

            return '<span class="' . ($tone[$status] ?? 'badge') . '">'
                . e($label[$status] ?? $status) . '</span>';
        };
    }
}
