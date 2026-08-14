<div>
    <div class="page-head">
        <h1>Consumption</h1>
        <p>Record jumbo rolls unwound on a converting machine during a shift.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    <div class="card card-pad">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.75rem;">
            <div class="form-group">
                <label class="form-label">User</label>
                <input type="text" class="form-control" value="{{ auth()->user()?->username }}" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">Shift</label>
                <select class="form-control" wire:model="shift">
                    <option value="day">Day Shift</option>
                    <option value="night">Night Shift</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Date</label>
                @include('bil::partials.date-field', ['model' => 'dateIso', 'disabled' => ! $this->canBackdate()])
                @unless($this->canBackdate())
                    <div class="text-muted text-sm" style="margin-top:.25rem;">Shift date — needs the “backdate” permission to change.</div>
                @endunless
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.75rem;margin-top:0.25rem;">
            <div class="form-group">
                <label class="form-label">Factory</label>
                <select class="form-control" wire:model.live="factoryId">
                    <option value="">Select factory</option>
                    @foreach ($this->factories as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Line</label>
                <select class="form-control" wire:model.live="lineId" @disabled(! $factoryId)>
                    <option value="">Select line</option>
                    @foreach ($this->lines as $l)
                        <option value="{{ $l->id }}">{{ $l->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Machine</label>
                <select class="form-control" wire:model.live="projectId" @disabled(! $lineId)>
                    <option value="">Select machine</option>
                    @foreach ($this->machines as $m)
                        <option value="{{ $m->id }}">{{ $m->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Product On Line</label>
                <input type="text" class="form-control" value="{{ $this->productOnLine() ?: '—' }}" disabled>
            </div>
        </div>

        <form wire:submit.prevent="addScan" style="margin-top:0.5rem;">
            <div class="form-group" style="max-width:520px;">
                <label class="form-label">Scan Barcode <span class="text-muted text-sm">({{ count($items) }}/{{ $this->maxScan() }})</span></label>
                <input type="text" class="form-control" wire:model="scan" wire:key="scan-input"
                       placeholder="{{ $this->placed() ? 'Scan or type a barcode, then Enter' : 'Select factory, line and machine first' }}"
                       autocomplete="off" @disabled(! $this->placed()) autofocus>
                @if ($scanError)
                    <div class="form-error">{{ $scanError }}</div>
                @endif
                <div class="text-muted text-sm" style="margin-top:.25rem;">
                    A sliced reel is scanned one slice at a time — the slice number is the last part of the barcode.
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
                        <th style="width:80px">Status</th>
                        <th style="width:110px">Weight</th>
                        <th class="col-actions">Remove</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $i => $item)
                        <tr wire:key="scan-row-{{ $item['barcode'] }}">
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $item['barcode'] }}</td>
                            <td>{{ $item['productname'] }}</td>
                            <td><span class="badge badge-success">OK</span></td>
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
                <span wire:loading.remove wire:target="save">Enter ({{ count($items) }})</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </div>

    {{-- Shift window guard: closed outside the Consumption shift --}}
    @include('core::partials.shift-guard')
</div>
