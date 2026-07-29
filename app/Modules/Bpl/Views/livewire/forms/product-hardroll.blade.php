<div class="form-group">
    <label class="form-label">Grade Type</label>
    <select class="form-control" wire:model="gradetype" autofocus>
        <option value="">— Select grade type —</option>
        @foreach ($this->gradeTypes as $t)
            <option value="{{ $t }}">{{ $t }}</option>
        @endforeach
    </select>
    @error('gradetype') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">GSM (g/m²)</label>
        <input type="number" step="0.01" min="0" class="form-control" wire:model="gsm">
        @error('gsm') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Ply</label>
        <input type="number" step="1" min="1" class="form-control" wire:model="ply">
        @error('ply') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Hardroll Width (cm)</label>
        <input type="number" step="0.01" min="0" class="form-control" wire:model="width">
        @error('width') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Brightness (% ISO)</label>
        <input type="number" step="0.01" min="0" class="form-control" wire:model="brightness" placeholder="Optional">
        @error('brightness') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Diameter (cm)</label>
        <input type="number" step="0.01" min="0" class="form-control" wire:model="diameter">
        @error('diameter') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Slice</label>
        <input type="number" step="1" min="1" class="form-control" wire:model="slice">
        @error('slice') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>
