<div class="form-group">
    <label class="form-label">Bank Name</label>
    <input type="text" class="form-control" wire:model="name" maxlength="100"
           placeholder="e.g. Zenith Bank Plc" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label class="form-label">Account Number</label>
    <input type="text" class="form-control mono" wire:model="number" maxlength="100" autocomplete="off">
    @error('number') <div class="form-error">{{ $message }}</div> @enderror
    <div class="form-hint">Leave empty for a routing bank (intermediary or correspondent) BPL holds no account with.</div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Sort Code</label>
        <input type="text" class="form-control mono" wire:model="sortcode" maxlength="20" inputmode="numeric" autocomplete="off">
        @error('sortcode') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Swift Code</label>
        <input type="text" class="form-control mono" wire:model="swiftcode" maxlength="20" autocomplete="off"
               style="text-transform:uppercase;" placeholder="e.g. ZEIBNGLA">
        @error('swiftcode') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div class="form-group">
    <label class="form-label">Address</label>
    <textarea class="form-control" wire:model="address" rows="2" maxlength="500"></textarea>
    @error('address') <div class="form-error">{{ $message }}</div> @enderror
</div>
