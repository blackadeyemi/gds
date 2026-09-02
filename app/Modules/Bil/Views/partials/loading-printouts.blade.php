{{--
    Print Outs — the legacy "Loading Print Out" tab, as a modal.

    Same information and the same working habit: pick a date, see every load on
    it, open the one you want. What is added is the tick box — the cageroom
    prints a day's loads in a run, and the legacy screen made that a browser tab
    per load. Ticking several and pressing Print produces one document,
    page-broken between loads.

    Rendered server-side only while open, like the stock movement modal: the list
    costs a query, and there is no reason to pay it on every render of the page
    behind it.
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
                        {{ count($rows) }} load(s) on this date — tick the ones to print.
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
                        <input type="search" class="form-control" placeholder="Load number, barcode, customer, transporter, truck…"
                               wire:model.live.debounce.300ms="printSearch">
                    </div>
                    <div style="margin-left:auto;">
                        {{-- A plain link, not a Livewire action: the print view is
                             a normal document and opens in its own tab, which is
                             how the cageroom already works. --}}
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
                            <th style="width:90px;">Load No.</th>
                            <th style="width:170px;">Barcode</th>
                            <th>Customer</th>
                            <th style="width:170px;">Transporter</th>
                            <th style="width:120px;">Status</th>
                            <th style="width:150px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr wire:key="po-{{ $r->barcode }}">
                                <td style="text-align:center;">
                                    <input type="checkbox" value="{{ $r->barcode }}" wire:model.live="printPicked">
                                </td>
                                <td>
                                    <a href="{{ $this->printUrlFor($r->barcode) }}" target="_blank" rel="noopener"
                                       title="Open this printout">{{ $r->loadnumber }}</a>
                                </td>
                                <td style="font-family:monospace;">
                                    <a href="{{ $this->printUrlFor($r->barcode) }}" target="_blank" rel="noopener"
                                       title="Open this printout">{{ $r->barcode }}</a>
                                </td>
                                <td>{{ $r->customername ?: '—' }}</td>
                                <td>{{ $r->transportername ?: '—' }}</td>
                                <td>
                                    @if ($r->status)
                                        <span class="badge badge-muted">Delivered</span>
                                    @else
                                        <span class="badge badge-success">Not delivered</span>
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
                                <tr wire:key="pod-{{ $r->barcode }}">
                                    <td colspan="7" style="background:var(--surface-2);">
                                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.6rem 1.4rem;padding:.5rem .2rem;">
                                            <div><div class="form-label">Load number</div><div>{{ $r->loadnumber }}</div></div>
                                            <div><div class="form-label">Barcode</div><div style="font-family:monospace;">{{ $r->barcode }}</div></div>
                                            <div><div class="form-label">Customer</div><div>{{ $r->customername ?: '—' }}</div></div>
                                            <div><div class="form-label">Transporter</div><div>{{ $r->transportername ?: '—' }}</div></div>
                                            <div><div class="form-label">Truck number</div><div>{{ $r->trucknumber ?: '—' }}</div></div>
                                            <div><div class="form-label">Driver</div><div>{{ $r->truckdriver ?: '—' }}</div></div>
                                            <div><div class="form-label">Loader</div><div>{{ $r->loader ?: '—' }}</div></div>
                                            <div><div class="form-label">Date of loading</div><div>{{ $r->dateofloading }}</div></div>
                                            <div><div class="form-label">Lines</div><div>{{ number_format($r->line_count) }}</div></div>
                                            <div><div class="form-label">Quantity loaded</div><div>{{ number_format($r->loaded) }}</div></div>
                                            <div><div class="form-label">Status</div><div>{{ $r->status ? 'Delivered ' . $r->status : 'Not delivered' }}</div></div>
                                        </div>
                                        <div style="padding:.2rem;">
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                    wire:click="openLoad('{{ $r->barcode }}'); closePrintOuts()">
                                                Open this load
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
                                        No loads on this date.
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
