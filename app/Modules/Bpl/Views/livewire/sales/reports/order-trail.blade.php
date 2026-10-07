{{--
    BPL Order Trail — one export order, every step of it.

    Two states: no order chosen (a search box and what it matches), and an order
    open (its header, the progress strip, the timeline and the order lines).
    They are exclusive, so the page never shows a search box competing with a
    result.
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
                    <label class="form-label">Any number on the order</label>
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-start;">
                        <div class="form-group mb-0" style="flex:1 1 360px;min-width:0;margin-bottom:0;">
                            <input type="search" class="form-control" autofocus
                                   placeholder="Order number, NXP, bill of lading, container, customer…"
                                   wire:model.live.debounce.350ms="search">
                            <div class="form-hint">
                                {{-- Nine ways in, listed rather than guessed at: nobody
                                     tries a container number in a search box unless
                                     they are told it will work. --}}
                                Searches {{ \Illuminate\Support\Str::lower(implode(', ', array_slice($this->searchWays(), 0, -1))) }}
                                and {{ \Illuminate\Support\Str::lower(\Illuminate\Support\Arr::last($this->searchWays())) }} —
                                each result says which one matched.
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Open</button>
                    </div>
                </form>

                @if ($this->search !== '')
                    <div style="margin-top:.9rem;max-height:26rem;overflow:auto;margin-left:-0.4rem;margin-right:-0.4rem;">
                        @forelse ($this->suggestions as $hit)
                            <button type="button" wire:key="s-{{ $hit->id }}"
                                    wire:click="openOrder('{{ $hit->id }}')"
                                    class="btn btn-ghost"
                                    style="display:block;width:100%;text-align:left;padding:0.55rem 0.7rem;margin-bottom:0.2rem;border-radius:6px;">
                                <div style="display:flex;gap:.5rem;align-items:baseline;flex-wrap:wrap;">
                                    <span style="font-weight:600;font-family:monospace;">{{ $hit->number }}</span>
                                    <span class="badge badge-muted">{{ $hit->matched }}</span>
                                </div>
                                <div class="text-sm text-muted">
                                    {{ $hit->customername }}
                                    @if ($hit->country) · {{ $hit->country }} @endif
                                    · {{ $this->date($hit->date) }}
                                </div>
                            </button>
                        @empty
                            <div class="text-muted" style="padding:0.8rem;">
                                Nothing matches “{{ $this->search }}”.
                                <div class="text-sm" style="margin-top:.3rem;">
                                    Order numbers and customer codes are matched from the
                                    start; everything else is matched anywhere. Two
                                    characters at least.
                                </div>
                            </div>
                        @endforelse

                        @if ($this->hasMoreMatches())
                            <div class="text-muted text-sm" style="padding:0.6rem 0.8rem;">
                                Showing the first {{ \Modules\Bpl\Livewire\Sales\Reports\OrderTrail::SUGGEST_LIMIT }} —
                                type more of the number to narrow it.
                            </div>
                        @endif
                    </div>
                @endif
            @else
                @php $totals = $this->totals; @endphp
                <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                    <div style="flex:1 1 280px;min-width:0;">
                        <div class="form-label" style="margin-bottom:.15rem;">{{ $order->number }}</div>
                        <div style="font-size:1.25rem;font-weight:700;line-height:1.25;">
                            {{ $order->customername ?: 'No customer on this order' }}
                        </div>
                        <div class="text-sm text-muted" style="margin-top:.2rem;">
                            @if ($order->customerlabel) {{ $order->customerlabel }} · @endif
                            ordered {{ $this->date($order->date) }}
                            @if ($order->customercountry) · {{ $order->customercountry }} @endif
                            @if ($order->username) · entered by {{ $order->username }} @endif
                        </div>
                        @if ($order->nxp)
                            <div class="text-sm text-muted">NXP {{ $order->nxp }}</div>
                        @endif
                    </div>

                    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-start;">
                        @foreach ([
                            'Ordered' => $this->tonnes($totals['ordered']) . ' t',
                            'Shipped' => $this->tonnes($totals['shipped']) . ' t',
                            'Quoted' => $this->money($totals['quoted']),
                            'Invoiced' => $this->money($totals['invoiced']),
                            'Paid' => $this->money($totals['paid']),
                        ] as $label => $value)
                            <div>
                                <div class="form-label">{{ $label }}</div>
                                <div style="font-size:1.1rem;font-weight:600;line-height:1.2;">{{ $value }}</div>
                            </div>
                        @endforeach
                        @if ($totals['outstanding'] > 1)
                            <div>
                                <div class="form-label">Outstanding</div>
                                <div class="text-danger" style="font-size:1.1rem;font-weight:700;line-height:1.2;">
                                    {{ $this->money($totals['outstanding']) }}
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-2" style="margin-left:auto;">
                        @if ($this->canExport())
                            <div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
                                <button class="btn btn-ghost btn-sm" @click="open = !open" title="Export or print this order's trail">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                                    Export
                                    <svg width="10" height="7" viewBox="0 0 12 8" fill="none" style="margin-left:.1rem;"><path d="M1 1l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <div class="dropdown-menu" x-show="open" x-cloak x-transition @click="open = false">
                                    <a class="dropdown-item" href="{{ $this->downloadUrl('xlsx') }}">Export Excel (.xlsx)</a>
                                    <a class="dropdown-item" href="{{ $this->downloadUrl('csv') }}">Export CSV</a>
                                    <a class="dropdown-item" href="{{ $this->downloadUrl('pdf') }}">Export PDF</a>
                                    <div class="dropdown-sep"></div>
                                    <a class="dropdown-item" target="_blank" href="{{ $this->printUrl() }}">Print the whole trail</a>
                                </div>
                            </div>
                        @endif
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="clearOrder">Another order</button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($order)
        {{-- ---------------- Where it has got to ---------------- --}}
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-pad" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:stretch;">
                @foreach ($this->progress as $step)
                    <div style="flex:1 1 118px;min-width:110px;padding:.55rem .6rem;border-radius:6px;
                                border:1px solid var(--border);
                                {{ $step['done'] ? 'border-color:var(--success);' : 'opacity:.6;' }}">
                        <div class="text-sm" style="font-weight:600;">
                            @if ($step['done'])
                                <span style="color:var(--success);">&check;</span>
                            @else
                                <span class="text-muted">&circ;</span>
                            @endif
                            {{ $step['label'] }}
                        </div>
                        <div class="text-sm text-muted" style="margin-top:.15rem;">
                            {{ $step['date'] ? $this->date($step['date']) : ($step['done'] ? 'done' : 'not yet') }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ---------------- The timeline ---------------- --}}
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-head">
                <h2 class="card-title">What happened</h2>
                <span class="text-sm text-muted" style="margin-left:auto;">
                    {{ $this->totals['shipments'] }} {{ \Illuminate\Support\Str::plural('shipment', $this->totals['shipments']) }},
                    {{ $this->totals['containers'] }} {{ \Illuminate\Support\Str::plural('container', $this->totals['containers']) }}
                </span>
            </div>
            <div class="table-wrap">
                <table class="data" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:110px;">Date</th>
                            <th style="width:170px;">Step</th>
                            <th style="width:210px;">Reference</th>
                            <th style="width:130px;text-align:right;">Amount</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Never empty: the order itself is always the first row,
                             so an order nothing has happened to still shows how it
                             started. --}}
                        @foreach ($this->trail as $event)
                            <tr wire:key="e-{{ $loop->index }}">
                                <td style="white-space:nowrap;">{{ $this->date($event['date']) }}</td>
                                <td>@include('bpl::partials.trail-stage', ['stage' => $event['stage'], 'label' => $event['label']])</td>
                                <td style="font-family:monospace;">{{ $event['reference'] }}</td>
                                <td style="text-align:right;white-space:nowrap;">
                                    @if ($event['money'] === null)
                                        <span class="text-muted">—</span>
                                    @elseif ($event['stage'] === 'payment')
                                        <span class="text-success">{{ $this->money($event['money']) }}</span>
                                    @else
                                        {{ $this->money($event['money']) }}
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
                    Nothing has happened to this order since — it was raised and never quoted.
                </div>
            @endif
        </div>

        {{-- ---------------- The goods ---------------- --}}
        <div class="card">
            <div class="card-head">
                <h2 class="card-title">What was ordered</h2>
            </div>
            <div class="table-wrap">
                <table class="data" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="width:110px;text-align:right;">Ordered (t)</th>
                            <th style="width:110px;text-align:right;">Shipped (t)</th>
                            <th style="width:110px;text-align:right;">Balance (t)</th>
                            <th style="width:120px;text-align:right;">Price / t</th>
                            <th style="width:130px;text-align:right;">Quoted</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->lines as $line)
                            <tr wire:key="l-{{ $line->id }}">
                                <td>
                                    {{ $line->productname }}
                                    <div class="text-sm text-muted">{{ $line->description }}</div>
                                </td>
                                <td style="text-align:right;">{{ $this->tonnes($line->weight) }}</td>
                                <td style="text-align:right;">{{ $this->tonnes($line->shipped) }}</td>
                                <td style="text-align:right;">
                                    @if ($line->balance > 1)
                                        <span class="badge badge-warning">{{ $this->tonnes($line->balance) }}</span>
                                    @else
                                        <span class="badge badge-success">complete</span>
                                    @endif
                                </td>
                                <td style="text-align:right;">
                                    @if ($line->price === null)
                                        <span class="badge badge-warning">not priced</span>
                                    @else
                                        {{ $this->money($line->price) }}
                                    @endif
                                </td>
                                <td style="text-align:right;">{{ $this->money($line->amount) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="empty-row">This order has no lines.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
