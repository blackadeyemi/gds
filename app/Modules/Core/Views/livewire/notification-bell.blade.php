<div class="dropdown notif-bell" x-data="{ open: false }" @click.outside="open = false" wire:poll.60s.visible>
    <button class="icon-btn" @click="open = !open"
            title="{{ $this->unread ? $this->unread . ' unread notification' . ($this->unread === 1 ? '' : 's') : 'Notifications' }}"
            aria-label="Notifications">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        @if ($this->unread)
            <span class="notif-count">{{ $this->unread > 99 ? '99+' : $this->unread }}</span>
        @endif
    </button>

    <div class="dropdown-menu notif-menu" x-show="open" x-cloak x-transition>
        <div class="notif-head">
            <strong>Notifications</strong>
            @if ($this->unread)
                <button type="button" class="notif-link" wire:click="markAllRead">Mark all read</button>
            @endif
        </div>

        @forelse ($this->latest as $n)
            <button type="button" wire:key="notif-{{ $n->id }}" wire:click="open({{ $n->id }})"
                    @class(['notif-item', 'unread' => $n->read_at === null])>
                <span class="notif-dot" aria-hidden="true"></span>
                <span class="notif-body">
                    <span class="notif-note">{{ $n->note ?: ($n->activity?->summary ?? '') }}</span>
                    <span class="notif-meta">
                        {{ \Modules\Core\Models\ActivityNotification::REASONS[$n->reason] ?? $n->reason }}
                        @if ($n->activity?->username) · {{ $n->activity->username }} @endif
                        · {{ $n->created_at?->diffForHumans() }}
                    </span>
                </span>
            </button>
        @empty
            <div class="notif-empty">You're all caught up.</div>
        @endforelse

        <div class="dropdown-sep"></div>
        <a class="dropdown-item" href="{{ route('activity', ['view' => 'notifications']) }}">See all notifications</a>
        <a class="dropdown-item" href="{{ route('activity') }}">Activity</a>
    </div>
</div>
