<div>
    <div class="page-head">
        <h1>Damaged Goods</h1>
        <p>Report in-store raw material as damaged, then approve to write it off.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    {{-- Stage 1 — entry --}}
    <div class="card card-pad">
        <h3 style="margin-top:0;">Damage Entry</h3>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;">
            <div class="form-group">
                <label class="form-label">User</label>
                <input type="text" class="form-control" value="{{ auth()->user()?->username }}" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">Date</label>
                @include('bil::partials.date-field', ['model' => 'dateIso', 'disabled' => ! $this->canBackdate()])
                @unless($this->canBackdate())
                    <div class="text-muted text-sm" style="margin-top:.25rem;">Shift date — needs the “backdate” permission to change.</div>
                @endunless
            </div>
        </div>

        <form wire:submit.prevent="addScan" style="margin-top:0.5rem;">
            <div class="form-group" style="max-width:520px;">
                <label class="form-label">Scan Barcode <span class="text-muted text-sm">({{ count($items) }}/{{ $this->maxScan() }})</span></label>
                <input type="text" class="form-control" wire:model="scan" wire:key="scan-input"
                       placeholder="Scan or type a barcode, then Enter" autocomplete="off" autofocus>
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
                        <th>Barcode</th>
                        <th>Product</th>
                        <th style="width:120px">Weight (kg)</th>
                        <th class="col-actions">Remove</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $i => $item)
                        <tr wire:key="scan-row-{{ $item['barcode'] }}">
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $item['barcode'] }}</td>
                            <td>{{ $item['productname'] }}</td>
                            <td>{{ $item['weight'] }}</td>
                            <td class="col-actions">
                                <button type="button" class="btn btn-danger btn-icon btn-sm" wire:click="removeItem({{ $i }})" title="Remove">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-row">No barcodes scanned yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top:1.25rem;max-width:520px;">
            <button type="button" class="btn btn-primary" style="width:100%;"
                    wire:click="save" @disabled(count($items) === 0)
                    wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Submit for Approval ({{ count($items) }})</span>
                <span wire:loading wire:target="save">Submitting…</span>
            </button>
        </div>
    </div>

    {{-- Stage 2 — approval --}}
    @if ($this->canApprove())
        <div class="card card-pad" style="margin-top:1.25rem;">
            <h3 style="margin-top:0;">Awaiting Approval <span class="text-muted text-sm">({{ $this->pendingDamaged->count() }})</span></h3>

            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width:50px">SN</th>
                            <th>Barcode</th>
                            <th style="width:110px">Weight (kg)</th>
                            <th>By</th>
                            <th>Date</th>
                            <th style="width:190px" class="col-actions">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->pendingDamaged as $j => $req)
                            <tr wire:key="pending-{{ $req->id }}">
                                <td>{{ $j + 1 }}</td>
                                <td>{{ $req->barcode }}</td>
                                <td>{{ $req->weight }}</td>
                                <td>{{ $req->user_name }}</td>
                                <td>{{ $req->entrance_date }}</td>
                                <td class="col-actions">
                                    <button type="button" class="btn btn-primary btn-sm"
                                            wire:click="approve({{ $req->id }})"
                                            wire:loading.attr="disabled" wire:target="approve({{ $req->id }})">Approve</button>
                                    <button type="button" class="btn btn-danger btn-sm"
                                            wire:click="reject({{ $req->id }})"
                                            wire:confirm="Reject this damaged-goods report?"
                                            wire:loading.attr="disabled" wire:target="reject({{ $req->id }})">Reject</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="empty-row">Nothing awaiting approval.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
