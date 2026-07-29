<div class="form-group">
    <label class="form-label">Grade</label>
    <select class="form-control" wire:model="grade_id" autofocus>
        <option value="">— Select grade —</option>
        @foreach ($this->grades as $g)
            <option value="{{ $g->id }}">{{ $g->type }} — {{ $g->gradename }}</option>
        @endforeach
    </select>
    @error('grade_id') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Grammage (gsm)</label>
        <input type="text" class="form-control" wire:model="grammage" placeholder="e.g. 25">
        @error('grammage') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Diameter</label>
        <input type="text" class="form-control" wire:model="diameter" placeholder="e.g. 240">
        @error('diameter') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>
