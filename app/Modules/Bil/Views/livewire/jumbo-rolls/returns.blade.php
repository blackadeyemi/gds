<div>
    <div class="page-head">
        <h1>Returns</h1>
        <p>Send a jumbo roll back to BPL — a whole reel, or what was left of a part-used one.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    <div class="card card-pad">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;">
            <div class="form-group">
                <label class="form-label">User</label>
                <input type="text" class="form-control" value="{{ auth()->user()?->username }}" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">Returning to</label>
                <input type="text" class="form-control" value="BPL" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">Date of Return</label>
                @include('bil::partials.date-field', ['model' => 'dateIso', 'disabled' => ! $this->canBackdate()])
                @unless($this->canBackdate())
                    <div class="text-muted text-sm" style="margin-top:.25rem;">Shift date — needs the “backdate” permission to change.</div>
                @endunless
            </div>
            <div class="form-group" style="grid-column:span 2;">
                <label class="form-label">Reason <span class="text-muted text-sm">(optional)</span></label>
                <input type="text" class="form-control" wire:model="reason"
                       maxlength="{{ \Modules\Bil\Livewire\JumboRolls\Returns::REASON_MAX }}"
                       placeholder="e.g. wrong grade, damaged core, off-spec brightness" autocomplete="off">
                <div class="text-muted text-sm" style="margin-top:.25rem;">Applies to everything in this submit.</div>
            </div>
        </div>

        <form wire:submit.prevent="addScan" style="margin-top:0.5rem;">
            <div class="form-group" style="max-width:520px;">
                <label class="form-label">Barcode <span class="text-muted text-sm">({{ count($items) }}/{{ $this->maxScan() }})</span></label>
                <input type="text" class="form-control" wire:model="scan" wire:key="scan-input"
                       placeholder="Scan / enter reel or remainder barcode, then Enter" autocomplete="off" autofocus>
                @if ($scanError)
                    <div class="form-error">{{ $scanError }}</div>
                @endif
                <div class="text-muted text-sm" style="margin-top:.25rem;">
                    A whole reel goes back at its full weight; a logged remainder goes back at what is left of it.
                </div>
            </div>
        </form>

        <div class="table-wrap" style="margin-top:0.5rem;">
            <table class="data">
                <thead>
                    <tr>
                        <th style="width:60px">SN</th>
                        <th>Barcode</th>
                        <th>Product</th>
                        <th style="width:120px">Returning</th>
                        <th style="width:110px">Weight</th>
                        <th class="col-actions">Remove</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $i => $item)
                        <tr wire:key="return-row-{{ $item['barcode'] }}">
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $item['barcode'] }}</td>
                            <td>{{ $item['productname'] }}</td>
                            <td>
                                <span class="badge {{ $item['state'] === \Modules\Bil\Livewire\JumboRolls\Returns::WHOLE ? 'badge-success' : 'badge-warning' }}">
                                    {{ $item['state'] === \Modules\Bil\Livewire\JumboRolls\Returns::WHOLE ? 'Whole reel' : 'Remainder' }}
                                </span>
                            </td>
                            <td>{{ $item['weight'] }}</td>
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
                @if ($items)
                    <tfoot>
                        <tr>
                            <th colspan="4" style="text-align:right;">Total weight</th>
                            <th>{{ number_format($this->totalWeight(), 2) }}</th>
                            <th></th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div style="margin-top:1.25rem;max-width:520px;">
            <button type="button" class="btn btn-primary" style="width:100%;"
                    wire:click="save" @disabled(count($items) === 0)
                    wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Return to BPL ({{ count($items) }})</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </div>

    {{-- Shift window guard --}}
    @include('core::partials.shift-guard')
</div>
