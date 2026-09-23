<div class="form-group">
    <label class="form-label">Transporter Name</label>
    <input type="text" class="form-control" wire:model="transportername"
           placeholder="e.g. Akingbade Transport" maxlength="100" autofocus>
    @error('transportername') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label class="form-label">Transporter Code</label>
    {{-- System-assigned, never typed: it has to stay unique and mean nothing,
         so there is nothing here for anyone to decide. Shown read-only so it
         can be read out or copied onto paperwork. --}}
    <input type="text" class="form-control mono" readonly
           value="{{ $transportercode ?? 'Assigned when you save' }}"
           style="background:var(--surface-2);color:var(--muted);cursor:default;">
    <div class="form-hint">
        Eight digits, generated automatically and fixed for the life of the transporter.
    </div>
</div>
