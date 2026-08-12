<div class="form-group">
    <label class="form-label">Factory</label>
    @include('core::partials.searchable-select', [
        'field' => 'factory_id',
        'options' => $this->factories,
        'placeholder' => '— Unassigned —',
    ])
    <div class="text-muted text-sm" style="margin-top:.25rem;">
        <strong>An unassigned gate cannot be used.</strong>
    </div>
    @error('factory_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Gate name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. BIL1-Gate 1" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Direction</label>
    <select class="form-control" wire:model="direction">
        @foreach ($this->directions as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>
    <div class="text-muted text-sm" style="margin-top:.25rem;">
        <strong>Exit</strong> = finished goods leaving for the warehouse.
        <strong>Entrance</strong> = raw material arriving at the factory.
    </div>
    @error('direction') <div class="form-error">{{ $message }}</div> @enderror
</div>
@if ($legacy_name)
    <div class="form-group">
        <label class="form-label">Legacy name</label>
        <input type="text" class="form-control" value="{{ $legacy_name }}" disabled>
        <div class="text-muted text-sm" style="margin-top:.25rem;">
            Set on import and not editable — it is how historic movements are matched to this gate.
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
    <div class="text-muted text-sm" style="margin-top:.25rem;">Inactive gates are hidden from the scanning screens but keep their history.</div>
    @error('is_active') <div class="form-error">{{ $message }}</div> @enderror
</div>
