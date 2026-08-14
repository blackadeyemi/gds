<div>
    <div class="page-head">
        <h1>Factory Entrance</h1>
        <p>Scan jumbo rolls arriving at a factory gate onto the factory floor.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    @if ($this->gates->isEmpty())
        <div class="card card-pad" style="border-color:var(--warning,#b45309);margin-bottom:1rem;">
            <strong>No factory gates are assigned to you.</strong>
            <p class="text-muted text-sm" style="margin:.35rem 0 0;">
                An administrator grants these per user under Admin &rarr; Users, and each gate must be an
                inbound gate on a {{ config('bil.company_code') }} factory.
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
                'label' => 'Entrance Location',
                'gates' => $this->gates,
                'field' => 'gateId',
                'group' => fn ($g) => $g->factory?->name ?? 'Unassigned',
                'empty' => 'No factory gates assigned',
            ])
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
                <label class="form-label">Barcode <span class="text-muted text-sm">({{ count($items) }}/{{ $this->maxScan() }})</span></label>
                <input type="text" class="form-control" wire:model="scan" wire:key="scan-input"
                       placeholder="Scan / enter reel barcode here, then Enter" autocomplete="off" autofocus>
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
                        <th>Hardroll No.</th>
                        <th>Product Name</th>
                        <th style="width:110px">Weight</th>
                        <th class="col-actions">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $i => $item)
                        <tr wire:key="scan-row-{{ $item['barcode'] }}">
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $item['barcode'] }}</td>
                            <td>{{ $item['hardrollnumber'] }}</td>
                            <td>{{ $item['productname'] }}</td>
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
                <span wire:loading.remove wire:target="save">Save ({{ count($items) }})</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </div>

    {{-- Shift window guard: closed outside the Factory Entrance shift --}}
    @include('core::partials.shift-guard')
</div>
