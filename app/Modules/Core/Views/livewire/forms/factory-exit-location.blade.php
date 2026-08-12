<div class="form-group">
    <label class="form-label">Factory</label>
    @include('core::partials.searchable-select', [
        'field' => 'factory_id',
        'options' => $this->factories,
        'placeholder' => '— Unassigned —',
    ])
    <div class="text-muted text-sm" style="margin-top:.25rem;">
        <strong>An unassigned exit location cannot be used.</strong>
    </div>
    @error('factory_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Exit location name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. BIL1-Gate 1" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Sort order</label>
    <input type="number" class="form-control" wire:model="sort_order" min="0">
    @error('sort_order') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Status</label>
    <select class="form-control" wire:model="is_active">
        <option value="1">Active</option>
        <option value="0">Inactive</option>
    </select>
    <div class="text-muted text-sm" style="margin-top:.25rem;">Inactive locations are hidden from the exit screen but keep their history.</div>
    @error('is_active') <div class="form-error">{{ $message }}</div> @enderror
</div>
