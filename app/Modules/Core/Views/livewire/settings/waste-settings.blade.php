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

        {{-- ---------------- The cut-over ---------------- --}}
        @php
            $impact = $this->cutoverImpact;
            $meta = $this->cutoverMeta;
        @endphp
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Confirmation cut-over</h2>
                    <div class="text-sm text-muted">Production before this date never has to be confirmed.</div>
                </div>
            </div>

            <div class="card-pad">
                <div style="display:grid;grid-template-columns:minmax(180px,220px) 1fr;gap:1rem;align-items:start;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Waste is confirmed from</label>
                        <input type="date" class="form-control" wire:model.live="cutover" @disabled(! $mayEdit)>
                        @error('cutover') <div class="form-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="text-sm text-muted">
                        <p style="margin:0 0 .5rem;">
                            Runs on or after this date appear on Conversion Waste and block the next run
                            on their line until confirmed. Anything earlier is history — still in the
                            reports, never a blocker.
                        </p>
                        <p style="margin:0;">
                            @if ($this->cutoverIsOverridden())
                                Set here, overriding <code>WASTE_CONFIRMATION_START</code>
                                (<strong>{{ $this->cutoverConfigured() ?: 'unset' }}</strong>) in the environment.
                                @if ($meta?->updated_by_name)
                                    Last changed by <strong>{{ $meta->updated_by_name }}</strong>
                                    on {{ \Illuminate\Support\Carbon::parse($meta->updated_at)->format('d/m/Y H:i') }}.
                                @endif
                                @if ($mayEdit)
                                    <button type="button" class="btn btn-ghost btn-sm" style="margin-left:.4rem;"
                                            wire:click="revertCutover"
                                            wire:confirm="Revert to the environment setting ({{ $this->cutoverConfigured() ?: 'unset' }})?">
                                        Revert to environment
                                    </button>
                                @endif
                            @else
                                Coming from <code>WASTE_CONFIRMATION_START</code> in the environment.
                                Changing it here overrides that.
                            @endif
                        </p>
                    </div>
                </div>

                {{-- Both directions are consequential, so the cost is shown
                     before it is paid rather than discovered afterwards. --}}
                @if ($impact)
                    <div class="card" style="margin-top:0.9rem;padding:0.8rem 1.1rem;border-color:{{ $impact['direction'] === 'later' ? 'var(--danger)' : 'var(--accent,#3b82f6)' }};">
                        <div style="font-weight:600;">
                            @if ($impact['direction'] === 'later')
                                Moving the cut-over <em>later</em> — {{ abs($impact['delta']) }} run(s) would stop needing confirmation
                            @else
                                Moving the cut-over <em>earlier</em> — {{ abs($impact['delta']) }} more run(s) would need confirmation
                            @endif
                        </div>
                        <div class="text-sm" style="margin-top:0.3rem;">
                            Open runs today: <strong>{{ number_format($impact['now']) }}</strong>
                            (from {{ $impact['current'] }}) →
                            <strong>{{ number_format($impact['then']) }}</strong> after this change.
                            @if ($impact['direction'] === 'later')
                                Those runs disappear from the queue and stop blocking their lines —
                                <strong>their waste will never be asked for</strong>. This change is
                                recorded against your name.
                            @else
                                Every affected line will block until those runs are confirmed.
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>

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
