{{--
    One form for both levels: leave Parent line blank for a top-level line, or
    pick one to create a sub-line under it. A sub-line inherits its parent's
    factory, so the Factory field is only shown when there is no parent.
--}}
<div class="form-group">
    <label class="form-label">Parent line</label>
    <select class="form-control" wire:model.live="parent_id">
        <option value="">— None (this is a top-level line) —</option>
        @foreach ($this->parents as $p)
            <option value="{{ $p->id }}">{{ $p->name }}</option>
        @endforeach
    </select>
    @error('parent_id') <div class="form-error">{{ $message }}</div> @enderror
</div>

@if (! $parent_id)
<div class="form-group">
    <label class="form-label">Factory</label>
    <select class="form-control" wire:model="factory_id">
        <option value="">— Unassigned —</option>
        @foreach ($this->factories as $f)
            <option value="{{ $f->id }}">{{ $f->name }}{{ $f->company ? ' · ' . $f->company->name : '' }}</option>
        @endforeach
    </select>
    @error('factory_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
@endif

<div class="form-group">
    <label class="form-label">Line name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. REW 11" autofocus>
    <div class="form-hint">Must be unique across all lines and sub-lines — production history is still matched on this name.</div>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label class="form-label">Code</label>
    <input type="text" class="form-control" wire:model="code" placeholder="e.g. R11" maxlength="16">
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
