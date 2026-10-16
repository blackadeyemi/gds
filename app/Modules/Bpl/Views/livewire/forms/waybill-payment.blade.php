@php $isNew = ! $editingId; @endphp

<div class="form-group">
    <label class="form-label">Waybill</label>
    @if ($isNew)
        {{-- Only waybills with no record yet, so the same trip cannot be
             raised twice. Customer and truck come with it. --}}
        @include('bil::partials.combobox', [
            'model' => 'barcode',
            'labelText' => 'Waybill',
            'bare' => true,
            'placeholder' => 'Search waybill…',
            'items' => $this->openWaybills->map(fn ($w) => [
                'value' => $w->delivery_barcode,
                'label' => $w->delivery_barcode . ' · ' . ($w->customer_name ?: '—') . ' · ' . ($w->vechile_number ?: 'no truck'),
            ]),
        ])
        <div class="form-hint">Deliveries of the last 120 days that have no payment record yet.</div>
    @else
        <input type="text" class="form-control mono" value="{{ $barcode }}" readonly
               style="background:var(--surface-2);color:var(--muted);cursor:default;">
        <div class="form-hint">A record stays with the trip it was raised for.</div>
    @endif
    @error('barcode') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Transporter</label>
        <select class="form-control" wire:model="transporter_id">
            <option value="">— Choose —</option>
            @foreach ($this->transporters as $t)
                <option value="{{ $t->id }}">{{ $t->transportername }}</option>
            @endforeach
        </select>
        @error('transporter_id') <div class="form-error">{{ $message }}</div> @enderror
    </div>

    <div class="form-group">
        <label class="form-label">Agreed Amount (₦)</label>
        <input type="number" class="form-control qty-input" wire:model="amount" min="1" step="100">
        @error('amount') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

@if ($isNew)
    <div class="card card-pad" style="background:var(--surface-2);margin-top:.25rem;">
        <div class="text-sm" style="font-weight:600;margin-bottom:.6rem;">First payment <span class="text-muted">(optional)</span></div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:0.75rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Amount (₦)</label>
                <input type="number" class="form-control qty-input" wire:model="firstAmount" min="0" step="100">
                @error('firstAmount') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Date</label>
                @include('bil::partials.date-field', ['model' => 'firstDate', 'compact' => true])
                @error('firstDate') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Voucher No.</label>
                <input type="text" class="form-control" wire:model="firstNote" maxlength="100" autocomplete="off">
                @error('firstNote') <div class="form-error">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="form-hint" style="margin-top:.5rem;">
            Leave the amount empty to raise the record with nothing paid. A zero-amount entry records the
            voucher without moving money, as the legacy screen did.
        </div>
    </div>
@else
    <div class="text-muted text-sm" style="margin-top:.5rem;">
        Payments are added from the card button on the row, not here.
    </div>
@endif
