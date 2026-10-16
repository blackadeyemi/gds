@php $internal = $this->isInternal(); @endphp
<div>
    <div class="page-head">
        <h1>Delivery</h1>
        <p>Load a truck from the loading bay and print its waybill. Rolls released at Warehouse Exit are what can go on.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">
            {{ session('ok') }}
            @if ($lastWaybill)
                <a href="{{ route('bpl.logistics.delivery.waybill', $lastWaybill) }}" target="_blank" rel="noopener"
                   class="btn btn-ghost btn-sm" style="margin-left:.75rem;">Print waybill</a>
                <a href="{{ route('bpl.logistics.delivery.processor-slip', $lastWaybill) }}" target="_blank" rel="noopener"
                   class="btn btn-ghost btn-sm" style="margin-left:.4rem;">Processor slip</a>
            @endif
        </div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Deliveries already made                                             --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($mode === 'list')
        <div class="card">
            <div class="card-head">
                <h2 class="card-title">Deliveries</h2>
                <button type="button" class="btn btn-ghost btn-sm" style="margin-left:auto;" wire:click="showForm">
                    &larr; Back to form
                </button>
            </div>
            <div class="card-pad">
                <div class="flex items-end gap-2" style="flex-wrap:wrap;margin-bottom:1rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Date</label>
                        @include('bil::partials.date-field', ['model' => 'listDateIso', 'live' => true, 'compact' => true])
                    </div>
                    <div class="form-group" style="margin-bottom:0;flex:1 1 220px;">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" wire:model.live.debounce.300ms="listSearch"
                               placeholder="Waybill, customer or truck…">
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:170px;">Waybill</th>
                                <th style="width:100px;">Date</th>
                                <th>Customer</th>
                                <th style="width:130px;">Truck</th>
                                <th style="width:70px;text-align:right;">Rolls</th>
                                <th style="width:120px;text-align:right;">Weight (kg)</th>
                                <th style="width:110px;">Status</th>
                                <th style="width:92px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->deliveries as $d)
                                <tr wire:key="wb-{{ $d->id }}">
                                    <td class="mono">{{ $d->delivery_barcode }}</td>
                                    <td>{{ $d->date }}</td>
                                    <td>{{ $d->customer_name ?: '—' }}</td>
                                    <td>{{ $d->vechile_number ?: '—' }}</td>
                                    <td style="text-align:right;">{{ number_format((int) $d->roll_count) }}</td>
                                    <td style="text-align:right;">{{ number_format((float) $d->total_weight, 2) }}</td>
                                    <td>
                                        <span class="badge {{ $d->status === 'completed' ? 'badge-success' : 'badge-muted' }}">
                                            {{ $d->status ?: '—' }}
                                        </span>
                                    </td>
                                    <td style="text-align:right;">
                                        <a href="{{ route('bpl.logistics.delivery.waybill', $d->delivery_barcode) }}"
                                           target="_blank" rel="noopener" class="btn btn-ghost btn-icon btn-sm" title="Print waybill">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                                        </a>
                                        <a href="{{ route('bpl.logistics.delivery.processor-slip', $d->delivery_barcode) }}"
                                           target="_blank" rel="noopener" class="btn btn-ghost btn-icon btn-sm"
                                           title="Processor slip — every roll on its own line">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8M8 9h2"/></svg>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="empty-row">No deliveries match.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="text-muted text-sm" style="margin-top:.6rem;">
                    Showing the 200 most recent. “pending” means the loaded truck has not been weighed yet.
                </div>
            </div>
        </div>
    @else

    {{-- ------------------------------------------------------------------ --}}
    {{-- Loading a truck                                                     --}}
    {{-- ------------------------------------------------------------------ --}}
        <div class="card">
            <div class="card-head">
                <h2 class="card-title">New delivery</h2>
                <button type="button" class="btn btn-ghost btn-sm" style="margin-left:auto;" wire:click="showList">Deliveries</button>
            </div>

            <div class="card-pad">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label">Date</label>
                        @include('bil::partials.date-field', ['model' => 'dateIso', 'disabled' => ! $this->canBackdate()])
                        @error('dateIso') <div class="form-error">{{ $message }}</div> @enderror
                        @unless($this->canBackdate())
                            <div class="text-muted text-sm" style="margin-top:.25rem;">Today — needs the “backdate” permission to change.</div>
                        @endunless
                    </div>

                    <div style="grid-column:span 2;">
                        {{-- Live: the customer decides whether the truck details apply at all. --}}
                        @include('bil::partials.combobox', [
                            'model' => 'customerName',
                            'labelText' => 'Customer',
                            'placeholder' => 'Search customer…',
                            'items' => $this->customers->map(fn ($n) => ['value' => $n, 'label' => $n]),
                        ])
                    </div>
                </div>

                @if ($internal)
                    <div class="card" style="border-color:var(--warning,#b8860b);margin:0.25rem 0 0;padding:0.6rem 1rem;font-size:.875rem;">
                        <strong>Inter-company move.</strong> A load to {{ \Modules\Bpl\Models\BplDelivery::INTERNAL_CUSTOMER }}
                        carries no truck paperwork and is not weighed, so its waybill is complete as soon as it is created.
                    </div>
                @else
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0.75rem;margin-top:0.25rem;">
                        <div class="form-group">
                            <label class="form-label">Truck Number</label>
                            <input type="text" class="form-control" wire:model="vehicleNumber" list="dl-vehicles" autocomplete="off">
                            @error('vehicleNumber') <div class="form-error">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">Driver</label>
                            <input type="text" class="form-control" wire:model="driver" list="dl-drivers" autocomplete="off">
                            @error('driver') <div class="form-error">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">Driver Phone</label>
                            <input type="text" class="form-control" wire:model="driverPhone" maxlength="20" autocomplete="off">
                            @error('driverPhone') <div class="form-error">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">Loader</label>
                            <input type="text" class="form-control" wire:model="loader" list="dl-loaders" autocomplete="off">
                            @error('loader') <div class="form-error">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">Container Number</label>
                            <input type="text" class="form-control" wire:model="containerNumber" autocomplete="off">
                            @error('containerNumber') <div class="form-error">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">Empty Truck Weight (kg)</label>
                            <input type="number" class="form-control qty-input" wire:model="truckWeight" min="0" step="10">
                            @error('truckWeight') <div class="form-error">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    {{-- One datalist each, shared by the boxes above: what has
                         been typed before, so a truck keeps one spelling. --}}
                    @foreach (['dl-vehicles' => 'vehicles', 'dl-drivers' => 'drivers', 'dl-loaders' => 'loaders'] as $id => $key)
                        <datalist id="{{ $id }}">
                            @foreach ($this->suggestions[$key] as $value)
                                <option value="{{ $value }}"></option>
                            @endforeach
                        </datalist>
                    @endforeach
                @endif

                <form wire:submit.prevent="addScan" style="margin-top:1rem;">
                    <div class="form-group" style="max-width:520px;">
                        <label class="form-label">
                            Barcode <span class="text-muted text-sm">({{ count($items) }}/{{ \Modules\Bpl\Livewire\Logistics\Delivery::MAX_ROLLS }})</span>
                        </label>
                        <input type="text" class="form-control" wire:model="scan" wire:key="scan-input"
                               placeholder="Scan / enter roll barcode here, then Enter" autocomplete="off" autofocus>
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
                                <th>Roll No.</th>
                                <th>Grade</th>
                                <th>Product</th>
                                <th style="width:110px;text-align:right;">Weight</th>
                                <th class="col-actions">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($items as $i => $item)
                                <tr wire:key="roll-{{ $item['barcode'] }}">
                                    <td>{{ $i + 1 }}</td>
                                    <td class="mono">{{ $item['barcode'] }}</td>
                                    <td>{{ $item['rollnumber'] }}</td>
                                    <td>{{ $item['gradetype'] }}</td>
                                    <td>{{ $item['productname'] }}</td>
                                    <td style="text-align:right;">{{ number_format((float) $item['weight'], 2) }}</td>
                                    <td class="col-actions">
                                        <button type="button" class="btn btn-danger btn-icon btn-sm" wire:click="removeItem({{ $i }})" title="Remove">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="empty-row">No rolls scanned yet.</td></tr>
                            @endforelse
                        </tbody>
                        @if ($items)
                            <tfoot>
                                <tr>
                                    <th colspan="5" style="text-align:right;">{{ count($items) }} rolls</th>
                                    <th style="text-align:right;">{{ number_format($this->totalWeight(), 2) }}</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>

                @if ($this->summary())
                    <div class="table-wrap" style="margin-top:1rem;max-width:640px;">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>Grade</th>
                                    <th>Product</th>
                                    <th style="width:80px;text-align:right;">Rolls</th>
                                    <th style="width:120px;text-align:right;">Weight</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->summary() as $line)
                                    <tr>
                                        <td>{{ $line['grade'] }}</td>
                                        <td>{{ $line['product'] }}</td>
                                        <td style="text-align:right;">{{ $line['rolls'] }}</td>
                                        <td style="text-align:right;">{{ number_format($line['weight'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <div class="text-muted text-sm" style="margin-top:.4rem;">This is how the waybill prints the load.</div>
                    </div>
                @endif

                <div style="margin-top:1.25rem;max-width:520px;">
                    <button type="button" class="btn btn-primary" style="width:100%;"
                            wire:click="save" @disabled(count($items) === 0)
                            wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Create waybill ({{ count($items) }})</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>

                <p class="text-muted text-sm" style="margin:1rem 0 0;">
                    Saving stamps every roll as gone, so it cannot be loaded onto a second truck. The waybill
                    number is the day's next: <span class="mono">{{ \Modules\Bpl\Support\DeliveryNumber::prefix($dateIso) }}-00n</span>.
                </p>
            </div>
        </div>
    @endif
</div>
