{{--
    Print Outs — the legacy "Return Print Out" tab as a modal.

    Same columns the legacy listed (return number, customer, warehouse) and the
    same habit: pick a date, find the sheet, print it. The tick boxes are the
    addition — the office prints a day's returns in a run, and the legacy made
    that a browser tab each.

    A return is keyed by "{date}|{number}", because that pair IS the return: the
    number restarts every day and there is no id on the sheet.
--}}
@if ($printOpen)
    @php
        $rows = $this->printList;
        $picked = count($printPicked);
    @endphp

    <div class="modal-backdrop" x-data @keydown.escape.window="$wire.closePrintOuts()" style="display:flex;">
        <div class="modal-card" style="max-width:1000px;width:96%;" @click.outside="$wire.closePrintOuts()">
            <div class="modal-head">
                <div>
                    <h3 class="modal-title" style="margin:0;">Print Outs</h3>
                    <div class="text-sm text-muted">
                        {{ count($rows) }} return(s) on this date — tick the ones to print.
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
                        <input type="search" class="form-control" placeholder="Return number, customer, sales order…"
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

                <table class="data" style="width:100%;min-width:780px;">
                    <thead>
                        <tr>
                            <th style="width:38px;text-align:center;">
                                <input type="checkbox" title="Select all listed"
                                       wire:click="togglePrintAll"
                                       @checked($this->allPrintPicked())>
                            </th>
                            <th style="width:100px;">Return No.</th>
                            <th>Customer</th>
                            <th style="width:150px;">Warehouse</th>
                            <th style="width:110px;text-align:right;">Returned</th>
                            <th style="width:150px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $r)
                            @php $key = $r->dateofreturn . '|' . $r->returnnumber; @endphp
                            <tr wire:key="rpo-{{ $key }}">
                                <td style="text-align:center;">
                                    <input type="checkbox" value="{{ $key }}" wire:model.live="printPicked">
                                </td>
                                <td>
                                    <a href="{{ $this->printUrlFor($key) }}" target="_blank" rel="noopener"
                                       title="Open this return note">{{ $r->returnnumber }}</a>
                                </td>
                                <td>{{ $r->customername ?: '—' }}</td>
                                <td>{{ $r->warehouse ?: '—' }}</td>
                                <td style="text-align:right;">
                                    {{ number_format($r->returned) }}
                                    @if ($r->rejected > 0)
                                        <span class="badge" style="background:rgba(217,119,6,.14);color:#b45309;">{{ number_format($r->rejected) }} rej</span>
                                    @endif
                                </td>
                                <td>
                                    <button type="button" class="btn btn-ghost btn-sm"
                                            wire:click="togglePrintDetails('{{ $key }}')">
                                        {{ $printExpanded === $key ? 'Hide' : 'View More' }}
                                    </button>
                                </td>
                            </tr>

                            @if ($printExpanded === $key)
                                <tr wire:key="rpod-{{ $key }}">
                                    <td colspan="6" style="background:var(--surface-2);">
                                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.6rem 1.4rem;padding:.5rem .2rem;">
                                            <div><div class="form-label">Return number</div><div>{{ $r->returnnumber }}</div></div>
                                            <div><div class="form-label">Customer</div><div>{{ $r->customername ?: '—' }}</div></div>
                                            <div><div class="form-label">Sales order(s)</div><div>{{ implode(', ', $r->orders) ?: '—' }}</div></div>
                                            <div><div class="form-label">Warehouse</div><div>{{ $r->warehouse ?: '—' }}</div></div>
                                            <div><div class="form-label">Date of return</div><div>{{ $r->dateofreturn }}</div></div>
                                            <div><div class="form-label">Recorded by</div><div>{{ $r->username ?: '—' }}</div></div>
                                            <div><div class="form-label">Lines</div><div>{{ number_format($r->line_count) }}</div></div>
                                            <div><div class="form-label">Returned</div><div>{{ number_format($r->returned) }}</div></div>
                                            <div><div class="form-label">Rejected</div><div>{{ number_format($r->rejected) }}</div></div>
                                        </div>
                                        <div style="padding:.2rem;">
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                    wire:click="openReturn('{{ $r->dateofreturn }}', {{ $r->returnnumber }}); closePrintOuts()">
                                                Open this return
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="6" class="empty-row text-muted">
                                    @if ($printSearch !== '')
                                        Nothing on this date matches “{{ $printSearch }}”.
                                    @else
                                        No returns on this date.
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
