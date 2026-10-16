@php
    $invoice = (float) ($context['invoice'] ?? 0);
    $symbol = $context['symbol'] ?? '';
    $paid = is_numeric($amount) ? (float) $amount : 0.0;
    $balance = round($invoice - $paid, 2);
@endphp

<div class="card card-pad" style="background:var(--surface-2);margin-bottom:1rem;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.5rem 1rem;font-size:.875rem;">
        <div><span class="text-muted">Shipment</span><div class="mono"><strong>{{ $context['shipment'] ?? '' }}</strong></div></div>
        <div><span class="text-muted">Customer</span><div>{{ $context['customer'] ?? '—' }}</div></div>
        <div><span class="text-muted">Shipped</span><div>{{ $context['date'] ?? '—' }}</div></div>
        <div>
            <span class="text-muted">Final invoice</span>
            <div><strong>{{ $symbol }}{{ number_format($invoice, 2) }}</strong> {{ $context['currency'] ?? '' }}</div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Amount Paid</label>
        <input type="number" class="form-control qty-input" wire:model.live.debounce.400ms="amount" min="0" step="0.01">
        @error('amount') <div class="form-error">{{ $message }}</div> @enderror
        <div class="form-hint">The total received against this shipment, not a single receipt.</div>
    </div>
    <div class="form-group">
        <label class="form-label">Date of Payment</label>
        @include('bil::partials.date-field', ['model' => 'dateIso'])
        @error('dateIso') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

@if ($invoice > 0)
    <div class="card card-pad" style="border-color:{{ $balance > \Modules\Bpl\Livewire\Finance\InvoicePayments::TOLERANCE ? 'var(--warning,#b8860b)' : 'var(--success)' }};">
        <div style="display:flex;justify-content:space-between;font-size:.875rem;">
            <span>Balance after this payment</span>
            <strong>{{ $symbol }}{{ number_format($balance, 2) }}</strong>
        </div>
        @if ($balance < -\Modules\Bpl\Livewire\Finance\InvoicePayments::TOLERANCE)
            <div class="form-error" style="margin-top:.4rem;">That is more than the invoice — this will not save.</div>
        @endif
    </div>
@else
    <div class="text-muted text-sm">
        This shipment has no priced proforma lines, so there is no invoice value to check the payment against.
    </div>
@endif
