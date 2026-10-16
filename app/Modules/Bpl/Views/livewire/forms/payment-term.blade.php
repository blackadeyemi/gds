<div class="form-group">
    <label class="form-label">Payment Term</label>
    <input type="text" class="form-control" wire:model="payment_terms" maxlength="150"
           placeholder="e.g. 30 Days After BL" autofocus>
    @error('payment_terms') <div class="form-error">{{ $message }}</div> @enderror
    <div class="form-hint">Printed word for word on the proforma and the invoice.</div>
</div>

<div class="form-group" style="max-width:180px;">
    <label class="form-label">Number of Days</label>
    <input type="number" class="form-control qty-input" wire:model="days" min="1" max="3650" step="1" placeholder="30">
    @error('days') <div class="form-error">{{ $message }}</div> @enderror
    <div class="form-hint">Days until payment falls due. Cash in advance is 1.</div>
</div>
