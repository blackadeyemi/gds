<?php

namespace Modules\Core\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Models\Company;
use Modules\Core\Support\Attention;
use Modules\Core\Support\DashboardCatalog;

/**
 * Home page. The signed-in user's profile on top, then one card per module
 * they hold statistics or reports for — so what a person sees here is exactly
 * what their role grants, and nothing has to be configured twice.
 */
class Dashboard extends Component
{
    /** Range every card is shown over; '' = each module's own default. */
    #[Url]
    public string $range = '';

    public const RANGES = [
        '' => "Each module's default",
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        '12m' => 'Last 12 months',
        'all' => 'All time',
    ];

    public function mount(): void
    {
        if (! array_key_exists($this->range, self::RANGES)) {
            $this->range = '';
        }
    }

    #[Layout('core::layouts.admin')]
    #[Title('Dashboard')]
    public function render()
    {
        $user = auth()->user()->loadMissing(['roles', 'company', 'department', 'division']);
        $catalog = DashboardCatalog::for($user);
        $want = array_key_exists($this->range, self::RANGES) && $this->range !== '' ? $this->range : null;

        // "Statistics" opens on the window the card shows. Resolving it only
        // reads the page's range options — no queries.
        if ($want !== null) {
            foreach ($catalog as &$cards) {
                foreach ($cards as &$card) {
                    if ($card['stats'] && $class = DashboardCatalog::statisticsClass($card['stats'])) {
                        $card['statsUrl'] .= '?range=' . (new $class)->useDashboardRange($want);
                    }
                }
            }
            unset($cards, $card);
        }

        $cards = collect($catalog)->flatten(1);
        $work = DashboardCatalog::workFor($user);
        $name = trim((string) $user->fullname) ?: $user->username;

        return view('core::livewire.dashboard', [
            'user' => $user,
            'displayName' => $name,
            'initials' => $this->initials($name),
            'isAdmin' => $user->isAdmin(),
            'catalog' => $catalog,
            'ranges' => self::RANGES,
            'want' => $want,
            'companyNames' => Company::pluck('name', 'code')->all(),
            'work' => $work,
            'hasAttention' => Attention::itemsFor($user) !== [],
            'counts' => [
                'screens' => collect($work)->sum(fn ($g) => count($g['pages'])),
                'statistics' => $cards->whereNotNull('stats')->count(),
                'reports' => $cards->sum(fn ($c) => count($c['reports'])),
            ],
        ]);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/[\s._-]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $letters = count($parts) > 1
            ? mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1)
            : mb_substr($parts[0] ?? '?', 0, 2);

        return mb_strtoupper($letters);
    }
}
