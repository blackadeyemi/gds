<div class="form-group">
    <label class="form-label">Product Name</label>
    <input type="text" class="form-control" wire:model="productname" placeholder="e.g. ALUMINIUM FOIL 300CM" autofocus>
    @error('productname') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Store Code</label>
        <input type="text" class="form-control" wire:model="storecode" placeholder="e.g. AM000ALFL300">
        @error('storecode') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Account Code</label>
        <input type="text" class="form-control" wire:model="accountcode" placeholder="Optional">
        @error('accountcode') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Group</label>
        <select class="form-control" wire:model.live="groupid">
            <option value="">— Select group —</option>
            @foreach ($this->groups as $g)
                <option value="{{ $g->id }}">{{ $g->groupname }}</option>
            @endforeach
        </select>
        @error('groupid') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Sub-Group</label>
        <select class="form-control" wire:model="subgroupid">
            <option value="">— Select sub-group —</option>
            @foreach ($this->subgroups as $s)
                <option value="{{ $s->id }}">{{ $s->subgroupname }}</option>
            @endforeach
        </select>
        @error('subgroupid') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div class="form-group">
    <label class="form-label">Common Consumption</label>
    <select class="form-control" wire:model="common">
        <option value="No">No</option>
        <option value="Yes">Yes</option>
    </select>
    @error('common') <div class="form-error">{{ $message }}</div> @enderror
</div>
