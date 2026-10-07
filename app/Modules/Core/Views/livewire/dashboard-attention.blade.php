<div>
    @if ($open)
        <div class="dash-alerts">
            @foreach ($open as $a)
                <a href="{{ $a['url'] }}" wire:navigate class="dash-alert tone-{{ $a['tone'] }}">
                    <span class="dash-alert-count">{{ number_format($a['count']) }}</span>
                    <span class="dash-alert-label">{{ $a['label'] }}</span>
                    @if ($a['detail'])
                        <span class="dash-alert-detail">{{ $a['detail'] }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    @else
        <div class="dash-allclear">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            Nothing is waiting on you.
        </div>
    @endif

    @if ($open && $clear)
        <p class="dash-clear-note">
            Also checked, all clear: {{ collect($clear)->pluck('label')->join(', ', ' and ') }}.
        </p>
    @endif

    @if ($failed)
        <p class="dash-clear-note">{{ $failed }} {{ Str::plural('check', $failed) }} could not be run just now.</p>
    @endif
</div>
