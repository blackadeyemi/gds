<div>
    <div class="page-head">
        <h1>Warehouse Entry</h1>
        <p>Receive hardrolls and softrolls into a BPL store. The barcode decides which it is.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    @if ($this->gates->isEmpty())
        <div class="card card-pad" style="border-color:var(--warning,#b45309);margin-bottom:1rem;">
            <strong>No jumbo roll stores are assigned to you.</strong>
            <p class="text-muted text-sm" style="margin:.35rem 0 0;">
                An administrator grants these per user under Admin &rarr; Users. They are the inbound
                gates on the PM2, PM3 and Waste Paper stores.
            </p>
        </div>
    @endif

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
                <label class="form-label">Barcode <span class="text-muted text-sm">({{ count($items) }}/{{ $this->maxScan() }})</span></label>
                <input type="text" class="form-control" wire:model="scan" wire:key="scan-input"
                       placeholder="Scan / enter roll barcode here, then Enter" autocomplete="off" autofocus
                       @disabled($ambiguous !== [])>
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
                            <td>{{ $item['barcode'] }}</td>
                            <td>{{ $item['rollnumber'] }}</td>
                            <td>{{ $item['productname'] }}</td>
                            <td>{{ $item['weight'] }}</td>
                            <td class="col-actions">
                                <button type="button" class="btn btn-danger btn-icon btn-sm" wire:click="removeItem({{ $i }})" title="Remove">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
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
                <span wire:loading.remove wire:target="save">Save ({{ count($items) }})</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>

        <p class="text-muted text-sm" style="margin:1rem 0 0;">
            Receiving adds each roll's weight to the store's stock. A roll can only be received once,
            and only after it has been booked out of the factory.
        </p>
    </div>

    {{-- The barcode is awaiting a store in BOTH streams. Nothing in the code can
         choose, so the two go side by side — the weights make it obvious. --}}
    @if ($ambiguous !== [])
        <div class="modal-backdrop" x-data style="display:flex;">
            <div class="modal-card" style="max-width:640px;">
                <div class="modal-head">
                    <h3 class="modal-title">Which roll is this?</h3>
                </div>
                <div class="modal-body">
                    <p class="text-muted text-sm" style="margin-top:0;">
                        Barcode <strong>{{ $ambiguous[0]['barcode'] }}</strong> belongs to a hardroll and a softroll,
                        both out of the factory and neither yet in a store — it was printed before softrolls
                        carried their own <strong>S</strong> prefix. Pick the one in front of you.
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
