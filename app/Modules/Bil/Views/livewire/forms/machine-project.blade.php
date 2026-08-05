{{--
    As with lines: blank Parent = a top-level project, otherwise a sub-project.
    A sub-project runs on its parent's line, so Line is hidden when parented.
--}}
<div class="form-group">
    <label class="form-label">Parent project</label>
    <select class="form-control" wire:model.live="parent_id">
        <option value="">— None (this is a top-level project) —</option>
        @foreach ($this->parents as $p)
            <option value="{{ $p->id }}">{{ $p->name }}</option>
        @endforeach
    </select>
    @error('parent_id') <div class="form-error">{{ $message }}</div> @enderror
</div>

@if (! $parent_id)
<div class="form-group">
    <label class="form-label">Line</label>
    <select class="form-control" wire:model="line_id">
        <option value="">— Select line —</option>
        @foreach ($this->lines as $l)
            <option value="{{ $l->id }}">{{ $l->parent_id ? '— ' : '' }}{{ $l->name }}</option>
        @endforeach
    </select>
    <div class="form-hint">Indented entries are sub-lines. Most projects sit on a sub-line.</div>
    @error('line_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
@endif

<div class="form-group">
    <label class="form-label">Project name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. GAMBINI REWINDER 01" autofocus>
    <div class="form-hint">Must be unique across all projects and sub-projects — maintenance history is still matched on this name.</div>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label class="form-label">Code</label>
    <input type="text" class="form-control" wire:model="code" placeholder="e.g. GR001" maxlength="32">
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
