{{--
    Delivery — the two legacy delivery screens as one.

    Same shape as Loading, because it is the same object seen one step later.
    The left column is the queue of loads still on the floor; pick one and the
    right shows what is on it and the single button that sends it. A load that
    has already gone shows its delivery note instead, and the undo of that
    button — which is all the legacy Modification page ever was.
--}}
@php
    $load = $this->load;
    $delivery = $this->delivery;
    $lines = $this->lines;
    $pending = $this->isPending();
    $totals = $this->totals();
@endphp

<div>
    {{-- Print Outs sits top-right: it is a way OUT of the page (to paper), not
         a step in it, so it does not belong among the working controls. --}}
    <div class="page-head" style="display:flex;align-items:flex-start;gap:1rem;">
        <div>
            <h1>Delivery</h1>
            <p>Confirm that a load has gone out. Nothing is re-entered here — the truck was filled at loading, and this is the record that it left.</p>
        </div>
        <button type="button" class="btn btn-ghost" style="margin-left:auto;flex:none;" wire:click="openPrintOuts">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
            Print Outs
        </button>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    {{-- ---------------- In transit ----------------
         Loading is the stock deduction point, so everything below has already
         left the warehouse. Splitting it matters: added together it reads as
         "23,483 bundles on the road", when almost all of it is loads nobody
         ever closed. The two halves need different actions, so they are shown
         as different numbers. --}}
    @php $transit = $this->inTransit; @endphp
    @if ($transit['loads'] > 0)
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-pad" style="display:flex;gap:2rem;flex-wrap:wrap;align-items:flex-start;">
                <div>
                    <div class="form-label">On the road</div>
                    <div style="font-size:1.5rem;font-weight:600;line-height:1.2;">
                        {{ number_format($transit['fresh_bundles']) }}
                        <span class="text-sm text-muted" style="font-weight:400;">bundles</span>
                    </div>
                    <div class="text-sm text-muted">
                        {{ $transit['fresh_loads'] }} load(s), loaded in the last {{ $transit['stale_after'] }} days
                    </div>
                </div>

                @if ($transit['stale_loads'] > 0)
                    <div>
                        <div class="form-label">Not confirmed</div>
                        <div style="font-size:1.5rem;font-weight:600;line-height:1.2;color:#b45309;">
                            {{ number_format($transit['stale_bundles']) }}
                            <span class="text-sm text-muted" style="font-weight:400;">bundles</span>
                        </div>
                        <div class="text-sm text-muted">
                            {{ $transit['stale_loads'] }} load(s) older than that — oldest {{ $transit['oldest'] }}
                        </div>
                    </div>
                @endif

                <div style="margin-left:auto;">
                    <div class="form-label">By age</div>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                        @foreach ($transit['buckets'] as $label => $bucket)
                            <span class="badge badge-muted" title="{{ number_format($bucket[1]) }} bundles">
                                {{ $label }}: {{ $bucket[0] }}
                            </span>
                        @endforeach
                    </div>
                    <div class="text-sm text-muted" style="margin-top:.35rem;">
                        These bundles are already off warehouse stock — loading is what deducts.
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div style="display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:1rem;align-items:start;">

        {{-- ---------------- The queue ---------------- --}}
        <div class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ $showDelivered ? 'Already delivered' : 'Awaiting delivery' }}</h2>
                    <div class="text-sm text-muted">
                        @if ($showDelivered)
                            {{ count($this->deliveredLoads) }} on the chosen date — open one to reprint or undo it
                        @elseif ($search !== '')
                            {{ count($this->pendingLoads) }}{{ $this->hasMore() ? '+' : '' }} match{{ count($this->pendingLoads) === 1 ? '' : 'es' }}
                        @else
                            showing {{ count($this->pendingLoads) }} of {{ number_format($this->pendingCount) }} still on the floor
                        @endif
                    </div>
                </div>
            </div>

            <div class="card-pad">
                {{-- The two things this list can be, as a switch at the top of
                     it. Finding a delivery already made used to be a checkbox
                     BELOW a list 42rem tall, so it was off-screen unless you
                     scrolled past everything awaiting — and it appended a
                     second scrolling list under the first. They are one
                     question ("which load am I working on?") asked of two
                     sources, so they take turns in one pane. --}}
                <div class="seg" role="group" aria-label="Which loads to show" style="margin-bottom:0.7rem;">
                    <button type="button" @class(['active' => ! $showDelivered])
                            aria-pressed="{{ $showDelivered ? 'false' : 'true' }}"
                            wire:click="$set('showDelivered', false)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                        <span>Awaiting</span>
                    </button>
                    <button type="button" @class(['active' => $showDelivered])
                            aria-pressed="{{ $showDelivered ? 'true' : 'false' }}"
                            wire:click="$set('showDelivered', true)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                        <span>Delivered</span>
                    </button>
                </div>

                @if ($showDelivered)
                    {{-- A delivery is found by the day it was made: the number
                         restarts daily, so the date is the only way in. --}}
                    <div class="form-group">
                        @include('bil::partials.date-field', ['model' => 'dateIso', 'live' => true])
                    </div>

                    <div style="max-height:42rem;overflow:auto;margin:0 -0.4rem;">
                        @forelse ($this->deliveredLoads as $d)
                            <button type="button" wire:key="d-{{ $d->id }}"
                                    wire:click="openLoad('{{ $d->loadbarcode }}', {{ $d->id }})"
                                    class="btn btn-ghost"
                                    style="display:block;width:100%;text-align:left;padding:0.55rem 0.7rem;margin-bottom:0.2rem;border-radius:6px;{{ $deliveryId === (int) $d->id ? 'background:var(--accent-soft,rgba(59,130,246,.12));' : '' }}">
                                <div style="font-weight:600;font-family:monospace;">{{ $d->barcode }}</div>
                                <div class="text-sm text-muted">
                                    #{{ $d->deliverynumber }} · {{ $d->customername ?: '—' }}
                                    @if ($d->waybill) · <span class="badge badge-muted">waybill</span> @endif
                                </div>
                            </button>
                        @empty
                            <div class="text-muted" style="padding:0.8rem;">No deliveries on that date.</div>
                        @endforelse
                    </div>
                @else
                <div class="form-group">
                    <input type="search" class="form-control" placeholder="Barcode, truck, driver, customer…"
                           wire:model.live.debounce.400ms="search">
                </div>

                <div style="max-height:42rem;overflow:auto;margin:0 -0.4rem;">
                    @forelse ($this->pendingLoads as $l)
                        <button type="button" wire:key="p-{{ $l->barcode }}" wire:click="openLoad('{{ $l->barcode }}')"
                                class="btn btn-ghost"
                                style="display:block;width:100%;text-align:left;padding:0.55rem 0.7rem;margin-bottom:0.2rem;border-radius:6px;{{ $l->barcode === $barcode ? 'background:var(--accent-soft,rgba(59,130,246,.12));' : '' }}">
                            @php $age = $this->ageOf($l->dateofloading); @endphp
                            <div style="display:flex;align-items:center;gap:.4rem;">
                                <span style="font-weight:600;font-family:monospace;">{{ $l->barcode }}</span>
                                {{-- An age badge, because a load standing for
                                     months is a different job from today's: it
                                     wants closing, not sending. --}}
                                @if ($age !== null && $age > $this->staleAfter())
                                    <span class="badge" style="background:rgba(217,119,6,.14);color:#b45309;"
                                          title="Loaded {{ $l->dateofloading }} and never confirmed">{{ $age }}d</span>
                                @endif
                            </div>
                            <div class="text-sm text-muted">{{ $l->customername ?: 'No customer' }}</div>
                            <div class="text-sm text-muted">
                                {{ $l->trucknumber }} · {{ $l->line_count }} line(s) · {{ number_format($l->loaded) }} bundles
                            </div>
                        </button>
                    @empty
                        <div class="text-muted" style="padding:0.8rem;">
                            @if ($search !== '')
                                Nothing awaiting delivery matches “{{ $search }}”.
                            @else
                                Nothing waiting — every load has gone out.
                            @endif
                        </div>
                    @endforelse

                    @if ($this->hasMore())
                        <button type="button" class="btn btn-ghost btn-sm"
                                style="width:100%;margin-top:.4rem;" wire:click="showMore">
                            Show more
                        </button>
                    @endif
                </div>
                @endif
            </div>
        </div>

        {{-- ---------------- Right: the selected load ---------------- --}}
        <div>
            @if (! $load)
                <div class="card card-pad text-muted">
                    Pick a load on the left to send it out — or find one already delivered to reprint its note.
                </div>
            @else
                <div class="card" style="margin-bottom:1rem;">
                    <div class="card-head" style="flex-wrap:wrap;gap:0.75rem;">
                        <div>
                            <h2 class="card-title" style="font-family:monospace;">{{ $load->barcode }}</h2>
                            <div class="text-sm text-muted">
                                Load #{{ $load->loadnumber }} · {{ $load->customername ?: 'no customer' }}
                                · {{ $load->dateofloading }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2" style="margin-left:auto;flex-wrap:wrap;">
                            @if ($pending)
                                <span class="badge badge-success">Awaiting delivery</span>
                            @elseif ($delivery)
                                <span class="badge badge-muted" style="font-family:monospace;">{{ $delivery->barcode }}</span>
                            @else
                                <span class="badge badge-muted">Closed {{ $load->status }}</span>
                            @endif
                            <span class="badge badge-muted">{{ $totals['lines'] }} product(s)</span>
                            <span class="badge badge-muted">{{ number_format($totals['quantity']) }} bundles</span>
                        </div>
                    </div>

                    {{-- Truck and crew, read-only. They belong to the load and
                         are corrected on the Loading screen; showing them here
                         editable would be two screens owning one fact. --}}
                    <div class="card-pad">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.6rem 1.4rem;">
                            <div><div class="form-label">Transporter</div><div>{{ $load->transportername ?: '—' }}</div></div>
                            <div><div class="form-label">Truck number</div><div>{{ $load->trucknumber ?: '—' }}</div></div>
                            <div><div class="form-label">Driver</div><div>{{ $load->truckdriver ?: '—' }}</div></div>
                            <div><div class="form-label">Loader</div><div>{{ $load->loader ?: '—' }}</div></div>
                            <div><div class="form-label">Date of loading</div><div>{{ $load->dateofloading }}</div></div>
                        </div>
                    </div>
                </div>

                {{-- Tabs, scoped to this load --}}
                <div class="card">
                    <div class="card-pad" style="padding-bottom:0;">
                        <div class="flex gap-2" style="border-bottom:1px solid var(--border);">
                            @foreach (['delivery' => 'Delivery', 'print' => 'Print'] as $key => $label)
                                <button type="button" wire:click="setTab('{{ $key }}')"
                                        class="btn btn-ghost btn-sm"
                                        style="border-radius:6px 6px 0 0;margin-bottom:-1px;{{ $tab === $key ? 'border-bottom:2px solid var(--brand,#3b82f6);font-weight:600;' : '' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="card-pad" style="overflow-x:auto;">
                        {{-- ---- Delivery: what is going, and the button ---- --}}
                        @if ($tab === 'delivery')
                            <table class="data" style="width:100%;min-width:620px;">
                                <thead>
                                    <tr>
                                        <th>Product Code</th>
                                        <th>Product</th>
                                        <th style="width:130px;text-align:right;">{{ $pending ? 'Delivering' : 'Delivered' }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($lines as $line)
                                        <tr wire:key="dl-{{ $line->productid }}-{{ $loop->index }}">
                                            <td>{{ $line->productcode }}</td>
                                            <td>
                                                {{ $line->productname }}
                                                @if ($line->foc) <span class="badge" style="background:rgba(198,40,40,.14);color:#c62828;">FOC</span> @endif
                                            </td>
                                            <td style="text-align:right;">
                                                <strong>{{ number_format((int) ($line->quantityloaded ?? $line->loaded_net ?? 0)) }}</strong>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="empty-row text-muted">Nothing on this load.</td></tr>
                                    @endforelse
                                    @if (count($lines) > 1)
                                        <tr>
                                            <td colspan="2" style="text-align:right;font-weight:600;">Total</td>
                                            <td style="text-align:right;font-weight:600;">{{ number_format($totals['quantity']) }}</td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>

                            @if ($pending)
                                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:1.2rem;">
                                    <div class="text-sm text-muted">
                                        Confirming closes the load and dates the delivery
                                        <strong>{{ $load->dateofloading }}</strong> — the day it was loaded, not today.
                                    </div>
                                    @if ($this->canConfirm())
                                        <button type="button" class="btn btn-primary" style="margin-left:auto;"
                                                wire:click="confirmDelivery" wire:loading.attr="disabled">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                            Confirm delivery
                                        </button>
                                    @endif
                                </div>
                            @elseif ($delivery)
                                <div class="card" style="background:var(--surface-2);padding:.8rem 1rem;margin-top:1.2rem;">
                                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.6rem 1.4rem;">
                                        <div><div class="form-label">Delivery number</div><div>{{ $delivery->deliverynumber }}</div></div>
                                        <div><div class="form-label">Delivery barcode</div><div style="font-family:monospace;">{{ $delivery->barcode }}</div></div>
                                        <div><div class="form-label">Date of delivery</div><div>{{ $delivery->dateofdelivery }}</div></div>
                                        <div><div class="form-label">Confirmed by</div><div>{{ $delivery->username }}</div></div>
                                        <div><div class="form-label">Waybill</div>
                                            <div>
                                                @if ($delivery->waybill)
                                                    <span style="font-family:monospace;">{{ $delivery->waybill }}</span>
                                                @else
                                                    <span class="text-muted">Not raised</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    <div style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;margin-top:.9rem;">
                                        <a href="{{ $this->printUrlFor($delivery->barcode) }}" target="_blank" rel="noopener"
                                           class="btn btn-ghost btn-sm">Print delivery note</a>

                                        @if ($this->canUndo())
                                            @if ($delivery->waybill)
                                                <span class="text-sm text-muted" style="margin-left:auto;">
                                                    A waybill has been raised — this delivery can no longer be undone.
                                                </span>
                                            @elseif ($confirmingUndo)
                                                <span class="text-sm" style="margin-left:auto;">
                                                    Undo this delivery and put the load back on the floor?
                                                </span>
                                                <button type="button" class="btn btn-danger btn-sm" wire:click="undoDelivery">Yes, undo</button>
                                                <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmingUndo', false)">No</button>
                                            @else
                                                <button type="button" class="btn btn-ghost btn-sm" style="margin-left:auto;"
                                                        wire:click="$set('confirmingUndo', true)">Undo delivery</button>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @else
                                <div class="text-muted" style="margin-top:1rem;">
                                    This load was closed on {{ $load->status }} but carries no delivery record.
                                </div>
                            @endif

                        {{-- ---- Print ---- --}}
                        @else
                            <div class="flex" style="justify-content:flex-end;margin-bottom:.7rem;">
                                @if ($delivery)
                                    <a href="{{ $this->printUrlFor($delivery->barcode) }}" target="_blank" rel="noopener"
                                       class="btn btn-ghost btn-sm">Print delivery note</a>
                                @endif
                            </div>

                            @unless ($delivery)
                                <div class="text-muted" style="margin-bottom:.7rem;">
                                    There is no note to print until the delivery is confirmed. This is what it will say.
                                </div>
                            @endunless

                            <table class="data" style="width:100%;min-width:620px;">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">S/N</th>
                                        <th>Product Code</th>
                                        <th>Product</th>
                                        <th style="width:110px;text-align:right;">Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $n => $line)
                                        <tr wire:key="pv-{{ $line->productid }}-{{ $n }}">
                                            <td>{{ $n + 1 }}</td>
                                            <td>{{ $line->productcode }}</td>
                                            <td>
                                                {{ $line->productname }}
                                                @if ($line->foc) <span style="color:#c62828;font-weight:600;">(FOC)</span> @endif
                                            </td>
                                            <td style="text-align:right;">{{ number_format((int) ($line->quantityloaded ?? $line->loaded_net ?? 0)) }}</td>
                                        </tr>
                                    @endforeach
                                    @if (count($lines) > 1)
                                        <tr>
                                            <td colspan="3" style="text-align:right;font-weight:600;">Total</td>
                                            <td style="text-align:right;font-weight:600;">{{ number_format($totals['quantity']) }}</td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    @include('bil::partials.delivery-printouts')
</div>
