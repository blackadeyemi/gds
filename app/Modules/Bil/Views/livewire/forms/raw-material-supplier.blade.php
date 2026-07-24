<div class="form-group">
    <label class="form-label">Supplier Name</label>
    <input type="text" class="form-control" wire:model="suppliername" placeholder="e.g. 4 TEES NIG. LTD" autofocus>
    @error('suppliername') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Supplier ID</label>
        <input type="text" class="form-control" wire:model="supplierid" placeholder="e.g. 40110008">
        @error('supplierid') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Supplier Code</label>
        <input type="text" class="form-control" wire:model="suppliercode" placeholder="e.g. 4T">
        @error('suppliercode') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>
