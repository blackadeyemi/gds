{{--
    Conversion Waste: weigh the waste off each run, then close the run.

    A run is one line converting one product in one shift. It appears here
    because pallets were booked against it, and it leaves when its waste is
    confirmed. The list is a QUEUE — oldest first — because an open run blocks
    the next one on the same line, on this screen and on Conversion Output.
--}}
@php
    $run = $this->run;
    $stored = $this->storedRun;
    $entries = $this->entries;
    $blocker = $this->blocker;
    $blocked = $this->isBlocked();
    $confirmed = (bool) $stored?->isConfirmed();
    $openRuns = $this->openRuns;
@endphp

<div>
    <div class="page-head">
        <h1>Conversion Waste</h1>
        <p>Weigh the waste off each run, then confirm it. A run is one line converting one product in one shift — it appears here because pallets were booked against it. <strong>Conversion Output will not start the next run on a line until the previous one is confirmed.</strong></p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    <div style="display:grid;grid-template-columns:minmax(300px,380px) 1fr;gap:1rem;align-items:start;">

        {{-- ---------------- The queue ---------------- --}}
        <div class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Runs awaiting waste</h2>
                    <div class="text-sm text-muted">{{ count($openRuns) }} open — oldest first</div>
                </div>
            </div>

            <div class="card-pad">
                <div class="form-group">
                    <label class="form-label">Line</label>
                    <select class="form-control" wire:model.live="filterLine">
                        <option value="">All lines</option>
                        @foreach ($this->lines as $l)
                            <option value="{{ $l->id }}">{{ $l->parent_id ? '— ' : '' }}{{ $l->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div style="max-height:26rem;overflow:auto;margin:0 -0.4rem;">
                    @forelse ($openRuns as $r)
                        @php $key = \Modules\Bil\Support\ConversionWaste::keyOf($r); @endphp
                        <button type="button" wire:key="run-{{ $key }}" wire:click="selectRun('{{ $key }}')"
                                class="btn btn-ghost"
                                style="display:block;width:100%;text-align:left;padding:0.55rem 0.7rem;margin-bottom:0.2rem;border-radius:6px;{{ $key === $runKey ? 'background:var(--accent-soft,rgba(59,130,246,.12));' : '' }}">
                            <div style="font-weight:600;">{{ $r['line_name'] ?: 'Line #' . $r['line_id'] }}</div>
                            <div class="text-sm text-muted">
                                {{ \Modules\Bil\Support\ConversionWaste::productName($r['productid']) ?: 'Product #' . $r['productid'] }}
                            </div>
                            <div class="text-sm text-muted">
                                {{ \Illuminate\Support\Carbon::parse($r['date'])->format('d/m/Y') }}
                                · <span class="badge {{ $r['shift'] === 'night' ? 'badge-muted' : 'badge-success' }}">{{ ucfirst($r['shift']) }}</span>
                                · {{ number_format($r['pallets']) }} pallets
                            </div>
                        </button>
                    @empty
                        <div class="text-muted" style="padding:0.8rem;">
                            Nothing waiting — every run since the cut-over has had its waste confirmed.
                        </div>
                    @endforelse
                </div>

                <label class="flex items-center gap-2" style="cursor:pointer;font-size:0.9rem;margin-top:0.7rem;">
                    <input type="checkbox" wire:model.live="showConfirmed">
                    <span>Show confirmed runs</span>
                </label>

                @if ($showConfirmed)
                    <div style="max-height:16rem;overflow:auto;margin:0.4rem -0.4rem 0;">
                        @forelse ($this->confirmedRuns as $r)
                            @php $key = \Modules\Bil\Support\ConversionWaste::keyOf($r); @endphp
                            <button type="button" wire:key="cr-{{ $key }}" wire:click="selectRun('{{ $key }}')"
                                    class="btn btn-ghost"
                                    style="display:block;width:100%;text-align:left;padding:0.45rem 0.7rem;margin-bottom:0.15rem;border-radius:6px;{{ $key === $runKey ? 'background:var(--accent-soft,rgba(59,130,246,.12));' : '' }}">
                                <div class="text-sm">{{ $r['line_name'] }} · {{ \Illuminate\Support\Carbon::parse($r['date'])->format('d/m/Y') }} {{ $r['shift'] }}</div>
                            </button>
                        @empty
                            <div class="text-muted text-sm" style="padding:0.6rem;">No confirmed runs yet.</div>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>

        {{-- ---------------- The run being worked ---------------- --}}
        <div>
            @if (! $run)
                <div class="card card-pad text-muted">
                    Select a run on the left to record its waste.
                </div>
            @else
                <div class="card" style="margin-bottom:1rem;">
                    <div class="card-head" style="flex-wrap:wrap;gap:0.75rem;">
                        <div>
                            <h2 class="card-title">{{ $run['line_name'] ?: 'Line #' . $run['line_id'] }}</h2>
                            <div class="text-sm text-muted">
                                {{ \Modules\Bil\Support\ConversionWaste::productName($run['productid']) ?: 'Product #' . $run['productid'] }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2" style="margin-left:auto;flex-wrap:wrap;">
                            <span class="badge badge-muted">{{ \Illuminate\Support\Carbon::parse($run['date'])->format('d/m/Y') }}</span>
                            <span class="badge {{ $run['shift'] === 'night' ? 'badge-muted' : 'badge-success' }}">{{ ucfirst($run['shift']) }} shift</span>
                            <span class="badge badge-muted">{{ number_format($run['pallets']) }} pallets · {{ number_format($run['bundles']) }} bundles</span>
                            @if ($confirmed)
                                <span class="badge badge-success">Confirmed</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- The queue rule --}}
                @if ($blocker)
                    <div class="card" style="border-color:var(--danger);margin-bottom:1rem;padding:0.8rem 1.25rem;">
                        <div style="color:var(--danger);font-weight:600;">
                            {{ $blocked ? 'Blocked by an earlier run' : 'An earlier run is still open' }}
                        </div>
                        <div class="text-sm" style="margin-top:0.25rem;">{{ $this->blockMessage() }}</div>
                        @if (! $blocked)
                            <div class="text-sm text-muted" style="margin-top:0.25rem;">You may work out of order because you hold the waste bypass.</div>
                        @endif
                        <button type="button" class="btn btn-ghost btn-sm" style="margin-top:0.5rem;"
                                wire:click="selectRun('{{ \Modules\Bil\Support\ConversionWaste::keyOf($blocker) }}')">
                            Go to that run
                        </button>
                    </div>
                @endif

                {{-- Waste already recorded --}}
                @if ($entries->isNotEmpty())
                    <div class="card" style="margin-bottom:1rem;">
                        <div class="card-head">
                            <h2 class="card-title">Recorded so far</h2>
                            <span class="badge badge-muted" style="margin-left:auto;">{{ number_format($this->savedTotal(), 3) }} kg</span>
                        </div>
                        <div class="card-pad">
                            <table class="data" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th>Origin</th>
                                        <th>Classification</th>
                                        <th>Cause</th>
                                        <th style="width:130px;text-align:right;">Weight (kg)</th>
                                        <th style="width:150px;">By</th>
                                        <th style="width:60px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($entries as $e)
                                        <tr wire:key="entry-{{ $e->id }}">
                                            <td>{{ $e->origin?->label ?? '—' }}</td>
                                            <td>{{ $e->origin_ref ?: '—' }}</td>
                                            <td>{{ $e->cause?->name ?? '—' }}</td>
                                            <td style="text-align:right;">{{ number_format((float) $e->weight_kg, 3) }}</td>
                                            <td class="text-sm text-muted">{{ $e->username }}</td>
                                            <td style="text-align:center;">
                                                @unless ($confirmed)
                                                    <button type="button" class="btn btn-danger btn-icon btn-sm"
                                                            wire:click="deleteEntry({{ $e->id }})"
                                                            wire:confirm="Remove this waste entry?" title="Remove">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                                    </button>
                                                @endunless
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                {{-- Entry form --}}
                @if ($confirmed)
                    <div class="card card-pad">
                        <div class="text-muted">
                            Confirmed by <strong>{{ $stored->confirmed_by_name ?: 'unknown' }}</strong>
                            on {{ $stored->confirmed_at?->format('d/m/Y H:i') }}.
                            @if ($stored->is_nil) This was a nil return — no waste was recorded. @endif
                            @if ($stored->note) <div style="margin-top:0.3rem;">“{{ $stored->note }}”</div> @endif
                        </div>
                        @if ($this->canReopen())
                            <button type="button" class="btn btn-ghost btn-sm" style="margin-top:0.7rem;"
                                    wire:click="reopen" wire:confirm="Re-open this run so its waste can be corrected?">
                                Re-open run
                            </button>
                        @endif
                    </div>
                @else
                    <div class="card">
                        <div class="card-head">
                            <h2 class="card-title">Add waste</h2>
                            <div class="text-sm text-muted" style="margin-left:auto;">Origin decides what each row is classified against.</div>
                        </div>

                        <form wire:submit="save">
                            <div class="card-pad">
                                <table class="data" style="width:100%;">
                                    <thead>
                                        <tr>
                                            <th style="width:20%;">Origin</th>
                                            <th style="width:28%;">Classification</th>
                                            <th style="width:28%;">Cause</th>
                                            <th style="width:16%;">Weight (kg)</th>
                                            <th style="width:50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($rows as $i => $row)
                                            @php
                                                $opts = $this->optionsFor($row['origin_id'] ?? 0);
                                                $needsRef = $this->originNeedsRef($row['origin_id'] ?? 0);
                                            @endphp
                                            <tr wire:key="row-{{ $row['uid'] }}">
                                                <td>
                                                    <select class="form-control" wire:model.live="rows.{{ $i }}.origin_id" @disabled($blocked)>
                                                        <option value="">— Select —</option>
                                                        @foreach ($this->origins as $o)
                                                            <option value="{{ $o->id }}">{{ $o->label }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error("rows.{$i}.origin_id") <div class="form-error">{{ $message }}</div> @enderror
                                                </td>
                                                <td>
                                                    @if (! ($row['origin_id'] ?? ''))
                                                        <div class="text-sm text-muted" style="padding:0.5rem 0;">Pick an origin first</div>
                                                    @elseif (! $needsRef)
                                                        <div class="text-sm text-muted" style="padding:0.5rem 0;">Not classified</div>
                                                    @else
                                                        <select class="form-control" wire:model="rows.{{ $i }}.origin_ref" @disabled($blocked)>
                                                            <option value="">— Select —</option>
                                                            @foreach ($opts as $value => $label)
                                                                <option value="{{ $value }}">{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                        @error("rows.{$i}.origin_ref") <div class="form-error">{{ $message }}</div> @enderror
                                                    @endif
                                                </td>
                                                <td>
                                                    <select class="form-control" wire:model="rows.{{ $i }}.cause_id" @disabled($blocked)>
                                                        <option value="">— Select cause —</option>
                                                        @foreach ($this->causes as $c)
                                                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error("rows.{$i}.cause_id") <div class="form-error">{{ $message }}</div> @enderror
                                                </td>
                                                <td>
                                                    <input type="number" step="0.001" min="0" class="form-control"
                                                           placeholder="0.000" wire:model="rows.{{ $i }}.weight" @disabled($blocked)>
                                                    @error("rows.{$i}.weight") <div class="form-error">{{ $message }}</div> @enderror
                                                </td>
                                                <td style="text-align:center;">
                                                    <button type="button" class="btn btn-danger btn-icon btn-sm"
                                                            wire:click="removeRow({{ $i }})" @disabled($blocked) title="Remove row">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14"/></svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                                <button type="button" class="btn btn-ghost btn-sm" style="margin-top:0.6rem;"
                                        wire:click="addRow" @disabled($blocked)>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                                    Add row
                                </button>
                            </div>

                            <div class="card-pad" style="border-top:1px solid var(--border);display:flex;gap:0.6rem;flex-wrap:wrap;">
                                <button type="submit" class="btn btn-primary" @disabled($blocked)>Save waste</button>

                                @if ($this->canConfirm())
                                    <button type="button" class="btn btn-ghost" style="margin-left:auto;"
                                            wire:click="startConfirm" @disabled($blocked)>
                                        Confirm run
                                    </button>
                                @endif
                            </div>
                        </form>
                    </div>

                    {{-- Confirmation --}}
                    @if ($confirming)
                        <div class="card" style="margin-top:1rem;border-color:var(--accent,#3b82f6);">
                            <div class="card-pad">
                                <h2 class="card-title" style="margin-bottom:0.4rem;">Confirm this run?</h2>
                                @if ($entries->isEmpty())
                                    <p class="text-sm">There is <strong>no waste recorded</strong> against this run. Confirming records it as a deliberate nil return — that this shift was checked and produced none, rather than that nobody looked.</p>
                                @else
                                    <p class="text-sm">{{ $entries->count() }} {{ \Illuminate\Support\Str::plural('entry', $entries->count()) }} totalling <strong>{{ number_format($this->savedTotal(), 3) }} kg</strong>. Once confirmed, the run closes and Conversion Output can start the next run on this line.</p>
                                @endif

                                <div class="form-group" style="margin-top:0.6rem;">
                                    <label class="form-label">Note (optional)</label>
                                    <input type="text" class="form-control" wire:model="confirmNote" placeholder="Anything worth recording about this run">
                                </div>

                                <div class="flex gap-2">
                                    <button type="button" class="btn btn-primary" wire:click="confirm">Confirm run</button>
                                    <button type="button" class="btn btn-ghost" wire:click="cancelConfirm">Cancel</button>
                                </div>
                            </div>
                        </div>
                    @endif
                @endif
            @endif
        </div>
    </div>
</div>
