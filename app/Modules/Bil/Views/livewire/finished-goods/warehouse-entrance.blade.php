<div>
    <div class="page-head">
        <h1>Warehouse Entrance</h1>
        <p>Scan pallets as the warehouse receives them in from the factory.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    @if ($this->entrances()->isEmpty())
        <div class="card card-pad" style="border-color:var(--warning,#b45309);margin-bottom:1rem;">
            <strong>No warehouse entrances are assigned to you.</strong>
            <p class="text-muted text-sm" style="margin:.35rem 0 0;">
                An administrator grants these per user under Admin → Users. An entrance must also belong to a
                warehouse before it can receive — see Finished Goods → Setup → Warehouse Entrances.
            </p>
        </div>
    @endif

    <div class="card card-pad">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;max-width:720px;">
            <div class="form-group">
                <label class="form-label">User</label>
                <input type="text" class="form-control" value="{{ auth()->user()?->username }}" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">Entrance</label>
                @if ($this->entrances()->isEmpty())
                    <input type="text" class="form-control" value="No entrances assigned" disabled>
                @else
                    <select class="form-control" wire:model="entrance_id">
                        @foreach ($this->entrances()->groupBy(fn ($e) => $e->warehouse?->name ?? 'Unassigned') as $warehouse => $group)
                            <optgroup label="{{ $warehouse }}">
                                @foreach ($group as $entrance)
                                    <option value="{{ $entrance->id }}">{{ $entrance->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                @endif
                @error('entrance_id') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Date</label>
                @include('bil::partials.date-field', ['model' => 'dateIso', 'disabled' => ! $this->canBackdate()])
                <div class="text-muted text-sm" style="margin-top:.25rem;">
                    Receipts are dated by the pallet's factory exit — this is only a fallback.
                    @unless ($this->canBackdate())
                        Needs the “backdate” permission to change.
                    @endunless
                </div>
            </div>
        </div>

        <form wire:submit.prevent="addScan" style="margin-top:0.5rem;">
            <div class="form-group" style="max-width:520px;">
                <label class="form-label">Scan Barcode <span class="text-muted text-sm">({{ count($items) }}/{{ $this->maxScan() }})</span></label>
                <input type="text" class="form-control" wire:model="scan" wire:key="scan-input"
                       placeholder="Scan or type a pallet barcode, then Enter" autocomplete="off" autofocus>
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
                        <th style="width:80px">Status</th>
                        <th>Product</th>
                        <th style="width:120px">Exited</th>
                        <th style="width:110px">Bundles</th>
                        <th class="col-actions">Remove</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $i => $item)
                        <tr wire:key="scan-row-{{ $item['barcode'] }}">
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $item['barcode'] }}</td>
                            <td><span class="badge badge-success">OK</span></td>
                            <td>{{ $item['productname'] }}</td>
                            <td class="text-muted text-sm">{{ $item['exitDate'] }}</td>
                            <td>{{ $item['bundles'] }}</td>
                            <td class="col-actions">
                                <button type="button" class="btn btn-danger btn-icon btn-sm" wire:click="removeItem({{ $i }})" title="Remove">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-row">No barcodes scanned yet.</td></tr>
                    @endforelse
                </tbody>
                @if ($items !== [])
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align:right;font-weight:600;">Total Bundles</td>
                            <td style="font-weight:600;">{{ $this->totalBundles() }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div style="margin-top:1.25rem;max-width:520px;">
            <button type="button" class="btn btn-primary" style="width:100%;"
                    wire:click="save" @disabled(count($items) === 0)
                    wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save ({{ count($items) }})</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </div>
</div>
