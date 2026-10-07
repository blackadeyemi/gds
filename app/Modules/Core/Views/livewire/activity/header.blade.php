{{-- Activity page: notification controls above the grid. --}}
<div class="activity-bar">
    <span class="text-sm text-muted">
        @if ($this->unreadCount)
            <strong style="color:var(--ink);">{{ number_format($this->unreadCount) }}</strong>
            unread {{ \Illuminate\Support\Str::plural('notification', $this->unreadCount) }}
        @else
            No unread notifications
        @endif
    </span>
    <div class="flex items-center gap-2 ml-auto">
        @if ($this->unreadCount)
            <button type="button" class="btn btn-ghost btn-sm" wire:click="markAllRead">Mark all read</button>
        @endif
        <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('showMutes', true)">Notification settings</button>
    </div>
</div>
