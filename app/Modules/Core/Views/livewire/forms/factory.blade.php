<div class="form-group">
    <label class="form-label">Company</label>
    <select class="form-control" wire:model="company_id">
        <option value="">— Select company —</option>
        @foreach ($this->companies as $c)
            <option value="{{ $c->id }}">{{ $c->name }}{{ $c->code ? ' (' . $c->code . ')' : '' }}</option>
        @endforeach
    </select>
    @error('company_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Factory name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. Gambini" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Code</label>
    <input type="text" class="form-control" wire:model="code" placeholder="e.g. PM3" maxlength="16" style="text-transform:uppercase">
    <div class="form-hint">Used to match existing production records — change it only if you know what depends on it.</div>
    @error('code') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Status</label>
    <select class="form-control" wire:model="is_active">
        <option value="1">Active</option>
        <option value="0">Inactive</option>
    </select>
    @error('is_active') <div class="form-error">{{ $message }}</div> @enderror
</div>
