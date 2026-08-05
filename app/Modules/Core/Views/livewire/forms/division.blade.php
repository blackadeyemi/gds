<div class="form-group">
    <label class="form-label">Department</label>
    <select class="form-control" wire:model="department_id">
        <option value="">— Select department —</option>
        @foreach ($this->departments as $d)
            <option value="{{ $d->id }}">{{ $d->name }}</option>
        @endforeach
    </select>
    @error('department_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Division name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. Electrical" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
@if ($legacy_name !== '')
    <div class="form-group">
        <label class="form-label">Legacy name</label>
        <input type="text" class="form-control" value="{{ $legacy_name }}" disabled>
        <div class="form-hint">What the old system calls this division. Service history is still matched on it, so it can't be edited here.</div>
    </div>
@endif
<div class="form-group">
    <label class="form-label">Status</label>
    <select class="form-control" wire:model="is_active">
        <option value="1">Active</option>
        <option value="0">Inactive</option>
    </select>
    @error('is_active') <div class="form-error">{{ $message }}</div> @enderror
</div>
