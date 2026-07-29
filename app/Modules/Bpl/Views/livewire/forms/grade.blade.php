<div class="form-group">
    <label class="form-label">Grade Name</label>
    <input type="text" class="form-control" wire:model="gradename" placeholder="e.g. SOFT TISSUE NATURAL" autofocus>
    @error('gradename') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Grade Type</label>
        <input type="text" class="form-control" wire:model="type" placeholder="e.g. STN">
        @error('type') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Grade</label>
        <input type="text" class="form-control" wire:model="grade" placeholder="Optional">
        @error('grade') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>
