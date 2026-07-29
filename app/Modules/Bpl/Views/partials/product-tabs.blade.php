@php
    $onHardroll = request()->is('bpl/products/hardroll*');
@endphp
<div class="bpl-tabs" style="display:flex;gap:0.25rem;border-bottom:1px solid var(--line);margin-bottom:1rem;">
    <a href="{{ route('bpl.products.hardroll') }}" wire:navigate
       class="bpl-tab {{ $onHardroll ? 'active' : '' }}"
       style="padding:0.6rem 1.1rem;font-weight:600;text-decoration:none;border-bottom:2px solid {{ $onHardroll ? 'var(--primary)' : 'transparent' }};color:{{ $onHardroll ? 'var(--primary)' : 'var(--muted)' }};">
        Hardroll
    </a>
    <a href="{{ route('bpl.products.softroll') }}" wire:navigate
       class="bpl-tab {{ ! $onHardroll ? 'active' : '' }}"
       style="padding:0.6rem 1.1rem;font-weight:600;text-decoration:none;border-bottom:2px solid {{ ! $onHardroll ? 'var(--primary)' : 'transparent' }};color:{{ ! $onHardroll ? 'var(--primary)' : 'var(--muted)' }};">
        Softroll
    </a>
</div>
