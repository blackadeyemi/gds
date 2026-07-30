{{-- Read-only view of a role's permissions, grouped by module. --}}
<div class="modal-backdrop" x-data x-show="$wire.viewingRoleId !== null" x-cloak
     @keydown.escape.window="$wire.viewingRoleId = null" style="display:none;">
    <div class="modal-card" style="max-width:660px;" @click.outside="$wire.viewingRoleId = null">
        @if ($this->viewingRole)
            <div class="modal-head">
                <div>
                    <h3 class="modal-title">{{ $this->viewingRole['name'] }}</h3>
                    @if ($this->viewingRole['description'])
                        <p class="text-muted text-sm mb-0" style="margin-top:0.15rem;">{{ $this->viewingRole['description'] }}</p>
                    @endif
                </div>
                <button class="modal-close" wire:click="$set('viewingRoleId', null)">&times;</button>
            </div>
            <div class="modal-body">
                <div class="flex items-center gap-2" style="margin-bottom:0.85rem;">
                    <span class="text-sm text-muted">Abilities</span>
                    <span class="badge badge-muted">{{ $this->viewingRole['count'] }}</span>
                </div>

                @forelse ($this->viewingRole['groups'] as $module => $pages)
                    <div style="margin-bottom:0.8rem;">
                        <div class="text-sm" style="font-weight:700;color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:.05em;margin:0.3rem 0;">{{ $module }}</div>
                        @foreach ($pages as $page)
                            <div style="display:flex;align-items:baseline;gap:0.5rem;font-size:0.88rem;padding:0.15rem 0;">
                                <span style="min-width:150px;font-weight:500;">{{ $page['label'] }}</span>
                                <span style="display:flex;flex-wrap:wrap;gap:0.3rem;">
                                    @foreach ($page['abilities'] as $ability)
                                        <span class="badge badge-success">{{ $ability }}</span>
                                    @endforeach
                                </span>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="text-muted text-sm mb-0">This role has no abilities assigned.</p>
                @endforelse
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" wire:click="$set('viewingRoleId', null)">Close</button>
                @if ($this->editable())
                    <button type="button" class="btn btn-primary" wire:click="edit({{ $viewingRoleId }})">Edit role</button>
                @endif
            </div>
        @endif
    </div>
</div>
