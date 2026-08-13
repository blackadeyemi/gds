@php $mayEdit = $this->mayEdit(); @endphp

<div>
    <div class="page-head">
        <h1>Waste Settings</h1>
        <p>The vocabulary the Conversion Waste screen is built from. <strong>Causes</strong> are the reasons waste was produced; <strong>origins</strong> are what it was made of. Every cause is offered under every origin, so the two lists are independent.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    <form wire:submit="save">

        {{-- ---------------- Causes ---------------- --}}
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Causes of waste</h2>
                    <div class="text-sm text-muted">Why the waste happened — offered under every origin.</div>
                </div>
            </div>

            <div class="card-pad">
                <table class="data" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Cause</th>
                            <th style="width:100px;">Order</th>
                            <th style="width:110px;text-align:center;">In use</th>
                            <th style="width:90px;text-align:center;">Active</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($causes as $i => $row)
                            <tr wire:key="cause-{{ $row['uid'] }}">
                                <td>
                                    <input type="text" class="form-control" placeholder="e.g. Bad Cuts"
                                           wire:model="causes.{{ $i }}.name" @disabled(! $mayEdit)>
                                    @error("causes.{$i}.name") <div class="form-error">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="number" min="0" class="form-control" wire:model="causes.{{ $i }}.sort_order" @disabled(! $mayEdit)>
                                </td>
                                <td style="text-align:center;">
                                    @php $used = $row['id'] ? ($causeUsage[$row['id']] ?? 0) : 0; @endphp
                                    @if ($used)
                                        <span class="badge badge-muted">{{ number_format($used) }} entries</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td style="text-align:center;">
                                    <input type="checkbox" wire:model="causes.{{ $i }}.is_active" @disabled(! $mayEdit)>
                                </td>
                                <td style="text-align:center;">
                                    @if ($mayEdit)
                                        <button type="button" class="btn btn-danger btn-icon btn-sm" wire:click="removeCause({{ $i }})"
                                                title="{{ $used ? 'Used by existing entries — will be retired, not deleted' : 'Remove' }}">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="empty-row text-muted">No causes yet — add one below.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($mayEdit)
                    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:0.6rem;" wire:click="addCause">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        Add cause
                    </button>
                @endif

                @if ($retiredCauses->isNotEmpty())
                    <div class="text-sm text-muted" style="margin-top:0.9rem;">
                        <strong>Retired:</strong>
                        {{ $retiredCauses->pluck('name')->join(', ') }}
                        — kept because existing waste entries refer to them.
                    </div>
                @endif
            </div>
        </div>

        {{-- ---------------- Origins ---------------- --}}
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Waste origins</h2>
                    <div class="text-sm text-muted">What the waste was made of. <strong>Classified by</strong> chooses which list the entry is then attributed to.</div>
                </div>
            </div>

            <div class="card-pad">
                <table class="data" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Origin</th>
                            <th style="width:230px;">Classified by</th>
                            <th style="width:100px;">Order</th>
                            <th style="width:110px;text-align:center;">In use</th>
                            <th style="width:90px;text-align:center;">Active</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($origins as $i => $row)
                            <tr wire:key="origin-{{ $row['uid'] }}">
                                <td>
                                    <input type="text" class="form-control" placeholder="e.g. Jumbo Roll"
                                           wire:model="origins.{{ $i }}.label" @disabled(! $mayEdit)>
                                    @if ($row['id'])
                                        <div class="text-sm text-muted" style="font-family:monospace;">{{ $row['key'] }}</div>
                                    @endif
                                    @error("origins.{$i}.label") <div class="form-error">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <select class="form-control" wire:model="origins.{{ $i }}.source" @disabled(! $mayEdit)>
                                        @foreach ($sources as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error("origins.{$i}.source") <div class="form-error">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="number" min="0" class="form-control" wire:model="origins.{{ $i }}.sort_order" @disabled(! $mayEdit)>
                                </td>
                                <td style="text-align:center;">
                                    @php $used = $row['id'] ? ($originUsage[$row['id']] ?? 0) : 0; @endphp
                                    @if ($used)
                                        <span class="badge badge-muted">{{ number_format($used) }} entries</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td style="text-align:center;">
                                    <input type="checkbox" wire:model="origins.{{ $i }}.is_active" @disabled(! $mayEdit)>
                                </td>
                                <td style="text-align:center;">
                                    @if ($mayEdit)
                                        <button type="button" class="btn btn-danger btn-icon btn-sm" wire:click="removeOrigin({{ $i }})"
                                                title="{{ $used ? 'Used by existing entries — will be retired, not deleted' : 'Remove' }}">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="empty-row text-muted">No origins yet — add one below.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($mayEdit)
                    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:0.6rem;" wire:click="addOrigin">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        Add origin
                    </button>
                @endif

                @if ($retiredOrigins->isNotEmpty())
                    <div class="text-sm text-muted" style="margin-top:0.9rem;">
                        <strong>Retired:</strong> {{ $retiredOrigins->pluck('label')->join(', ') }}
                        — kept because existing waste entries refer to them.
                    </div>
                @endif
            </div>
        </div>

        @if ($mayEdit)
            <div class="flex" style="justify-content:flex-end;">
                <button type="submit" class="btn btn-primary">Save settings</button>
            </div>
        @endif
    </form>
</div>
