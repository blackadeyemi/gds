{{--
    Waybill — the last step of the sales chain, and the thinnest.

    The delivery already says what went and to whom. This adds two figures: the
    receipt number and what the haulier is paid. The queue is scoped to a date,
    unlike Loading and Delivery, because most deliveries never get a waybill and
    listing them all would present 74,692 normal end states as work outstanding.
--}}
@php
    $delivery = $this->delivery;
    $waybill = $this->waybill;
    $lines = $this->lines;
    $totals = $this->totals();
    $counts = $this->counts;
@endphp

<div>
    <div class="page-head" style="display:flex;align-items:flex-start;gap:1rem;">
        <div>
            <h1>Waybill</h1>
            <p>What the haulier is paid for taking a delivery out. A delivery on the customer’s own truck needs none — most never get one.</p>
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

    <div style="display:grid;grid-template-columns:minmax(300px,380px) 1fr;gap:1rem;align-items:start;">

        {{-- ---------------- The queue ---------------- --}}
        <div class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Deliveries</h2>
                    <div class="text-sm text-muted">
                        {{ $counts['awaiting'] }} of {{ $counts['deliveries'] }} on this date want a waybill
                    </div>
                </div>
            </div>

            <div class="card-pad">
                <div class="form-group">
                    <label class="form-label">Date of delivery</label>
                    @include('bil::partials.date-field', ['model' => 'dateIso', 'live' => true])
                </div>

                <div class="form-group">
                    <input type="search" class="form-control" placeholder="Barcode, customer, truck, transporter…"
                           wire:model.live.debounce.400ms="search">
                </div>

                <label class="flex items-center gap-2" style="cursor:pointer;font-size:0.9rem;margin-bottom:.6rem;">
                    <input type="checkbox" wire:model.live="awaitingOnly">
                    <span>Only those without a waybill</span>
                </label>

                <div style="max-height:36rem;overflow:auto;margin:0 -0.4rem;">
                    @forelse ($this->deliveries as $d)
                        <button type="button" wire:key="d-{{ $d->id }}" wire:click="openDelivery('{{ $d->barcode }}')"
                                class="btn btn-ghost"
                                style="display:block;width:100%;text-align:left;padding:0.55rem 0.7rem;margin-bottom:0.2rem;border-radius:6px;{{ $d->barcode === $barcode ? 'background:var(--accent-soft,rgba(59,130,246,.12));' : '' }}">
                            <div style="display:flex;align-items:center;gap:.4rem;">
                                <span style="font-weight:600;font-family:monospace;">{{ $d->barcode }}</span>
                                @if ($d->waybill)
                                    <span class="badge badge-muted" title="Waybill {{ $d->waybill }}">waybilled</span>
                                @endif
                            </div>
                            <div class="text-sm text-muted">{{ $d->customername ?: 'No customer' }}</div>
                            <div class="text-sm text-muted">
                                {{ $d->trucknumber ?: '—' }} · {{ $d->transportername ?: 'no transporter' }}
                            </div>
                        </button>
                    @empty
                        <div class="text-muted" style="padding:0.8rem;">
                            @if ($search !== '')
                                Nothing on this date matches “{{ $search }}”.
                            @elseif ($awaitingOnly && $counts['deliveries'] > 0)
                                Every delivery on this date has its waybill.
                            @else
                                No deliveries on this date.
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
            </div>
        </div>

        {{-- ---------------- Right ---------------- --}}
        <div>
            @if (! $delivery)
                <div class="card card-pad text-muted">
                    Pick a delivery on the left to raise its waybill, or to reprint one already raised.
                </div>
            @else
                <div class="card" style="margin-bottom:1rem;">
                    <div class="card-head" style="flex-wrap:wrap;gap:0.75rem;">
                        <div>
                            <h2 class="card-title" style="font-family:monospace;">
                                {{ $waybill->barcode ?? $delivery->barcode }}
                            </h2>
                            <div class="text-sm text-muted">
                                Delivery #{{ $delivery->deliverynumber }} ·
                                {{ $delivery->customername ?: 'no customer' }} · {{ $delivery->dateofdelivery }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2" style="margin-left:auto;flex-wrap:wrap;">
                            @if ($waybill)
                                <span class="badge badge-muted">Waybill raised</span>
                            @else
                                <span class="badge badge-success">Awaiting waybill</span>
                            @endif
                            <span class="badge badge-muted">{{ $totals['lines'] }} product(s)</span>
                            <span class="badge badge-muted">{{ number_format($totals['quantity']) }} bundles</span>
                        </div>
                    </div>

                    <div class="card-pad">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.6rem 1.4rem;">
                            <div><div class="form-label">Delivery barcode</div><div style="font-family:monospace;">{{ $delivery->barcode }}</div></div>
                            <div><div class="form-label">Loading barcode</div><div style="font-family:monospace;">{{ $delivery->loadbarcode ?: '—' }}</div></div>
                            <div><div class="form-label">Transporter</div><div>{{ $delivery->transportername ?: '—' }}</div></div>
                            <div><div class="form-label">Truck number</div><div>{{ $delivery->trucknumber ?: '—' }}</div></div>
                            <div><div class="form-label">Driver</div><div>{{ $delivery->truckdriver ?: '—' }}</div></div>
                            <div><div class="form-label">Loader</div><div>{{ $delivery->loader ?: '—' }}</div></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-pad" style="padding-bottom:0;">
                        <div class="flex gap-2" style="border-bottom:1px solid var(--border);">
                            @foreach (['waybill' => 'Waybill', 'print' => 'Print'] as $key => $label)
                                <button type="button" wire:click="setTab('{{ $key }}')"
                                        class="btn btn-ghost btn-sm"
                                        style="border-radius:6px 6px 0 0;margin-bottom:-1px;{{ $tab === $key ? 'border-bottom:2px solid var(--brand,#3b82f6);font-weight:600;' : '' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="card-pad" style="overflow-x:auto;">
                        @if ($tab === 'waybill')
                            {{-- The two figures the waybill exists to record.
                                 Everything else on this screen is the delivery
                                 repeated back so the operator can check it. --}}
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;max-width:560px;">
                                <div class="form-group">
                                    <label class="form-label">Receipt number</label>
                                    <input type="number" class="form-control" style="text-align:right;"
                                           wire:model="receiptnumber" placeholder="optional"
                                           @disabled($waybill ? ! $this->canModify() : ! $this->canCreate())>
                                    @error('receiptnumber') <div class="form-error">{{ $message }}</div> @enderror
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Transport cost (₦)</label>
                                    <input type="number" min="0" step="0.01" class="form-control" style="text-align:right;"
                                           wire:model="transportcost"
                                           @disabled($waybill ? ! $this->canModify() : ! $this->canCreate())>
                                    @error('transportcost') <div class="form-error">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            @if (! $waybill)
                                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:1rem;">
                                    <div class="text-sm text-muted">
                                        The waybill is dated <strong>{{ $delivery->dateofdelivery }}</strong> — the
                                        delivery’s day, not today, because that pair is how the two are joined.
                                    </div>
                                    @if ($this->canCreate())
                                        <button type="button" class="btn btn-primary" style="margin-left:auto;"
                                                wire:click="raise" wire:loading.attr="disabled">
                                            Raise waybill
                                        </button>
                                    @endif
                                </div>
                            @else
                                <div class="card" style="background:var(--surface-2);padding:.8rem 1rem;margin-top:1rem;">
                                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.6rem 1.4rem;">
                                        <div><div class="form-label">Waybill barcode</div><div style="font-family:monospace;">{{ $waybill->barcode }}</div></div>
                                        <div><div class="form-label">Date of waybill</div><div>{{ $waybill->dateofwaybill }}</div></div>
                                        <div><div class="form-label">Raised by</div><div>{{ $waybill->username }}</div></div>
                                        <div><div class="form-label">Transport cost</div><div>₦{{ number_format($waybill->transportcost, 2) }}</div></div>
                                        <div><div class="form-label">Receipt number</div>
                                            <div>{{ $waybill->receiptnumber ?? '—' }}</div></div>
                                    </div>

                                    <div style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;margin-top:.9rem;">
                                        <a href="{{ $this->printUrlFor($waybill->barcode) }}" target="_blank" rel="noopener"
                                           class="btn btn-ghost btn-sm">Print waybill</a>

                                        @if ($this->canModify())
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="saveFigures">
                                                Save figures
                                            </button>
                                        @endif

                                        @if ($this->canDelete())
                                            @if ($confirmingRemove)
                                                <span class="text-sm" style="margin-left:auto;">
                                                    Remove this waybill? It also re-opens the delivery for undo.
                                                </span>
                                                <button type="button" class="btn btn-danger btn-sm" wire:click="removeWaybill">Yes, remove</button>
                                                <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmingRemove', false)">No</button>
                                            @else
                                                <button type="button" class="btn btn-ghost btn-sm" style="margin-left:auto;"
                                                        wire:click="$set('confirmingRemove', true)">Remove waybill</button>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @endif

                        @else
                            <div class="flex" style="justify-content:flex-end;margin-bottom:.7rem;">
                                @if ($waybill)
                                    <a href="{{ $this->printUrlFor($waybill->barcode) }}" target="_blank" rel="noopener"
                                       class="btn btn-ghost btn-sm">Print waybill</a>
                                @endif
                            </div>

                            @unless ($waybill)
                                <div class="text-muted" style="margin-bottom:.7rem;">
                                    There is nothing to print until the waybill is raised. This is what it will carry.
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
                                        <tr wire:key="wl-{{ $line->productid }}-{{ $n }}">
                                            <td>{{ $n + 1 }}</td>
                                            <td>{{ $line->productcode }}</td>
                                            <td>
                                                {{ $line->productname }}
                                                @if ($line->foc) <span style="color:#c62828;font-weight:600;">(FOC)</span> @endif
                                            </td>
                                            <td style="text-align:right;">{{ number_format((int) $line->quantityloaded) }}</td>
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

    @include('bil::partials.waybill-printouts')
</div>
