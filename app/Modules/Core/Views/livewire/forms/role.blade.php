<div class="form-group">
    <label class="form-label">Role name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. Warehouse Operator" autofocus>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Description</label>
    <input type="text" class="form-control" wire:model="description" placeholder="Optional description">
    @error('description') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group mb-0">
    <label class="form-label">Abilities per page</label>
    <p class="text-sm text-muted" style="margin:-0.2rem 0 0.6rem;">Tick what this role can do. <strong>View</strong> grants access to the page.</p>
    <div style="max-height:52vh;overflow:auto;border:1px solid var(--line);border-radius:8px;">
        @php $cols = $this->matrix['columns']; @endphp
        <table class="data" style="width:100%;min-width:640px;">
            <thead style="position:sticky;top:0;z-index:1;background:var(--surface,#fff);">
                <tr>
                    <th style="text-align:left;">Page</th>
                    @foreach ($cols as $ability => $colLabel)
                        <th style="text-align:center;font-size:0.72rem;white-space:nowrap;">{{ $colLabel }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($this->matrix['groups'] as $module => $pages)
                    <tr>
                        <td colspan="{{ count($cols) + 1 }}" style="background:var(--hover,#f6f7f9);font-weight:700;font-size:0.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);">{{ $module }}</td>
                    </tr>
                    @foreach ($pages as $page)
                        <tr wire:key="row-{{ $page['key'] }}">
                            <td style="white-space:nowrap;">{{ $page['label'] }}</td>
                            @foreach ($cols as $ability => $colLabel)
                                <td style="text-align:center;">
                                    @if (in_array($ability, $page['abilities']))
                                        <input type="checkbox" value="{{ $page['key'] }}:{{ $ability }}" wire:model="granted">
                                    @else
                                        <span class="text-muted" style="opacity:.25;">·</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
    @error('granted') <div class="form-error">{{ $message }}</div> @enderror
</div>
