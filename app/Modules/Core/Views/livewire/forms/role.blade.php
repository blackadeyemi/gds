<div class="form-group">
    <label class="form-label">Role name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. Report Manager" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Description</label>
    <input type="text" class="form-control" wire:model="description" placeholder="Optional description">
    @error('description') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Permissions</label>
    <div style="max-height:340px;overflow-y:auto;border:1px solid var(--line);border-radius:8px;padding:0.5rem;">
        @forelse ($this->groupedPermissions as $module => $perms)
            <div style="margin-bottom:0.6rem;">
                <div class="text-sm" style="font-weight:700;color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:.05em;margin:0.3rem 0;">{{ $module }}</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.25rem 1rem;">
                    @foreach ($perms as $perm)
                        <label style="display:flex;align-items:center;gap:0.45rem;font-size:0.88rem;cursor:pointer;">
                            <input type="checkbox" value="{{ $perm->id }}" wire:model="selectedPermissions">
                            {{ $perm->name }}
                        </label>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-muted text-sm mb-0">No permissions defined yet. Add some under Permissions first.</p>
        @endforelse
    </div>
</div>
