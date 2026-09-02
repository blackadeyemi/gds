{{--
    Print Outs — the legacy "Waybill Print Out" tab as a modal.

    The legacy listed delivery number, barcode, customer, transporter, cost,
    receipt, truck and driver, every cell a link to the same sheet. Same
    information here, with the tick boxes that let a day's waybills print in one
    run instead of a browser tab each.
--}}
@if ($printOpen)
    @php
        $rows = $this->printList;
        $picked = count($printPicked);
    @endphp

    <div class="modal-backdrop" x-data @keydown.escape.window="$wire.closePrintOuts()" style="display:flex;">
        <div class="modal-card" style="max-width:1100px;width:96%;" @click.outside="$wire.closePrintOuts()">
            <div class="modal-head">
                <div>
                    <h3 class="modal-title" style="margin:0;">Print Outs</h3>
                    <div class="text-sm text-muted">
                        {{ count($rows) }} waybill(s) on this date — tick the ones to print.
                    </div>
                </div>
                <button class="modal-close" wire:click="closePrintOuts">&times;</button>
            </div>

            <div class="modal-body" style="max-height:70vh;overflow:auto;">
                <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:.9rem;">
                    <div class="form-group" style="margin:0;min-width:190px;">
                        <label class="form-label">Date</label>
                        @include('bil::partials.date-field', ['model' => 'printDate', 'live' => true])
                    </div>
                    <div class="form-group" style="margin:0;flex:1;min-width:220px;">
                        <label class="form-label">Search</label>
                        <input type="search" class="form-control" placeholder="Barcode, customer, transporter, truck, receipt…"
                               wire:model.live.debounce.300ms="printSearch">
                    </div>
                    <div style="margin-left:auto;">
                        <a href="{{ $picked ? $this->printUrl() : '#' }}"
                           target="_blank" rel="noopener"
                           class="btn btn-primary {{ $picked ? '' : 'is-disabled' }}"
                           @if (! $picked) onclick="return false;" aria-disabled="true"
                               style="opacity:.5;pointer-events:none;" @endif>
                            Print {{ $picked ?: '' }} selected
                        </a>
                    </div>
                </div>

                <table class="data" style="width:100%;min-width:860px;">
                    <thead>
                        <tr>
                            <th style="width:38px;text-align:center;">
                                <input type="checkbox" title="Select all listed"
                                       wire:click="togglePrintAll"
                                       @checked($this->allPrintPicked())>
                            </th>
                            <th style="width:90px;">Delivery</th>
                            <th style="width:170px;">Barcode</th>
                            <th>Customer</th>
                            <th style="width:150px;">Transporter</th>
                            <th style="width:120px;text-align:right;">Cost</th>
                            <th style="width:100px;">Receipt</th>
                            <th style="width:120px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr wire:key="wpo-{{ $r->id }}">
                                <td style="text-align:center;">
                                    <input type="checkbox" value="{{ $r->barcode }}" wire:model.live="printPicked">
                                </td>
                                <td>
                                    <a href="{{ $this->printUrlFor($r->barcode) }}" target="_blank" rel="noopener"
                                       title="Open this waybill">{{ $r->deliverynumber }}</a>
                                </td>
                                <td style="font-family:monospace;">
                                    <a href="{{ $this->printUrlFor($r->barcode) }}" target="_blank" rel="noopener"
                                       title="Open this waybill">{{ $r->barcode }}</a>
                                </td>
                                <td>{{ $r->customername ?: '—' }}</td>
                                <td>{{ $r->transportername ?: '—' }}</td>
                                <td style="text-align:right;">₦{{ number_format($r->transportcost, 2) }}</td>
                                <td>{{ $r->receiptnumber ?? '—' }}</td>
                                <td>
                                    <button type="button" class="btn btn-ghost btn-sm"
                                            wire:click="togglePrintDetails('{{ $r->barcode }}')">
                                        {{ $printExpanded === $r->barcode ? 'Hide' : 'View More' }}
                                    </button>
                                </td>
                            </tr>

                            @if ($printExpanded === $r->barcode)
                                <tr wire:key="wpod-{{ $r->id }}">
                                    <td colspan="8" style="background:var(--surface-2);">
                                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.6rem 1.4rem;padding:.5rem .2rem;">
                                            <div><div class="form-label">Waybill barcode</div><div style="font-family:monospace;">{{ $r->barcode }}</div></div>
                                            <div><div class="form-label">Delivery barcode</div><div style="font-family:monospace;">{{ $r->deliverybarcode ?: '—' }}</div></div>
                                            <div><div class="form-label">Loading barcode</div><div style="font-family:monospace;">{{ $r->loadbarcode ?: '—' }}</div></div>
                                            <div><div class="form-label">Customer</div><div>{{ $r->customername ?: '—' }}</div></div>
                                            <div><div class="form-label">Transporter</div><div>{{ $r->transportername ?: '—' }}</div></div>
                                            <div><div class="form-label">Truck number</div><div>{{ $r->trucknumber ?: '—' }}</div></div>
                                            <div><div class="form-label">Truck driver</div><div>{{ $r->truckdriver ?: '—' }}</div></div>
                                            <div><div class="form-label">Transport cost</div><div>₦{{ number_format($r->transportcost, 2) }}</div></div>
                                            <div><div class="form-label">Receipt number</div><div>{{ $r->receiptnumber ?? '—' }}</div></div>
                                            <div><div class="form-label">Date of waybill</div><div>{{ $r->dateofwaybill }}</div></div>
                                            <div><div class="form-label">Raised by</div><div>{{ $r->username }}</div></div>
                                        </div>
                                        <div style="padding:.2rem;">
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                    wire:click="openDelivery('{{ $r->deliverybarcode }}'); closePrintOuts()">
                                                Open this delivery
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="8" class="empty-row text-muted">
                                    @if ($printSearch !== '')
                                        Nothing on this date matches “{{ $printSearch }}”.
                                    @else
                                        No waybills on this date.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
