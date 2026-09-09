{{--
    Order Trail — one order, every transaction on it, one order line per page.

    Two states: no order chosen (a search box and what it matches), and an order
    open (its header, the line pager, and that line's timeline). They are
    exclusive, so the page never shows a search box competing with a result.
--}}
<div>
    <div class="page-head">
        <h1>{{ $this->title() }}</h1>
        <p>{{ $this->subtitle() }}</p>
    </div>

    @php $order = $this->order; @endphp

    {{-- ---------------- Finding an order ---------------- --}}
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-pad">
            @if (! $order)
                <form wire:submit="submitSearch">
                    <label class="form-label">Order number, or the customer's name</label>
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-start;">
                        <div class="form-group mb-0" style="flex:1 1 320px;min-width:0;margin-bottom:0;">
                            <input type="search" class="form-control" autofocus
                                   placeholder="e.g. 117432 — or RUBY EXALTED"
                                   wire:model.live.debounce.350ms="search">
                            <div class="form-hint">
                                A number is matched from the start; anything else is treated as a customer.
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Open</button>
                    </div>
                </form>

                @if ($this->search !== '')
                    <div style="margin-top:.9rem;max-height:26rem;overflow:auto;margin-left:-0.4rem;margin-right:-0.4rem;">
                        @forelse ($this->suggestions as $hit)
                            <button type="button" wire:key="s-{{ $hit->orderid }}"
                                    wire:click="openOrder('{{ $hit->orderid }}')"
                                    class="btn btn-ghost"
                                    style="display:block;width:100%;text-align:left;padding:0.55rem 0.7rem;margin-bottom:0.2rem;border-radius:6px;">
                                <div style="font-weight:600;font-family:monospace;">{{ $hit->orderid }}</div>
                                <div class="text-sm text-muted">
                                    {{ $hit->customername ?: 'No customer on this order' }}
                                    · {{ $hit->dateoforder }}
                                </div>
                            </button>
                        @empty
                            <div class="text-muted" style="padding:0.8rem;">
                                Nothing matches “{{ $this->search }}”.
                            </div>
                        @endforelse
                    </div>
                @endif
            @else
                {{-- The order, and who it is for. The customer is the first
                     thing asked for, so it is the biggest thing here. --}}
                <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                    <div style="flex:1 1 280px;min-width:0;">
                        <div class="form-label" style="margin-bottom:.15rem;">Order {{ $order->orderid }}</div>
                        <div style="font-size:1.25rem;font-weight:700;line-height:1.25;">
                            {{ $order->customername ?: 'No customer on this order' }}
                        </div>
                        <div class="text-sm text-muted" style="margin-top:.2rem;">
                            @if ($order->customercode) {{ $order->customercode }} · @endif
                            ordered {{ $order->dateoforder }}
                            @if ($order->warehouse) · {{ $order->warehouse }} @endif
                            @if ($order->username) · entered by {{ $order->username }} @endif
                        </div>
                        @if ($order->customeraddress)
                            <div class="text-sm text-muted">{{ $order->customeraddress }}</div>
                        @endif
                    </div>

                    @php $totals = $this->totals(); @endphp
                    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-start;">
                        @foreach ([
                            'Ordered' => $totals['ordered'],
                            'Loaded' => $totals['loaded'],
                            'Delivered' => $totals['delivered'],
                            'Returned' => $totals['returned'],
                        ] as $label => $value)
                            <div>
                                <div class="form-label">{{ $label }}</div>
                                <div style="font-size:1.15rem;font-weight:600;line-height:1.2;">
                                    {{ number_format($value) }}
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-2" style="margin-left:auto;">
                        @if ($this->canExport())
                            <div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
                                <button class="btn btn-ghost btn-icon btn-sm" @click="open = !open" title="Export / Print">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                                </button>
                                <div class="dropdown-menu" x-show="open" x-cloak x-transition @click="open = false">
                                    <a class="dropdown-item" href="{{ $this->downloadUrl('xlsx') }}">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                                        Export Excel (.xlsx)
                                    </a>
                                    <a class="dropdown-item" href="{{ $this->downloadUrl('csv') }}">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                                        Export CSV
                                    </a>
                                    <a class="dropdown-item" href="{{ $this->downloadUrl('pdf') }}">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 15h6M9 18h6M9 12h2"/></svg>
                                        Export PDF
                                    </a>
                                    <div class="dropdown-sep"></div>
                                    <a class="dropdown-item" target="_blank" href="{{ $this->printUrl() }}">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                                        Print the whole trail
                                    </a>
                                </div>
                            </div>
                        @endif
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="clearOrder">
                            Another order
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($order)
        @php $line = $this->line; $count = $this->pageCount(); @endphp

        {{-- ---------------- The pager, and the optional product filter ---------------- --}}
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-pad" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
                <div class="form-group mb-0" style="flex:0 1 300px;min-width:220px;margin-bottom:0;">
                    <label class="form-label">Product</label>
                    <select class="form-control" wire:model.live="productid">
                        <option value="">All products on this order</option>
                        @foreach ($this->products as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($count > 0)
                    <div class="flex items-center gap-2" style="margin-left:auto;flex-wrap:wrap;">
                        <span class="text-sm text-muted">
                            Product {{ min($this->lineNo, $count) }} of {{ $count }}
                        </span>
                        <button type="button" class="btn btn-ghost btn-sm"
                                wire:click="previousLine" @disabled($this->lineNo <= 1)>
                            Previous
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm"
                                wire:click="nextLine" @disabled($this->lineNo >= $count)>
                            Next
                        </button>
                    </div>
                @endif
            </div>

            {{-- Every line as a numbered chip, so nine products are one click
                 apart rather than eight Nexts. --}}
            @if ($count > 1)
                <div class="card-pad" style="padding-top:0;display:flex;gap:.35rem;flex-wrap:wrap;">
                    @foreach ($this->lines as $i => $l)
                        <button type="button" wire:key="chip-{{ $l->id }}"
                                wire:click="goTo({{ $i + 1 }})"
                                class="btn btn-sm {{ min($this->lineNo, $count) === $i + 1 ? 'btn-primary' : 'btn-ghost' }}"
                                title="{{ $l->productname }}{{ $l->foc ? ' (FOC)' : '' }}">
                            {{ $i + 1 }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ---------------- The line on screen ---------------- --}}
        @if (! $line)
            <div class="card card-pad text-muted">
                This order has no lines{{ $this->productid !== '' ? ' for that product' : '' }}.
            </div>
        @else
            <div class="card">
                <div class="card-head" style="flex-wrap:wrap;gap:.75rem;">
                    <div>
                        <h2 class="card-title">{{ $line->productname }}</h2>
                        <div class="text-sm text-muted">
                            {{ $line->productcode ?: '—' }}
                            · {{ $line->foc ? 'free of charge' : 'sold' }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2" style="margin-left:auto;flex-wrap:wrap;">
                        @if ($line->foc)
                            <strong class="text-danger">FOC</strong>
                        @endif
                        <span class="badge badge-muted">{{ number_format($line->quantityordered) }} ordered</span>
                        <span class="badge badge-muted">{{ number_format($line->loaded) }} loaded</span>
                        @if ($line->returned > 0)
                            <span class="badge badge-warning">{{ number_format($line->returned) }} returned</span>
                        @endif
                        @if ($line->balance > 0)
                            <span class="badge badge-danger">{{ number_format($line->balance) }} still owed</span>
                        @else
                            <span class="badge badge-success">complete</span>
                        @endif
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:110px;">Date</th>
                                <th style="width:170px;">Transaction</th>
                                <th style="width:180px;">Reference</th>
                                <th style="width:110px;text-align:right;">Bundles</th>
                                <th>Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Never empty: the order itself is always the
                                 first row, so a line with nothing else against
                                 it still shows how it started. --}}
                            @foreach ($this->trail as $event)
                                <tr wire:key="e-{{ $line->id }}-{{ $loop->index }}">
                                    <td style="white-space:nowrap;">{{ $event['date'] }}</td>
                                    <td>
                                        @include('bil::partials.trail-stage', ['stage' => $event['stage'], 'label' => $event['label']])
                                    </td>
                                    <td style="font-family:monospace;">{{ $event['reference'] }}</td>
                                    <td style="text-align:right;">
                                        @if ($event['quantity'] === null)
                                            <span class="text-muted">—</span>
                                        @elseif ($event['stage'] === 'unload' || $event['stage'] === 'return')
                                            <span class="text-danger">−{{ number_format($event['quantity']) }}</span>
                                        @else
                                            {{ number_format($event['quantity']) }}
                                        @endif
                                    </td>
                                    <td class="text-sm text-muted">{{ $event['detail'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if (count($this->trail) === 1)
                    <div class="card-pad text-muted text-sm" style="padding-top:0.9rem;">
                        Nothing has happened to this line since — it was ordered and never loaded.
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
