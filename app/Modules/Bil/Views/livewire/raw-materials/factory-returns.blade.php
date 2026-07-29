<div>
    <div class="page-head">
        <h1>Factory Returns</h1>
        <p>Return unused raw material from the factory back to the store, then approve it.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    {{-- Stage 1 — entry --}}
    <div class="card card-pad">
        <h3 style="margin-top:0;">Return Entry</h3>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;">
            <div class="form-group">
                <label class="form-label">User</label>
                <input type="text" class="form-control" value="{{ auth()->user()?->username }}" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">Return Type</label>
                <select class="form-control" wire:model.live="returnType">
                    <option value="Non-Consumed">Non-Consumed (whole item)</option>
                    <option value="Partially Consumed">Partially Consumed (leftover)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Date</label>
                @include('bil::partials.date-field', ['model' => 'dateIso', 'disabled' => ! auth()->user()?->can('backdate')])
                @cannot('backdate')
                    <div class="text-muted text-sm" style="margin-top:.25rem;">Shift date — needs the “backdate” permission to change.</div>
                @endcannot
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
                        <th style="width:120px">Original (kg)</th>
                        <th style="width:150px">Returned (kg)</th>
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
                            <td>
                                @if ($returnType === \Modules\Bil\Livewire\RawMaterials\FactoryReturns::TYPE_PARTIAL)
                                    <input type="number" step="any" min="0" class="form-control" style="max-width:120px;"
                                           wire:model="items.{{ $i }}.returnWeight" wire:key="rw-{{ $item['barcode'] }}"
                                           placeholder="Leftover">
                                @else
                                    {{ $item['weight'] }}
                                @endif
                            </td>
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
    @can('approve-raw-materials')
        <div class="card card-pad" style="margin-top:1.25rem;">
            <h3 style="margin-top:0;">Awaiting Approval <span class="text-muted text-sm">({{ $this->pendingReturns->count() }})</span></h3>

            @if ($printBarcode)
                <div class="flex items-center gap-2" style="margin-bottom:0.9rem;">
                    <a href="{{ route('bil.raw-materials.factory-returns.print', ['barcode' => $printBarcode]) }}"
                       target="_blank" class="btn btn-ghost btn-sm" style="border:1px solid var(--line);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                        Print label for {{ $printBarcode }}
                    </a>
                    <button type="button" class="btn btn-ghost btn-icon btn-sm" wire:click="$set('printBarcode', '')" title="Dismiss">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
            @endif

            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width:50px">SN</th>
                            <th>Barcode</th>
                            <th>Product</th>
                            <th>Type</th>
                            <th style="width:110px">Weight (kg)</th>
                            <th>By</th>
                            <th style="width:190px" class="col-actions">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->pendingReturns as $j => $req)
                            <tr wire:key="pending-{{ $req->id }}">
                                <td>{{ $j + 1 }}</td>
                                <td>{{ $req->barcode }}</td>
                                <td>{{ $req->product }}</td>
                                <td><span class="badge">{{ $req->type }}</span></td>
                                <td>{{ $req->weight }}</td>
                                <td>{{ $req->user }}</td>
                                <td class="col-actions">
                                    <button type="button" class="btn btn-primary btn-sm"
                                            wire:click="approve({{ $req->id }})"
                                            wire:loading.attr="disabled" wire:target="approve({{ $req->id }})">Approve</button>
                                    <button type="button" class="btn btn-danger btn-sm"
                                            wire:click="reject({{ $req->id }})"
                                            wire:confirm="Reject this return?"
                                            wire:loading.attr="disabled" wire:target="reject({{ $req->id }})">Reject</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="empty-row">Nothing awaiting approval.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endcan
</div>
