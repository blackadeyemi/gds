<div class="form-group">
    <label class="form-label">Company name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. BELIMPEX" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Code</label>
    <input type="text" class="form-control" wire:model="code" placeholder="e.g. BIL" maxlength="8" style="text-transform:uppercase">
    @error('code') <div class="form-error">{{ $message }}</div> @enderror
</div>
