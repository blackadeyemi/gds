@php
    $rollWeight = (float) ($context['roll_weight'] ?? 0);
    $net = (is_numeric($fullWeight) && is_numeric($truckWeight)) ? (float) $fullWeight - (float) $truckWeight : null;
@endphp

<div class="card card-pad" style="background:var(--surface-2);margin-bottom:1rem;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.5rem 1rem;font-size:.875rem;">
        <div><span class="text-muted">Waybill</span><div class="mono"><strong>{{ $waybill }}</strong></div></div>
        <div><span class="text-muted">Customer</span><div>{{ $context['customer'] ?: '—' }}</div></div>
        <div><span class="text-muted">Truck</span><div>{{ $context['truck'] ?: '—' }}</div></div>
        <div><span class="text-muted">Driver</span><div>{{ $context['driver'] ?: '—' }}</div></div>
        <div><span class="text-muted">Rolls</span><div>{{ number_format((int) ($context['rolls'] ?? 0)) }}</div></div>
        <div><span class="text-muted">Roll weight</span><div><strong>{{ number_format($rollWeight, 2) }} kg</strong></div></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Empty Truck Weight (kg)</label>
        <input type="number" class="form-control qty-input" wire:model.live.debounce.400ms="truckWeight" min="1" step="10">
        @error('truckWeight') <div class="form-error">{{ $message }}</div> @enderror
        <div class="form-hint">Booked in at Delivery; correct it here if the tare was wrong.</div>
    </div>
    <div class="form-group">
        <label class="form-label">Loaded Truck Weight (kg)</label>
        <input type="number" class="form-control qty-input" wire:model.live.debounce.400ms="fullWeight" min="1" step="10" autofocus>
        @error('fullWeight') <div class="form-error">{{ $message }}</div> @enderror
        <div class="form-hint">Off the weighbridge, on the way out.</div>
    </div>
</div>

{{-- The sum the clerk is really doing, shown as they type: a net short of the
     rolls by more than the tolerance is refused on save. --}}
@if ($net !== null && $net > 0)
    @php $diff = round($net - $rollWeight, 2); @endphp
    <div class="card card-pad" style="margin-top:.25rem;border-color:{{ $diff < -\Modules\Bpl\Livewire\Logistics\Weighbridge::TOLERANCE ? 'var(--danger)' : 'var(--border)' }};">
        <div style="display:flex;justify-content:space-between;font-size:.875rem;">
            <span>Net weight</span>
            <strong>{{ number_format($net, 2) }} kg</strong>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.875rem;margin-top:.35rem;">
            <span>Against the rolls on the truck</span>
            <strong style="color:{{ abs($diff) > \Modules\Bpl\Livewire\Logistics\Weighbridge::TOLERANCE ? 'var(--danger)' : 'inherit' }};">
                {{ $diff > 0 ? '+' : '' }}{{ number_format($diff, 2) }} kg
            </strong>
        </div>
        @if ($diff < -\Modules\Bpl\Livewire\Logistics\Weighbridge::TOLERANCE)
            <div class="form-error" style="margin-top:.4rem;">
                More than {{ \Modules\Bpl\Livewire\Logistics\Weighbridge::TOLERANCE }} kg short of the rolls — this will not save.
            </div>
        @endif
    </div>
@endif

@if (($context['status'] ?? '') === \Modules\Bpl\Models\BplWaybill::COMPLETED)
    <div class="text-muted text-sm" style="margin-top:.75rem;">This waybill has already been weighed. Saving replaces the figures.</div>
@endif
