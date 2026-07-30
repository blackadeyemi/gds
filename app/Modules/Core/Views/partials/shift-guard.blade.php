{{--
    Blocking overlay for a shift-gated page. Renders only when the area is
    closed and the viewer lacks bypass-shift-window. The sidebar stays usable
    (it lives outside the component), so the user can navigate away.
--}}
@php $shiftState = $this->shiftStatus(); @endphp
@if ($shiftState && $shiftState['blocked'])
    <div class="modal-backdrop" style="display:flex;" wire:key="shift-guard">
        <div class="modal-card" style="max-width:440px;">
            <div class="modal-body" style="padding:2rem;text-align:center;">
                <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:0.75rem;">
                    <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
                </svg>
                <h3 class="modal-title" style="justify-content:center;margin-bottom:0.4rem;">{{ $shiftState['label'] }} is closed</h3>
                <p class="text-muted mb-0">
                    This area is only open during its scheduled shift.
                    @if ($shiftState['next_open_at'])
                        <br>It reopens for <strong>{{ $shiftState['next_window'] }}</strong> at
                        <strong>{{ $shiftState['next_open_at']->translatedFormat('D d M, H:i') }}</strong>.
                    @endif
                </p>
                @if (! empty($shiftState['windows']))
                    <div class="text-sm text-muted" style="margin-top:1rem;border-top:1px solid var(--line);padding-top:0.85rem;">
                        @foreach ($shiftState['windows'] as $w)
                            <div>{{ $w['name'] }}: {{ $w['start'] }}–{{ $w['end'] }}@unless ($w['enabled']) <span class="badge badge-muted">off</span>@endunless</div>
                        @endforeach
                    </div>
                @endif
                <a href="{{ route('dashboard') }}" class="btn btn-primary" style="margin-top:1.5rem;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l9-9 9 9M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"/></svg>
                    Go to Dashboard
                </a>
            </div>
        </div>
    </div>
@endif
