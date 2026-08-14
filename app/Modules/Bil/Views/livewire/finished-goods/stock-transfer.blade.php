{{--
    Stock Transfer — send a truckload from one warehouse to another.

    The destination list is grouped by company, and that grouping IS the
    internal / inter-company distinction. Nothing asks the operator to classify
    the transfer; the badge below reports what their choice already decided.
--}}
@php
    $from = $this->fromWarehouse;
    $to = $this->toWarehouse;
@endphp

<div>
    <div class="page-head">
        <h1>Stock Transfer</h1>
        <p>Send finished goods from one warehouse to another. Bundles leave the source now and arrive when the destination receives them.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    <form wire:submit="save">
        <div class="card" style="padding:1.4rem;margin-bottom:1rem;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">From warehouse</label>
                    @include('core::partials.searchable-select', [
                        'field' => 'from_warehouse_id',
                        'options' => $this->sources,
                        'placeholder' => '— Select —',
                        'live' => true,
                    ])
                    @error('from_warehouse_id') <div class="form-error">{{ $message }}</div> @enderror
                    @if ($from?->company)
                        <div class="text-muted text-sm" style="margin-top:.25rem;">{{ $from->company->name }}</div>
                    @endif
                </div>

                <div class="form-group">
                    <label class="form-label">To warehouse</label>
                    @include('core::partials.searchable-select', [
                        'field' => 'to_warehouse_id',
                        'options' => $this->destinationOptions,
                        'valueKey' => 'value',
                        'labelKey' => 'label',
                        'placeholder' => '— Select destination —',
                        'live' => true,
                        'key' => 'dest-' . ($from_warehouse_id ?? 'none'),
                    ])
                    @error('to_warehouse_id') <div class="form-error">{{ $message }}</div> @enderror
                    @if ($to)
                        {{-- Derived, not chosen. --}}
                        <div style="margin-top:.35rem;">
                            @if ($interCompany)
                                <span class="badge" style="background:rgba(217,119,6,.14);color:#b45309;">Inter-company</span>
                                <span class="text-muted text-sm">{{ $from?->company?->name }} → {{ $to->company?->name }}</span>
                            @else
                                <span class="badge badge-success">Internal</span>
                                <span class="text-muted text-sm">within {{ $to->company?->name }}</span>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="form-group">
                    <label class="form-label">Transfer number</label>
                    <input type="text" class="form-control" wire:model="transfer_number">
                    @error('transfer_number') <div class="form-error">{{ $message }}</div> @enderror
                </div>

                <div class="form-group">
                    <label class="form-label">Truck number</label>
                    <input type="text" class="form-control" wire:model="truck_number" placeholder="e.g. kef342xb">
                    @error('truck_number') <div class="form-error">{{ $message }}</div> @enderror
                </div>

                <div class="form-group">
                    <label class="form-label">
                        Date @unless ($this->canBackdate()) <span class="text-muted">(today)</span> @endunless
                    </label>
                    @include('bil::partials.date-field', [
                        'model' => 'dateIso',
                        'live' => false,
                        'disabled' => ! $this->canBackdate(),
                    ])
                    @error('dateIso') <div class="form-error">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        {{-- ---------------- Product lines ---------------- --}}
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-head">
                <div>
                    <h2 class="card-title">What is on the truck</h2>
                    <div class="text-sm text-muted">Bundles per product. Available is what the source holds now.</div>
                </div>
            </div>

            <div class="card-pad" style="overflow-x:auto;">
                <table class="data" style="width:100%;min-width:640px;">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="width:140px;">Bundles</th>
                            <th style="width:140px;">Available</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $i => $row)
                            @php
                                $avail = $this->availableFor($row['productid'] ?? 0);
                                $short = $avail !== null && (int) ($row['bundles'] ?? 0) > $avail;
                            @endphp
                            <tr wire:key="row-{{ $row['uid'] }}">
                                <td>
                                    @include('core::partials.searchable-select', [
                                        'field' => "rows.{$i}.productid",
                                        'options' => $this->productOptions,
                                        'valueKey' => 'value',
                                        'labelKey' => 'label',
                                        'placeholder' => '— Select product —',
                                        'live' => true,
                                        'key' => 'prod-' . $row['uid'],
                                    ])
                                    @error("rows.{$i}.productid") <div class="form-error">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="number" min="1" step="1" class="form-control"
                                           style="text-align:right;" wire:model.live.debounce.400ms="rows.{{ $i }}.bundles">
                                    @error("rows.{$i}.bundles") <div class="form-error">{{ $message }}</div> @enderror
                                </td>
                                <td style="text-align:right;">
                                    @if ($avail === null)
                                        <span class="text-muted">—</span>
                                    @else
                                        <span class="{{ $short ? 'form-error' : 'text-muted' }}">{{ number_format($avail) }}</span>
                                    @endif
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="btn btn-danger btn-icon btn-sm" wire:click="removeRow({{ $i }})" title="Remove line">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @error('rows') <div class="form-error" style="margin-top:.5rem;">{{ $message }}</div> @enderror

                <button type="button" class="btn btn-ghost btn-sm" style="margin-top:0.6rem;" wire:click="addRow">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                    Add product
                </button>
            </div>
        </div>

        <div class="card" style="padding:1rem 1.4rem;">
            <div class="form-group" style="margin-bottom:1rem;">
                <label class="form-label">Note <span class="text-muted">(optional)</span></label>
                <textarea class="form-control" rows="2" wire:model="note"></textarea>
            </div>

            <div class="flex" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;">
                <div class="text-sm text-muted">
                    Bundles leave <strong>{{ $from?->name ?? 'the source' }}</strong> as soon as this is saved, and are
                    <strong>in transit</strong> until {{ $to?->name ?? 'the destination' }} receives them.
                </div>
                <button type="submit" class="btn btn-primary">Dispatch transfer</button>
            </div>
        </div>
    </form>
</div>
