<?php

namespace Modules\Core\Livewire;

use Livewire\Attributes\Lazy;
use Livewire\Component;
use Modules\Core\Support\Attention;

/**
 * The dashboard's "Needs attention" cards: one per thing waiting on someone
 * that this user is allowed to act on, worst first. Lazy so the page paints
 * before the counts run; each count is cached and shared (see Attention).
 *
 * Takes no input from the client — the items are derived from the signed-in
 * user's grants on every render.
 */
#[Lazy]
class DashboardAttention extends Component
{
    public function placeholder(): string
    {
        return view('core::livewire.dashboard-attention-placeholder')->render();
    }

    public function render()
    {
        $results = array_map([Attention::class, 'resolve'], Attention::itemsFor(auth()->user()));

        $failed = count(array_filter($results, fn ($r) => $r === null));
        $results = array_values(array_filter($results));

        $open = array_values(array_filter($results, fn ($r) => $r['count'] > 0));
        usort($open, fn ($a, $b) => [Attention::TONES[$a['tone']] ?? 9, -$a['count']]
            <=> [Attention::TONES[$b['tone']] ?? 9, -$b['count']]);

        return view('core::livewire.dashboard-attention', [
            'open' => $open,
            'clear' => array_values(array_filter($results, fn ($r) => $r['count'] === 0)),
            'failed' => $failed,
        ]);
    }
}
