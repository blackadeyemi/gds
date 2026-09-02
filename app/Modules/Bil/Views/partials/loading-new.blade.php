{{--
    New Loading — the only one of the three legacy screens that CREATES
    anything, and the only one that starts from an order rather than a load.

    Quantities default to what is still outstanding on each line, so the common
    case (load the rest of the order) needs no typing. Loading more than is
    outstanding is refused: that is a slip, not a decision.
--}}
@php $order = $this->order; @endphp

<div class="card" style="margin-bottom:1rem;">
    <div class="card-head">
        <div>
            <h2 class="card-title">New Loading</h2>
            <div class="text-sm text-muted">Pick the order, say which truck, then enter what goes on.</div>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" style="margin-left:auto;" wire:click="cancelNew">Cancel</button>
    </div>

    <div class="card-pad">
        <div class="form-group">
            <label class="form-label">Sales order</label>
            @include('core::partials.searchable-select', [
                'field' => 'orderid',
                'options' => $this->orderOptions,
                'valueKey' => 'value', 'labelKey' => 'label',
                'placeholder' => '— Select order —',
                'live' => true,
            ])
            @error('orderid') <div class="form-error">{{ $message }}</div> @enderror
            @if ($order)
                <div class="text-muted text-sm" style="margin-top:.25rem;">
                    {{ $order->customername ?: 'No customer on this order' }}
                </div>
            @endif
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:0.75rem;">
            <div class="form-group">
                <label class="form-label">Transporter</label>
                @include('core::partials.searchable-select', [
                    'field' => 'transporterid',
                    'options' => $this->transporterOptions,
                    'valueKey' => 'value', 'labelKey' => 'label',
                    'placeholder' => '— Select —',
                ])
                @error('transporterid') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Cageroom</label>
                @include('core::partials.searchable-select', [
                    'field' => 'cageroomcode',
                    'options' => $this->cageroomOptions,
                    'valueKey' => 'value', 'labelKey' => 'label',
                    'placeholder' => '— Select —',
                ])
                @error('cageroomcode') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Truck number</label>
                <input type="text" class="form-control" list="trucklist" wire:model="trucknumber" placeholder="e.g. KJA479YE">
                @error('trucknumber') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Driver</label>
                <input type="text" class="form-control" list="driverlist" wire:model="truckdriver">
                @error('truckdriver') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Loader</label>
                <input type="text" class="form-control" wire:model="loader">
                @error('loader') <div class="form-error">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>
</div>

@if ($orderid !== '')
    <div class="card">
        <div class="card-head">
            <div>
                <h2 class="card-title">What goes on the truck</h2>
                <div class="text-sm text-muted">Defaults to everything still outstanding. Clear a line to leave it behind.</div>
            </div>
        </div>

        <div class="card-pad" style="overflow-x:auto;">
            <table class="data" style="width:100%;min-width:680px;">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="width:100px;text-align:right;">Ordered</th>
                        <th style="width:110px;text-align:right;">Already loaded</th>
                        <th style="width:110px;text-align:right;">Outstanding</th>
                        <th style="width:130px;">Load now</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->orderLines as $line)
                        <tr wire:key="ol-{{ $line->sod_id }}">
                            <td>
                                <div>{{ $line->productname }}</div>
                                <div class="text-sm text-muted">{{ $line->productcode }}@if ($line->foc) · <span class="badge" style="background:rgba(198,40,40,.14);color:#c62828;">FOC</span>@endif</div>
                            </td>
                            <td style="text-align:right;">{{ number_format($line->quantityordered) }}</td>
                            <td style="text-align:right;">{{ number_format($line->already) }}</td>
                            <td style="text-align:right;">
                                @if ($line->outstanding > 0)
                                    <strong>{{ number_format($line->outstanding) }}</strong>
                                @else
                                    <span class="badge badge-success">complete</span>
                                @endif
                            </td>
                            <td>
                                <input type="number" min="0" max="{{ $line->outstanding }}" step="1"
                                       class="form-control" style="text-align:right;"
                                       wire:model="toLoad.{{ $line->sod_id }}"
                                       @disabled($line->outstanding <= 0)>
                                @error('toLoad.' . $line->sod_id) <div class="form-error">{{ $message }}</div> @enderror
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-row text-muted">This order has no lines.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="flex" style="justify-content:flex-end;margin-top:1rem;">
                <button type="button" class="btn btn-primary" wire:click="saveNew">Create load</button>
            </div>
        </div>
    </div>
@endif
