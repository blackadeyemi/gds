<div>
    <div class="page-head">
        <h1>Shift Settings</h1>
        <p>Set the Day/Night (or any named) shift windows per area. Turn an area <strong>Active</strong> to enforce its windows; while inactive it stays open anytime. Times use the {{ config('app.timezone') }} clock and may cross midnight.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif

    <form wire:submit="save">
        @forelse ($grouped as $module => $contexts)
            <div class="text-sm" style="font-weight:700;color:var(--muted);text-transform:uppercase;font-size:0.72rem;letter-spacing:.06em;margin:0.5rem 0 0.4rem;">{{ $module ?: 'Other' }}</div>

            @foreach ($contexts as $ctx)
                <div class="card" style="margin-bottom:1rem;" wire:key="ctx-{{ $ctx->id }}">
                    <div class="card-head" style="flex-wrap:wrap;gap:0.75rem;">
                        <div>
                            <h2 class="card-title">{{ $ctx->label }}</h2>
                            <div class="text-sm text-muted" style="font-family:monospace;">{{ $ctx->key }}</div>
                        </div>

                        <div class="flex items-center gap-2" style="margin-left:auto;flex-wrap:wrap;">
                            {{-- Live status of the SAVED config --}}
                            @php $s = $ctx->status; @endphp
                            @if (! $s['gated'])
                                <span class="badge badge-muted">Ungated · open anytime</span>
                            @elseif ($s['open'])
                                <span class="badge badge-success">Open now · {{ $s['current'] }}</span>
                            @else
                                <span class="badge" style="background:rgba(220,38,38,.12);color:var(--danger);">Closed · reopens {{ $s['next_window'] }} {{ optional($s['next_open_at'])->format('D H:i') }}</span>
                            @endif

                            <label class="flex items-center gap-2" style="cursor:pointer;font-size:0.9rem;">
                                <input type="checkbox" wire:model="active.{{ $ctx->id }}">
                                <span>Active</span>
                            </label>
                        </div>
                    </div>

                    <div class="card-pad">
                        <table class="data" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>Shift name</th>
                                    <th style="width:130px;">Start</th>
                                    <th style="width:130px;">End</th>
                                    <th style="width:90px;text-align:center;">Enabled</th>
                                    <th style="width:60px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($windows[$ctx->id] ?? [] as $i => $row)
                                    <tr wire:key="win-{{ $row['uid'] }}">
                                        <td>
                                            <input type="text" class="form-control" placeholder="e.g. Day" wire:model="windows.{{ $ctx->id }}.{{ $i }}.name">
                                            @error("windows.{$ctx->id}.{$i}.name") <div class="form-error">{{ $message }}</div> @enderror
                                        </td>
                                        <td>
                                            <input type="time" class="form-control" wire:model="windows.{{ $ctx->id }}.{{ $i }}.start">
                                            @error("windows.{$ctx->id}.{$i}.start") <div class="form-error">{{ $message }}</div> @enderror
                                        </td>
                                        <td>
                                            <input type="time" class="form-control" wire:model="windows.{{ $ctx->id }}.{{ $i }}.end">
                                            @error("windows.{$ctx->id}.{$i}.end") <div class="form-error">{{ $message }}</div> @enderror
                                        </td>
                                        <td style="text-align:center;">
                                            <input type="checkbox" wire:model="windows.{{ $ctx->id }}.{{ $i }}.enabled">
                                        </td>
                                        <td style="text-align:center;">
                                            <button type="button" class="btn btn-danger btn-icon btn-sm" wire:click="removeWindow({{ $ctx->id }}, {{ $i }})" title="Remove shift">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="empty-row text-muted">No shifts yet — add one below.</td></tr>
                                @endforelse
                            </tbody>
                        </table>

                        <button type="button" class="btn btn-ghost btn-sm" style="margin-top:0.6rem;" wire:click="addWindow({{ $ctx->id }})">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                            Add shift
                        </button>
                    </div>
                </div>
            @endforeach
        @empty
            <div class="card card-pad text-muted">No shift contexts registered yet. Run <code>php artisan gds:sync-shift-contexts</code>.</div>
        @endforelse

        @if (count($grouped))
            <div class="flex" style="justify-content:flex-end;">
                <button type="submit" class="btn btn-primary">Save settings</button>
            </div>
        @endif
    </form>
</div>
