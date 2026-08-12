<div class="form-group">
    <label class="form-label">Company</label>
    @include('core::partials.searchable-select', [
        'field' => 'company_id',
        'options' => $this->companies,
        'placeholder' => '— Select company —',
    ])
    @error('company_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Warehouse name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. Store FB" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Code <span style="font-weight:400">(optional)</span></label>
    <input type="text" class="form-control" wire:model="code" placeholder="e.g. FB">
    <div class="text-muted text-sm" style="margin-top:.25rem;">Short label for exports and printouts.</div>
    @error('code') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Sort order</label>
    <input type="number" class="form-control" wire:model="sort_order" min="0">
    <div class="text-muted text-sm" style="margin-top:.25rem;">Lower numbers appear first in pickers.</div>
    @error('sort_order') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Status</label>
    <select class="form-control" wire:model="is_active">
        <option value="1">Active</option>
        <option value="0">Inactive</option>
    </select>
    <div class="text-muted text-sm" style="margin-top:.25rem;">Inactive warehouses are hidden from pickers but keep their stock and history.</div>
    @error('is_active') <div class="form-error">{{ $message }}</div> @enderror
</div>
