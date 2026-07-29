<?php

namespace Modules\Bil\Livewire\RawMaterials\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * Reports → Factory Returns. Raw material returned from the factory to the store,
 * over `return_approval` (each row is a return request + its approval outcome).
 * Read-only — the pending → approved/rejected lifecycle is managed on the
 * Factory Returns operation page. Approved rows offer a label reprint.
 *
 * The table stores the product name (`product`) and weight directly, so no join
 * is needed; group/sub-group aren't recorded here, so they aren't offered as
 * filters (unlike the stock reports).
 */
#[Title('Factory Returns Report')]
class FactoryReturns extends RawMaterialReport
{
    public function title(): string
    {
        return 'Factory Returns Report';
    }

    public function printKey(): string
    {
        return 'factory-returns';
    }

    public function subtitle(): string
    {
        return 'Raw material returned from the factory back to the store.';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function filterDefs(): array
    {
        return [
            'type' => ['label' => 'Return Type', 'options' => [
                'Non-Consumed' => 'Non-Consumed',
                'Partially Consumed' => 'Partially Consumed',
            ]],
            'status' => ['label' => 'Status', 'options' => [
                'pending' => 'Pending',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
            ]],
        ];
    }

    protected function base()
    {
        $q = DB::connection('bil')->table('return_approval as r');

        // dateofcreation is legacy `d/m/y` text (e.g. 03/07/26), so a plain
        // string BETWEEN won't work — parse it with STR_TO_DATE. The table is
        // tiny (~1.8k rows) so the non-indexable function scan is trivial.
        if ($this->dateFrom !== '' && $this->dateTo !== '') {
            $q->whereRaw("STR_TO_DATE(r.dateofcreation, '%d/%m/%y') BETWEEN ? AND ?",
                [$this->dateFrom, $this->dateTo]);
        }

        $this->applyFilters($q, [
            'type' => 'r.type',
            'status' => 'r.status',
        ]);

        return $q;
    }

    public function views(): array
    {
        $badge = fn ($v) => $v
            ? '<span class="badge">' . e($v) . '</span>'
            : '<span class="badge">—</span>';

        // A reprint link for approved returns (label for the in-store barcode).
        $label = function ($row) {
            if ($row->status !== 'approved') {
                return '—';
            }
            $url = route('bil.raw-materials.factory-returns.print', ['barcode' => $row->barcode]);

            return '<a href="' . e($url) . '" target="_blank" title="Reprint label">Print</a>';
        };

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Product', 'product'],
                    ['Type', 'type', fn ($r) => $badge($r->type)],
                    ['Weight (kg)', 'weight'],
                    ['Submitted By', 'user'],
                    ['Approved/Rejected By', 'authorizer'],
                    $this->dateCol('Date', 'dateofcreation', 'd/m/y'),
                    ['Status', 'status', fn ($r) => $badge($r->status)],
                    ['Label', 'label', $label],
                ],
                'searchable' => ['r.barcode', 'r.product', 'r.user', 'r.authorizer', 'r.type', 'r.status'],
                'query' => fn () => $this->base()
                    ->select('r.id', 'r.barcode', 'r.product', 'r.type', 'r.weight',
                        'r.user', 'r.authorizer', 'r.dateofcreation', 'r.status')
                    ->orderByDesc('r.id'),
            ],
            'by_type' => [
                'label' => 'Summary (by type)',
                'type' => 'summary',
                'columns' => [
                    ['Type', 'type'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['r.type'],
                'query' => fn () => $this->base()
                    ->selectRaw('r.type, COUNT(*) as quantity, SUM(r.weight) as weight')
                    ->groupBy('r.type')->orderByDesc(DB::raw('SUM(r.weight)')),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Product', 'product'],
                    ['Quantity', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => ['r.product'],
                'query' => fn () => $this->base()
                    ->selectRaw('r.product, COUNT(*) as quantity, SUM(r.weight) as weight')
                    ->groupBy('r.product')->orderByDesc(DB::raw('SUM(r.weight)')),
            ],
        ];
    }
}
