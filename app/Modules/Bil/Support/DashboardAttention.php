<?php

namespace Modules\Bil\Support;

use Modules\Bil\Models\RawMaterialDamagedGood;
use Modules\Bil\Models\RawMaterialReturnApproval;
use Modules\Bil\Models\StockTransfer;
use Modules\Bil\Support\ConversionWaste as Waste;
use Modules\Core\Support\Prefs;

/**
 * BIL's "Needs attention" cards on the home dashboard (shape: see
 * Modules\Core\Support\Attention). Every count reuses the query the target
 * screen itself lists, so the number on the card is the number you land on.
 *
 * Deliberately absent: jumbo reels that left BPL and never reached a BIL gate.
 * An In Transit view of those was built and removed on 2026-08-14 at the
 * user's direction; what to do about them is still their call.
 */
class DashboardAttention
{
    public static function items(): array
    {
        return [
            [
                'key' => 'bil.sales.loads_open',
                'page' => 'bil.sales.delivery',
                'label' => 'Loads awaiting delivery',
                'route' => 'bil.sales.delivery',
                'resolve' => function () {
                    $t = SalesLoadings::inTransit();

                    return [
                        'count' => $t['loads'],
                        'detail' => $t['stale_loads'] > 0
                            ? number_format($t['stale_loads']) . ' older than ' . $t['stale_after'] . ' days, not confirmed'
                            : 'all loaded in the last ' . $t['stale_after'] . ' days',
                        'tone' => $t['stale_loads'] > 0 ? 'warn' : 'info',
                    ];
                },
            ],
            [
                'key' => 'bil.fg.transfers_awaiting',
                'page' => 'bil.finished_goods.stock_transfer_receive',
                'label' => 'Transfers awaiting receipt',
                'route' => 'bil.finished-goods.stock-transfer.receive',
                'resolve' => function () {
                    $q = StockTransfer::query()->inTransit();
                    $oldest = (clone $q)->min('date_of_transfer');
                    $age = $oldest ? (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($oldest)->startOfDay(), true) : 0;

                    return [
                        'count' => $q->count(),
                        'detail' => $oldest ? 'oldest dispatched ' . date(Prefs::dateFormat(), strtotime($oldest)) : null,
                        'tone' => $age > 2 ? 'warn' : 'info',
                    ];
                },
            ],
            [
                'key' => 'bil.fg.waste_open',
                'page' => 'bil.finished_goods.conversion_waste',
                'label' => 'Runs without waste confirmed',
                'route' => 'bil.finished-goods.conversion-waste',
                'resolve' => function () {
                    // Every run since the cut-over, uncapped: openRuns() reads
                    // only the newest 500, which once read as exactly "500".
                    // All of them is ~700 rows / ~120 ms.
                    $runs = Waste::recentRuns(null, PHP_INT_MAX);
                    $stored = Waste::storedFor($runs);
                    $open = array_filter($runs, fn ($r) => ! isset($stored[Waste::keyOf($r)])
                        || ! $stored[Waste::keyOf($r)]->isConfirmed());

                    // The newest run on a line is the one being worked now and
                    // blocks nothing; every older open run holds its line up.
                    $newest = [];
                    foreach ($runs as $r) {
                        $newest[$r['line_id']] = max($newest[$r['line_id']] ?? 0, $r['last_id']);
                    }
                    $blocked = collect($open)
                        ->filter(fn ($r) => $r['last_id'] < ($newest[$r['line_id']] ?? 0))
                        ->pluck('line_id')->unique()->count();

                    return [
                        'count' => count($open),
                        'detail' => $blocked > 0
                            ? 'Conversion Output blocked on ' . $blocked . ' ' . ($blocked === 1 ? 'line' : 'lines')
                            : 'current runs only — nothing blocked',
                        'tone' => $blocked > 0 ? 'danger' : 'info',
                    ];
                },
            ],
            [
                'key' => 'bil.rm.returns_pending',
                'page' => 'bil.raw_materials.factory_returns',
                'ability' => 'approve',
                'label' => 'Factory returns to approve',
                'route' => 'bil.raw-materials.factory-returns',
                'resolve' => fn () => [
                    'count' => RawMaterialReturnApproval::where('status', 'pending')->count(),
                    'detail' => 'stock moves only once approved',
                    'tone' => 'warn',
                ],
            ],
            [
                'key' => 'bil.rm.damaged_pending',
                'page' => 'bil.raw_materials.damaged_goods',
                'ability' => 'approve',
                'label' => 'Damaged goods to approve',
                'route' => 'bil.raw-materials.damaged-goods',
                'resolve' => fn () => [
                    'count' => RawMaterialDamagedGood::where('status', 'pending')->count(),
                    'detail' => 'still counted in stock until approved',
                    'tone' => 'warn',
                ],
            ],
        ];
    }
}
