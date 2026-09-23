{{--
    Hardroll / Softroll tabs over the two Production grids. Same shape as the
    Products tabs, and shown only where the viewer can reach the other side.
--}}
@php
    $onHardroll = request()->is('bpl/jumbo-rolls/production/hardroll*');
@endphp
<div class="bpl-tabs" style="display:flex;gap:0.25rem;border-bottom:1px solid var(--line);margin-bottom:1rem;">
    @canPage('bpl.jumbo_rolls.production.hardroll')
    <a href="{{ route('bpl.jumbo-rolls.production.hardroll') }}" wire:navigate
       class="bpl-tab {{ $onHardroll ? 'active' : '' }}"
       style="padding:0.6rem 1.1rem;font-weight:600;text-decoration:none;border-bottom:2px solid {{ $onHardroll ? 'var(--primary)' : 'transparent' }};color:{{ $onHardroll ? 'var(--primary)' : 'var(--muted)' }};">
        Hardroll
    </a>
    @endcanPage
    @canPage('bpl.jumbo_rolls.production.softroll')
    <a href="{{ route('bpl.jumbo-rolls.production.softroll') }}" wire:navigate
       class="bpl-tab {{ ! $onHardroll ? 'active' : '' }}"
       style="padding:0.6rem 1.1rem;font-weight:600;text-decoration:none;border-bottom:2px solid {{ ! $onHardroll ? 'var(--primary)' : 'transparent' }};color:{{ ! $onHardroll ? 'var(--primary)' : 'var(--muted)' }};">
        Softroll
    </a>
    @endcanPage
</div>

@if (session('bpl_label_url'))
    {{-- The label is the reason the roll was entered, so it is offered right
         after the save rather than only as a row action. Opened by the operator
         rather than auto-popped: a blocked pop-up would lose it silently. --}}
    <div class="card" style="display:flex;align-items:center;gap:0.75rem;border-color:var(--primary);margin-bottom:1rem;padding:0.7rem 1.25rem;">
        <span>Label ready for the roll just recorded.</span>
        <a class="btn btn-sm btn-primary" href="{{ session('bpl_label_url') }}" target="_blank" rel="noopener">Print label</a>
    </div>
@endif
