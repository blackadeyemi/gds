<div class="form-group">
    <label class="form-label">Service type</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. Repair" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Sort order</label>
    <input type="number" class="form-control" wire:model="sort_order" min="0">
    <div class="form-hint">Lower numbers appear first in the Services form.</div>
    @error('sort_order') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Status</label>
    <select class="form-control" wire:model="is_active">
        <option value="1">Active</option>
        <option value="0">Inactive</option>
    </select>
    @error('is_active') <div class="form-error">{{ $message }}</div> @enderror
</div>
