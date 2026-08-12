<div class="form-group">
    <label class="form-label">Warehouse</label>
    @include('core::partials.searchable-select', [
        'field' => 'warehouse_id',
        'options' => $this->warehouses,
        'placeholder' => '— Unassigned —',
    ])
    <div class="text-muted text-sm" style="margin-top:.25rem;">
        Goods received here move this warehouse's stock. <strong>An unassigned entrance cannot be used to receive.</strong>
    </div>
    @error('warehouse_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Entrance name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. FG Store FB Elevator 1" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
@if ($legacy_name)
    <div class="form-group">
        <label class="form-label">Legacy name</label>
        <input type="text" class="form-control" value="{{ $legacy_name }}" disabled>
        <div class="text-muted text-sm" style="margin-top:.25rem;">
            Set on import and not editable — it is how historic receipts are matched to this entrance.
        </div>
    </div>
@endif
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
    <div class="text-muted text-sm" style="margin-top:.25rem;">Inactive entrances are hidden from the receiving screen but keep their history.</div>
    @error('is_active') <div class="form-error">{{ $message }}</div> @enderror
</div>
