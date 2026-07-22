<div class="form-group">
    <label class="form-label">Name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="Department name" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Company</label>
    <select class="form-control" wire:model="company_id">
        <option value="">— Select company —</option>
        @foreach ($this->companies as $c)
            <option value="{{ $c->id }}">{{ $c->name }}</option>
        @endforeach
    </select>
    @error('company_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Status</label>
    <select class="form-control" wire:model="status">
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
    </select>
    @error('status') <div class="form-error">{{ $message }}</div> @enderror
</div>
