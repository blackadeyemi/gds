<?php

namespace Modules\Core\Livewire;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Models\Activity;
use Modules\Core\Models\ActivityNotification;
use Modules\Core\Models\Page;
use Modules\Core\Support\Activity\PageContext;
use Modules\Core\Support\Prefs;

/**
 * Activity: who changed what, and the notifications built on it.
 *
 * Open to every signed-in user; what they see follows page access — an
 * activity is visible to whoever can open the page it happened on (Admin:
 * everything, including sign-ins). See Activity::scopeVisibleTo().
 *
 * Three views: All activity, My notifications, My activity. Nothing here
 * edits the log — there is no New/Edit/Delete, for anyone.
 */
#[Title('Activity')]
class ActivityLog extends DataGrid
{
    /** The activity whose details are open. ?open=<id> opens one on arrival. */
    public ?int $openId = null;

    public bool $showMutes = false;

    public const PERIODS = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        '12m' => 'Last 12 months',
        // "All" (all time) is the dropdown's own empty entry.
    ];

    public const ACTIONS = [
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'mixed' => 'Several changes',
    ];

    public function pageKey(): string { return 'activity'; }
    public function pageLabel(): string { return 'Activity'; }

    public function pageSubtitle(): string
    {
        return $this->user()->isAdmin()
            ? 'Every change made in gds, by whom and when, and sign-ins.'
            : 'Changes made on the pages you can open, by whom and when.';
    }

    public function headerView(): ?string { return 'core::livewire.activity.header'; }
    public function extraView(): ?string { return 'core::livewire.activity.details'; }

    /**
     * Read-only for everyone. The base would offer Edit/Delete to an Admin
     * (Admin passes every ability); only export is meaningful here.
     */
    public function mayDo(string $ability): bool
    {
        return $ability === 'export' && $this->user()->isAdmin();
    }

    public function hasLeadingRowActions(): bool { return true; }

    public function leadingRowActions($row): string
    {
        return '<button class="btn btn-ghost btn-sm" wire:click="openActivity(' . (int) $row->id . ')">Details</button>';
    }

    public function mount(): void
    {
        parent::mount();

        $this->perPage = 25;
        if (($this->filters['period'] ?? '') === '') {
            $this->filters['period'] = '30d';
        }

        $open = (int) request()->query('open', 0);
        if ($open > 0) {
            $this->openActivity($open);
        }
    }

    public function views(): array
    {
        $columns = [
            ['When', 'created_at', fn ($r) => $this->when($r)],
            ['User', 'username', fn ($r) => e($r->username ?? '—')],
            ['Page', 'page_key', fn ($r) => $this->pageCell($r)],
            ['Action', 'verb', fn ($r) => $this->verbBadge($r)],
            ['What changed', 'summary', fn ($r) => $this->summaryCell($r)],
        ];

        $notificationColumns = array_merge([
            ['', 'read_at', fn ($r) => $r->read_at === null
                ? '<span class="notif-dot unread" title="Unread"></span>'
                : '<span class="notif-dot" title="Read"></span>'],
            ['Why', 'note', fn ($r) => '<div style="font-weight:600;">' . e($r->note ?? '') . '</div>'
                . '<div class="text-muted text-sm">' . e(ActivityNotification::REASONS[$r->reason] ?? $r->reason) . '</div>'],
        ], $columns);

        $searchable = ['activities.summary', 'activities.refs', 'activities.username', 'activities.verb'];

        return [
            'all' => [
                'label' => 'All activity',
                'columns' => $columns,
                'query' => fn () => $this->filtered(Activity::query()->visibleTo($this->user())),
                'searchable' => $searchable,
            ],
            'notifications' => [
                'label' => 'My notifications',
                'columns' => $notificationColumns,
                'query' => fn () => $this->filtered(Activity::query()
                    ->join('activity_notifications as n', 'n.activity_id', '=', 'activities.id')
                    ->where('n.user_id', (int) $this->user()->userid)
                    ->select('activities.*', 'n.id as notification_id', 'n.read_at', 'n.reason', 'n.note')),
                'searchable' => array_merge($searchable, ['n.note']),
            ],
            'mine' => [
                'label' => 'My activity',
                'columns' => $columns,
                'query' => fn () => $this->filtered(Activity::query()->where('activities.user_id', (int) $this->user()->userid)),
                'searchable' => $searchable,
            ],
        ];
    }

    public function filterDefs(): array
    {
        $actions = self::ACTIONS;
        if ($this->user()->isAdmin()) {
            $actions['auth'] = 'Sign-ins';
        }

        return [
            'period' => ['label' => 'Period', 'options' => self::PERIODS, 'width' => 150],
            'user' => ['label' => 'User', 'options' => $this->userOptions(), 'width' => 170],
            'module' => ['label' => 'Module', 'options' => $this->moduleOptions(), 'width' => 210],
            'action' => ['label' => 'Action', 'options' => $actions, 'width' => 160],
        ];
    }

    /** Filters + newest first. Applied inside each view so counts agree with rows. */
    private function filtered($q)
    {
        $f = $this->filters;

        if (($from = $this->periodStart($f['period'] ?? '30d')) !== null) {
            $q->where('activities.created_at', '>=', $from);
        }
        if (($f['user'] ?? '') !== '' && $f['user'] !== 'all') {
            $q->where('activities.username', $f['user']);
        }
        if (($f['module'] ?? '') !== '' && $f['module'] !== 'all') {
            $q->where('activities.module', $f['module']);
        }
        if (($f['action'] ?? '') !== '' && $f['action'] !== 'all') {
            $q->where('activities.action', $f['action']);
        }

        return $q->orderByDesc('activities.id');
    }

    private function periodStart(string $period)
    {
        return match ($period) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            '12m' => now()->subMonths(12),
            'all', '' => null,
            default => now()->subDays(30),
        };
    }

    /** People who appear in what this user can see over the last year. */
    private function userOptions(): array
    {
        $user = $this->user();

        return Cache::remember('activity:users:' . $user->userid, 300, fn () => Activity::query()
            ->visibleTo($user)
            ->where('created_at', '>=', now()->subYear())
            ->whereNotNull('username')
            ->distinct()->orderBy('username')
            ->pluck('username', 'username')->all());
    }

    /** Modules of the pages this user can open. */
    private function moduleOptions(): array
    {
        return Page::whereIn('key', $this->user()->accessiblePageKeys() ?: [''])
            ->whereNotNull('module')->distinct()->orderBy('module')
            ->pluck('module', 'module')->all();
    }

    /* ---------------- cells ---------------- */

    private function when($r): string
    {
        $at = $r->created_at;

        return '<span title="' . e($at?->diffForHumans()) . '" style="white-space:nowrap;">'
            . e($at?->format(Prefs::dateFormat() . ' H:i')) . '</span>';
    }

    private function pageCell($r): string
    {
        $page = PageContext::page($r->page_key);
        if (! $page) {
            return $r->action === 'auth' ? '<span class="text-muted">Sign-in</span>' : '<span class="text-muted">—</span>';
        }

        return '<div>' . e($page['label']) . '</div><div class="text-muted text-sm">' . e($page['module'] ?? '') . '</div>';
    }

    private function verbBadge($r): string
    {
        $tone = match ($r->action) {
            'created' => 'badge-success',
            'deleted' => 'badge-danger',
            'updated' => 'badge-warning',
            'auth' => $r->verb === 'Sign-in failed' ? 'badge-danger' : 'badge-muted',
            default => 'badge-muted',
        };

        return '<span class="badge ' . $tone . '">' . e($r->verb) . '</span>';
    }

    private function summaryCell($r): string
    {
        $out = '<div>' . e($r->summary) . '</div>';
        if ($r->refs && $r->refs !== $r->username) {
            $out .= '<div class="text-muted text-sm" style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'
                . e($r->refs) . '">' . e($r->refs) . '</div>';
        }

        return $out;
    }

    /* ---------------- details ---------------- */

    public function openActivity(int $id): void
    {
        $activity = Activity::find($id);
        if (! $activity || ! $activity->isVisibleTo($this->user())) {
            $this->openId = null;

            return;
        }

        $this->openId = $id;
        unset($this->opened);

        ActivityNotification::where('user_id', (int) $this->user()->userid)
            ->where('activity_id', $id)->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function closeActivity(): void
    {
        $this->openId = null;
    }

    #[Computed]
    public function opened(): ?array
    {
        if (! $this->openId) {
            return null;
        }

        $a = Activity::with('items')->find($this->openId);
        if (! $a || ! $a->isVisibleTo($this->user())) {
            return null;
        }

        return [
            'activity' => $a,
            'page' => PageContext::page($a->page_key),
            'url' => PageContext::url($a->page_key),
            'shown' => $a->items->count(),
        ];
    }

    /* ---------------- notifications ---------------- */

    #[Computed]
    public function unreadCount(): int
    {
        return ActivityNotification::unreadCount((int) $this->user()->userid);
    }

    public function markAllRead(): void
    {
        ActivityNotification::where('user_id', (int) $this->user()->userid)
            ->whereNull('read_at')->update(['read_at' => now()]);
        unset($this->unreadCount);
    }

    /** Modules this user may mute: those of pages they can open. */
    #[Computed]
    public function muteOptions(): array
    {
        $muted = DB::connection('core')->table('activity_mutes')
            ->where('user_id', (int) $this->user()->userid)->pluck('module')->flip();

        return collect($this->moduleOptions())
            ->map(fn ($m) => ['module' => $m, 'muted' => $muted->has($m)])
            ->values()->all();
    }

    public function toggleMute(string $module): void
    {
        if (! in_array($module, $this->moduleOptions(), true)) {
            return;
        }

        $q = DB::connection('core')->table('activity_mutes')
            ->where('user_id', (int) $this->user()->userid)->where('module', $module);

        if ($q->exists()) {
            $q->delete();
        } else {
            DB::connection('core')->table('activity_mutes')->insert([
                'user_id' => (int) $this->user()->userid, 'module' => $module, 'created_at' => now(),
            ]);
        }
        unset($this->muteOptions);
    }

    private function user(): \Modules\Core\Models\User
    {
        return auth()->user();
    }
}
