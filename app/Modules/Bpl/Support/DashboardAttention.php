<?php

namespace Modules\Bpl\Support;

use Illuminate\Support\Facades\DB;
use Modules\Bpl\Livewire\Finance\Reports\Receivables as ReceivablesReport;
use Modules\Bpl\Livewire\JumboRolls\WaitingArea;
use Modules\Bpl\Models\BplWaybillPayment;
use Modules\Core\Support\Prefs;

/**
 * BPL's "Needs attention" cards on the home dashboard (shape: see
 * Modules\Core\Support\Attention). Each count is the list its card opens.
 */
class DashboardAttention
{
    public static function items(): array
    {
        return [
            [
                'key' => 'bpl.finance.overdue',
                'page' => 'bpl.finance.reports.receivables',
                'label' => 'Overdue shipments',
                'route' => 'bpl.finance.reports.receivables',
                'query' => ['view' => 'overdue'],
                'resolve' => function () {
                    // The report's own "Overdue only" view, so the legacy
                    // ageing rule (+1 day, B/L starts the clock) is applied
                    // exactly once, in one place.
                    $report = new ReceivablesReport;
                    $report->mount();
                    $q = ($report->views()['overdue']['query'])()->reorder();

                    $r = DB::connection('bpl')->query()->fromSub($q, 'x')
                        ->selectRaw('COUNT(*) as n, MAX(days_overdue) as oldest')->first();

                    return [
                        'count' => (int) $r->n,
                        'detail' => $r->n ? 'oldest ' . number_format((int) $r->oldest) . ' days past due' : null,
                        'tone' => 'danger',
                    ];
                },
            ],
            [
                'key' => 'bpl.finance.waybills_unpaid',
                'page' => 'bpl.finance.waybill_payments',
                'label' => 'Waybills not fully paid',
                'route' => 'bpl.finance.waybill-payments',
                'resolve' => function () {
                    $q = BplWaybillPayment::whereIn('status', [BplWaybillPayment::OUTSTANDING, BplWaybillPayment::PARTIAL]);

                    return [
                        'count' => (clone $q)->count(),
                        'detail' => '₦' . number_format((float) $q->sum('balance')) . ' owed to hauliers',
                        'tone' => 'warn',
                    ];
                },
            ],
            [
                'key' => 'bpl.jr.waiting_held',
                'page' => 'bpl.jumbo_rolls.waiting_area',
                'label' => 'Rolls in the waiting area',
                'route' => 'bpl.jumbo-rolls.waiting-area',
                'query' => ['mode' => 'held'],
                'resolve' => function () {
                    $r = WaitingArea::heldQuery()->selectRaw('COUNT(*) as n, MIN(w.date) as since')->first();

                    return [
                        'count' => (int) $r->n,
                        'detail' => $r->n && $r->since ? 'held since ' . date(Prefs::dateFormat(), strtotime(str_replace('/', '-', $r->since))) : null,
                        'tone' => 'info',
                    ];
                },
            ],
        ];
    }
}
