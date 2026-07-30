{{--
    Blocking overlay for a shift-gated page. Renders only when the area is
    closed and the viewer lacks bypass-shift-window. The sidebar stays usable
    (it lives outside the component), so the user can navigate away.
--}}
@php $shift = $this->shift(); @endphp
@if ($shift && $shift['blocked'])
    <div class="modal-backdrop" style="display:flex;" wire:key="shift-guard">
        <div class="modal-card" style="max-width:440px;">
            <div class="modal-body" style="padding:2rem;text-align:center;">
                <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:0.75rem;">
                    <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
                </svg>
                <h3 class="modal-title" style="justify-content:center;margin-bottom:0.4rem;">{{ $shift['label'] }} is closed</h3>
                <p class="text-muted mb-0">
                    This area is only open during its scheduled shift.
                    @if ($shift['next_open_at'])
                        <br>It reopens for <strong>{{ $shift['next_window'] }}</strong> at
                        <strong>{{ $shift['next_open_at']->translatedFormat('D d M, H:i') }}</strong>.
                    @endif
                </p>
                @if (! empty($shift['windows']))
                    <div class="text-sm text-muted" style="margin-top:1rem;border-top:1px solid var(--line);padding-top:0.85rem;">
                        @foreach ($shift['windows'] as $w)
                            <div>{{ $w['name'] }}: {{ $w['start'] }}–{{ $w['end'] }}@unless ($w['enabled']) <span class="badge badge-muted">off</span>@endunless</div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif
