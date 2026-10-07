<?php

namespace Modules\Core\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Modules\Core\Models\ActivityNotification;

/**
 * The bell in the top bar: unread count, the latest few, mark as read.
 *
 * Polls once a minute while the tab is visible — one indexed COUNT on
 * (user_id, read_at). No push server.
 */
class NotificationBell extends Component
{
    public const LATEST = 8;

    #[Computed]
    public function unread(): int
    {
        return ActivityNotification::unreadCount((int) auth()->id());
    }

    #[Computed]
    public function latest()
    {
        return ActivityNotification::with('activity')
            ->where('user_id', (int) auth()->id())
            ->orderByRaw('read_at IS NULL DESC')
            ->orderByDesc('id')
            ->limit(self::LATEST)
            ->get();
    }

    /** Open one: mark it read, then show it on the Activity page. */
    public function open(int $id)
    {
        $n = ActivityNotification::where('user_id', (int) auth()->id())->find($id);
        if (! $n) {
            return null;
        }

        if ($n->read_at === null) {
            $n->forceFill(['read_at' => now()])->save();
        }

        return $this->redirect(route('activity', ['view' => 'notifications', 'open' => $n->activity_id]), navigate: false);
    }

    public function markAllRead(): void
    {
        ActivityNotification::where('user_id', (int) auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        unset($this->unread, $this->latest);
    }

    public function render()
    {
        return view('core::livewire.notification-bell');
    }
}
