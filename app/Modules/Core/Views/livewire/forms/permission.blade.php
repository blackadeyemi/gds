<div class="form-group">
    <label class="form-label">Permission name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. edit-department" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Description</label>
    <input type="text" class="form-control" wire:model="description" placeholder="Optional description">
    @error('description') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Module</label>
    <select class="form-control" wire:model="module_id">
        <option value="">— Unassigned —</option>
        @foreach ($this->modules as $m)
            <option value="{{ $m->id }}">{{ $m->name }}</option>
        @endforeach
    </select>
    @error('module_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
