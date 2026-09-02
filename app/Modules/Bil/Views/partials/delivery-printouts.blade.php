{{--
    Print Outs — the legacy "Delivery Print Out" tab, as a modal.

    Same columns the legacy listed (delivery number, barcode, customer, and a
    View More that opened its details), and the same working habit: pick a date,
    find the note, print it. What is added is the tick box — the office prints a
    day's notes in a run, and the legacy screen made that a browser tab each.

    Rendered server-side only while open: the list costs a query, and there is no
    reason to pay it on every render of the page behind it.
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
                        {{ count($rows) }} deliver(ies) on this date — tick the ones to print.
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
                        <input type="search" class="form-control" placeholder="Delivery number, barcode, customer, load, truck…"
                               wire:model.live.debounce.300ms="printSearch">
                    </div>
                    <div style="margin-left:auto;">
                        {{-- A plain link, not a Livewire action: the print view is
                             a normal document and opens in its own tab, which is
                             how the office already works. --}}
                        <a href="{{ $picked ? $this->printUrl() : '#' }}"
                           target="_blank" rel="noopener"
                           class="btn btn-primary {{ $picked ? '' : 'is-disabled' }}"
                           @if (! $picked) onclick="return false;" aria-disabled="true"
                               style="opacity:.5;pointer-events:none;" @endif>
                            Print {{ $picked ?: '' }} selected
                        </a>
                    </div>
                </div>

                <table class="data" style="width:100%;min-width:820px;">
                    <thead>
                        <tr>
                            <th style="width:38px;text-align:center;">
                                <input type="checkbox" title="Select all listed"
                                       wire:click="togglePrintAll"
                                       @checked($this->allPrintPicked())>
                            </th>
                            <th style="width:90px;">Delivery No.</th>
                            <th style="width:170px;">Barcode</th>
                            <th>Customer</th>
                            <th style="width:170px;">Load</th>
                            <th style="width:120px;">Waybill</th>
                            <th style="width:150px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr wire:key="dpo-{{ $r->id }}">
                                <td style="text-align:center;">
                                    <input type="checkbox" value="{{ $r->barcode }}" wire:model.live="printPicked">
                                </td>
                                <td>
                                    <a href="{{ $this->printUrlFor($r->barcode) }}" target="_blank" rel="noopener"
                                       title="Open this delivery note">{{ $r->deliverynumber }}</a>
                                </td>
                                <td style="font-family:monospace;">
                                    <a href="{{ $this->printUrlFor($r->barcode) }}" target="_blank" rel="noopener"
                                       title="Open this delivery note">{{ $r->barcode }}</a>
                                </td>
                                <td>{{ $r->customername ?: '—' }}</td>
                                <td style="font-family:monospace;">{{ $r->loadbarcode ?: '—' }}</td>
                                <td>
                                    @if ($r->waybill)
                                        <span class="badge badge-muted">Generated</span>
                                    @else
                                        <span class="badge badge-success">Not generated</span>
                                    @endif
                                </td>
                                <td>
                                    <button type="button" class="btn btn-ghost btn-sm"
                                            wire:click="togglePrintDetails('{{ $r->barcode }}')">
                                        {{ $printExpanded === $r->barcode ? 'Hide' : 'View More' }}
                                    </button>
                                </td>
                            </tr>

                            {{-- "View More": the legacy modal's fields, opened in
                                 place so the list keeps its position. --}}
                            @if ($printExpanded === $r->barcode)
                                <tr wire:key="dpod-{{ $r->id }}">
                                    <td colspan="7" style="background:var(--surface-2);">
                                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.6rem 1.4rem;padding:.5rem .2rem;">
                                            <div><div class="form-label">Delivery number</div><div>{{ $r->deliverynumber }}</div></div>
                                            <div><div class="form-label">Barcode</div><div style="font-family:monospace;">{{ $r->barcode }}</div></div>
                                            <div><div class="form-label">Customer</div><div>{{ $r->customername ?: '—' }}</div></div>
                                            <div><div class="form-label">Loading barcode</div><div style="font-family:monospace;">{{ $r->loadbarcode ?: '—' }}</div></div>
                                            <div><div class="form-label">Transporter</div><div>{{ $r->transportername ?: '—' }}</div></div>
                                            <div><div class="form-label">Truck number</div><div>{{ $r->trucknumber ?: '—' }}</div></div>
                                            <div><div class="form-label">Truck driver</div><div>{{ $r->truckdriver ?: '—' }}</div></div>
                                            <div><div class="form-label">Date of delivery</div><div>{{ $r->dateofdelivery }}</div></div>
                                            <div><div class="form-label">Confirmed by</div><div>{{ $r->username }}</div></div>
                                            <div><div class="form-label">Bundles</div><div>{{ number_format($r->loaded) }}</div></div>
                                            <div><div class="form-label">Waybill</div><div>{{ $r->waybill ?: 'Not generated' }}</div></div>
                                        </div>
                                        <div style="padding:.2rem;">
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                    wire:click="openLoad('{{ $r->loadbarcode }}', {{ $r->id }}); closePrintOuts()">
                                                Open this delivery
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="7" class="empty-row text-muted">
                                    @if ($printSearch !== '')
                                        Nothing on this date matches “{{ $printSearch }}”.
                                    @else
                                        No deliveries on this date.
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
