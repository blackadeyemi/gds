<div class="form-group">
    <label class="form-label">Company name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. BELIMPEX" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
