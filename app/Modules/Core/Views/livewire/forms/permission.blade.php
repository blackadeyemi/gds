<div class="form-group">
    <label class="form-label">Permission name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. Warehouse Operator" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Description</label>
    <input type="text" class="form-control" wire:model="description" placeholder="Optional description">
    @error('description') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Pages this permission grants access to</label>
    <div style="max-height:360px;overflow-y:auto;border:1px solid var(--line);border-radius:8px;padding:0.5rem;">
        @forelse ($this->pagesByModule as $module => $pages)
            <div style="margin-bottom:0.7rem;">
                <div class="flex items-center justify-between" style="margin:0.3rem 0;">
                    <span class="text-sm" style="font-weight:700;color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:.05em;">{{ $module }}</span>
                    <label class="text-sm text-muted" style="display:flex;align-items:center;gap:0.35rem;cursor:pointer;font-size:0.72rem;">
                        <input type="checkbox"
                               @checked(collect($pages)->every(fn ($p) => in_array($p->id, $selectedPages)))
                               x-on:change="$wire.toggleModulePages('{{ $module }}', $event.target.checked)">
                        all
                    </label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.25rem 1rem;">
                    @foreach ($pages as $page)
                        <label style="display:flex;align-items:center;gap:0.45rem;font-size:0.88rem;cursor:pointer;">
                            <input type="checkbox" value="{{ $page->id }}" wire:model.live="selectedPages">
                            {{ $page->label }}
                        </label>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-muted text-sm mb-0">No pages registered. Run <code>php artisan gds:sync-pages</code>.</p>
        @endforelse
    </div>
    @error('selectedPages') <div class="form-error">{{ $message }}</div> @enderror
</div>
