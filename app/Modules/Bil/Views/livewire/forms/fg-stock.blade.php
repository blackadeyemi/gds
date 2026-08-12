<div class="form-group">
    <label class="form-label">Product</label>
    <input type="text" class="form-control" value="{{ $productLabel }}" disabled>
</div>
<div class="form-group">
    <label class="form-label">Warehouse</label>
    <input type="text" class="form-control" value="{{ $warehouseLabel }}" disabled>
</div>
<div class="form-group">
    <label class="form-label">Bundles in stock</label>
    <input type="number" class="form-control" wire:model="bundles" step="1">
    <div class="text-muted text-sm" style="margin-top:.25rem;">
        Currently <strong>{{ number_format($currentBundles) }}</strong>.
        Saving records the difference as an adjustment rather than overwriting the figure,
        so the total can still be proved from its movements.
    </div>
    @error('bundles') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Reason <span style="font-weight:400">(optional)</span></label>
    <input type="text" class="form-control" wire:model="reason" placeholder="e.g. Opening balance, stock count 12 Aug">
    <div class="text-muted text-sm" style="margin-top:.25rem;">
        Recorded against the adjustment. Worth filling in for anything other than an obvious correction.
    </div>
    @error('reason') <div class="form-error">{{ $message }}</div> @enderror
</div>
