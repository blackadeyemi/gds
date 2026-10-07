@php
    $releasing = $mode === 'out';
    $held = $mode === 'held' ? $this->held : null;
@endphp
<div>
    <div class="page-head">
        <h1>Waiting Area</h1>
        <p>Rolls that have left a store and are standing at one until they go out again. Holding puts the weight back on that store's stock.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    @if ($this->gates->isEmpty())
        <div class="card card-pad" style="border-color:var(--warning,#b45309);margin-bottom:1rem;">
            <strong>No jumbo roll stores are assigned to you on this side.</strong>
            <p class="text-muted text-sm" style="margin:.35rem 0 0;">
                Holding needs an inbound gate on a store, releasing an outbound one — the same grants the
                Warehouse Entry and Warehouse Exit screens use. An administrator grants them per user
                under Admin &rarr; Users.
            </p>
        </div>
    @endif

    {{-- Which side of the fence: in, out, or what is standing there now --}}
    <div class="card card-pad" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
        @foreach (['in' => 'Hold rolls', 'out' => 'Release rolls', 'held' => 'In the waiting area'] as $key => $label)
            <button type="button"
                    class="btn {{ $mode === $key ? 'btn-primary' : 'btn-ghost' }} btn-sm"
                    wire:click="switchMode('{{ $key }}')">{{ $label }}</button>
        @endforeach

        @if ($mode !== 'held')
            <span class="text-muted text-sm" style="margin-left:auto;">
                {{ $releasing ? 'Releasing takes the weight back off the store stock.' : 'Only a roll released at Warehouse Exit can be held here; its weight goes back on the store.' }}
            </span>
        @endif
    </div>

    @if ($mode === 'held')
        {{-- ---------------- What is standing there ---------------- --}}
        <div class="card">
            <div class="card-head">
                <h2 class="card-title">In the waiting area</h2>
                <div class="flex items-center gap-2" style="margin-left:auto;">
                    <input type="text" class="form-control" style="width:240px;"
                           wire:model.live.debounce.300ms="heldSearch" placeholder="Barcode…">
                </div>
            </div>
            <div class="card-pad">
                <div class="table-wrap">
                    <table class="data" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:60px;">SN</th>
                                <th style="width:110px;">Type</th>
                                <th>Barcode</th>
                                <th>Roll No.</th>
                                <th>Product</th>
                                <th style="width:130px;">Store</th>
                                <th style="width:110px;">Held since</th>
                                <th style="width:90px;text-align:right;">Days</th>
                                <th style="width:110px;text-align:right;">Weight</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($held as $i => $row)
                                <tr wire:key="held-{{ $row->id }}">
                                    <td>{{ $i + 1 }}</td>
                                    <td>
                                        <span class="badge {{ $row->stream === 'softroll' ? 'badge-muted' : 'badge-success' }}">
                                            {{ $row->stream }}
                                        </span>
                                    </td>
                                    <td class="mono">{{ $row->barcode }}</td>
                                    <td>{{ $row->rollnumber ?: '—' }}</td>
                                    <td>{{ $row->product }}</td>
                                    <td>{{ $row->store }}</td>
                                    <td>{{ $row->date }}</td>
                                    <td style="text-align:right;">
                                        @if ($row->days === null)
                                            —
                                        @else
                                            <span class="badge {{ $row->days > 14 ? 'badge-warning' : 'badge-muted' }}">
                                                {{ number_format($row->days) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td style="text-align:right;">{{ number_format($row->weight, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="empty-row">Nothing is standing in a waiting area.</td></tr>
                            @endforelse
                        </tbody>
                        @if ($held->isNotEmpty())
                            <tfoot>
                                <tr>
                                    <th colspan="8" style="text-align:right;">
                                        {{ number_format($held->count()) }} {{ \Illuminate\Support\Str::plural('roll', $held->count()) }}
                                    </th>
                                    <th style="text-align:right;">{{ number_format($this->heldWeight(), 2) }}</th>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
                <p class="text-muted text-sm" style="margin:.6rem 0 0;">
                    Showing at most 500. Its weight is on that store's stock while it stands here, and comes
                    off again when it is released.
                </p>
            </div>
        </div>
    @else
        {{-- ---------------- Holding / releasing ---------------- --}}
        <div class="card card-pad">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">User</label>
                    <input type="text" class="form-control" value="{{ auth()->user()?->username }}" disabled>
                </div>

                @include('bil::partials.gate-select', [
                    'label' => 'Store',
                    'gates' => $this->gates,
                    'field' => 'gateId',
                    'group' => fn ($g) => $g->warehouse?->name ?? 'Unassigned',
                    'empty' => 'No stores assigned',
                ])

                <div class="form-group">
                    <label class="form-label">Date</label>
                    @include('bil::partials.date-field', ['model' => 'dateIso', 'disabled' => ! $this->canBackdate()])
                    @unless($this->canBackdate())
                        <div class="text-muted text-sm" style="margin-top:.25rem;">Today — needs the “backdate” permission to change.</div>
                    @endunless
                </div>
            </div>

            <form wire:submit.prevent="addScan" style="margin-top:0.5rem;">
                <div class="form-group" style="max-width:520px;">
                    <label class="form-label">
                        Barcode <span class="text-muted text-sm">({{ count($items) }}/{{ $this->maxScan() }})</span>
                    </label>
                    <input type="text" class="form-control" wire:model="scan" wire:key="scan-input"
                           placeholder="{{ $releasing ? 'Scan a roll to release…' : 'Scan a roll to hold…' }}"
                           autocomplete="off" autofocus @disabled($ambiguous !== [])>
                    @if ($scanError)
                        <div class="form-error">{{ $scanError }}</div>
                    @endif
                </div>
            </form>

            <div class="table-wrap" style="margin-top:0.5rem;">
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width:60px">SN</th>
                            <th style="width:110px">Type</th>
                            <th>Barcode</th>
                            <th>Roll No.</th>
                            <th>Product</th>
                            <th style="width:110px">Weight</th>
                            <th class="col-actions">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $i => $item)
                            <tr wire:key="scan-row-{{ $item['stream'] }}-{{ $item['barcode'] }}">
                                <td>{{ $i + 1 }}</td>
                                <td>
                                    <span class="badge {{ $item['stream'] === 'softroll' ? 'badge-muted' : 'badge-success' }}">
                                        {{ $item['stream'] }}
                                    </span>
                                </td>
                                <td class="mono">{{ $item['barcode'] }}</td>
                                <td>{{ $item['rollnumber'] }}</td>
                                <td>{{ $item['productname'] }}</td>
                                <td>{{ number_format((float) $item['weight'], 2) }}</td>
                                <td class="col-actions">
                                    <button type="button" class="btn btn-danger btn-icon btn-sm" wire:click="removeItem({{ $i }})" title="Remove">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="empty-row">No barcodes scanned yet.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($items)
                        <tfoot>
                            <tr>
                                <th colspan="5" style="text-align:right;">Total weight</th>
                                <th>{{ number_format($this->totalWeight(), 2) }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            <div style="margin-top:1.25rem;max-width:520px;">
                <button type="button" class="btn btn-primary" style="width:100%;"
                        wire:click="save" @disabled(count($items) === 0 || $ambiguous !== [])
                        wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">
                        {{ $releasing ? 'Release' : 'Hold' }} ({{ count($items) }})
                    </span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </div>
    @endif

    {{-- The same barcode in both streams: the weights tell them apart. --}}
    @if ($ambiguous !== [])
        <div class="modal-backdrop" x-data style="display:flex;">
            <div class="modal-card" style="max-width:640px;">
                <div class="modal-head">
                    <h3 class="modal-title">Which roll is this?</h3>
                </div>
                <div class="modal-body">
                    <p class="text-muted text-sm" style="margin-top:0;">
                        Barcode <strong>{{ $ambiguous[0]['barcode'] }}</strong> reads as a hardroll and as a
                        softroll — it was printed before softrolls carried their own <strong>S</strong> prefix.
                        Pick the one in front of you.
                    </p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                        @foreach ($ambiguous as $c)
                            <button type="button" class="card card-pad" style="text-align:left;cursor:pointer;border-width:2px;"
                                    wire:click="chooseStream('{{ $c['stream'] }}')">
                                <div class="badge {{ $c['stream'] === 'softroll' ? 'badge-muted' : 'badge-success' }}">{{ $c['stream'] }}</div>
                                <div style="font-size:1.6rem;font-weight:700;margin:0.4rem 0 0;">{{ $c['weight'] }} kg</div>
                                <div class="text-sm">{{ $c['productname'] }}</div>
                                <div class="text-muted text-sm">{{ $c['rollnumber'] }}</div>
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" wire:click="cancelAmbiguous">Cancel</button>
                </div>
            </div>
        </div>
    @endif
</div>
