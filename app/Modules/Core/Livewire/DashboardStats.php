<?php

namespace Modules\Core\Livewire;

use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Support\DashboardCatalog;

/**
 * One module's headline figures on the home dashboard — the Overview tab of
 * its Statistics page, computed by that page's own code.
 *
 * Lazy, so the dashboard paints at once and each module fills in on its own
 * request: an Admin sees nine of these, and the slowest Overview (12 months
 * of sales) should not hold up the rest. Cached for five minutes because the
 * figures are company-wide, not per user — everyone with access sees the
 * same numbers, and a busy morning of dashboard loads should cost one query
 * set per module, not one per person.
 */
#[Lazy]
class DashboardStats extends Component
{
    public const TTL = 300;

    #[Locked]
    public string $pageKey = '';

    /** Requested range key (StatisticsPage::RANGE_ORDER), or null for the page's own default. */
    #[Locked]
    public ?string $range = null;

    public function mount(string $pageKey, ?string $range = null): void
    {
        // The key arrives from our own view, but re-check: access is the
        // statistics page's own `view` grant, exactly as its route enforces.
        abort_unless(auth()->user()?->canAccessPage($pageKey), 403);
        abort_unless(DashboardCatalog::statisticsClass($pageKey), 404);

        $this->pageKey = $pageKey;
        $this->range = in_array($range, StatisticsPage::RANGE_ORDER, true) ? $range : null;
    }

    public function placeholder(): string
    {
        return view('core::livewire.dashboard-stats-placeholder')->render();
    }

    public function render()
    {
        $summary = null;
        $failed = false;

        try {
            $summary = Cache::remember('dashboard:stats:' . $this->pageKey . ':' . ($this->range ?? 'default'), self::TTL, function () {
                $class = DashboardCatalog::statisticsClass($this->pageKey);

                return (new $class)->dashboardSummary($this->range);
            });
        } catch (\Throwable $e) {
            // One broken module must not take the whole dashboard down.
            report($e);
            $failed = true;
        }

        return view('core::livewire.dashboard-stats', [
            'summary' => $summary,
            'failed' => $failed,
            // The module does not offer the chosen window and used its closest.
            'adjusted' => $summary && $this->range !== null && $summary['rangeKey'] !== $this->range,
        ]);
    }
}
