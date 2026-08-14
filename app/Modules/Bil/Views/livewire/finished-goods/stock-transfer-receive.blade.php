{{--
    Receive Transfer — count in what arrived.

    The left column is a queue of trucks in transit, oldest first. Counts default
    to what was sent; a short delivery is typed in deliberately and recorded as a
    shortfall rather than absorbed.
--}}
@php
    $transfer = $this->transfer;
    $pending = $this->pending;
@endphp

<div>
    <div class="page-head">
        <h1>Receive Transfer</h1>
        <p>Count in stock arriving from another warehouse. Bundles only count at this warehouse once received — until then they are <strong>in transit</strong>.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    <div style="display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:1rem;align-items:start;">

        {{-- ---------------- In transit ---------------- --}}
        <div class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">In transit</h2>
                    <div class="text-sm text-muted">{{ $pending->count() }} truck(s) — oldest first</div>
                </div>
            </div>

            <div class="card-pad">
                <div class="form-group">
                    <label class="form-label">Destination</label>
                    @include('core::partials.searchable-select', [
                        'field' => 'filterWarehouse',
                        'options' => $this->warehouseOptions,
                        'valueKey' => 'value',
                        'labelKey' => 'label',
                        'placeholder' => 'All destinations',
                        'live' => true,
                    ])
                </div>

                <div style="max-height:28rem;overflow:auto;margin:0 -0.4rem;">
                    @forelse ($pending as $t)
                        <button type="button" wire:key="t-{{ $t->id }}" wire:click="openTransfer({{ $t->id }})"
                                class="btn btn-ghost"
                                style="display:block;width:100%;text-align:left;padding:0.55rem 0.7rem;margin-bottom:0.2rem;border-radius:6px;{{ $t->id === $openId ? 'background:var(--accent-soft,rgba(59,130,246,.12));' : '' }}">
                            <div style="font-weight:600;">
                                {{ $t->fromWarehouse?->name ?? 'Unknown' }} → {{ $t->toWarehouse?->name ?? 'Unknown' }}
                            </div>
                            <div class="text-sm text-muted">
                                #{{ $t->transfer_number }}
                                @if ($t->truck_number) · {{ $t->truck_number }} @endif
                            </div>
                            <div class="text-sm text-muted">
                                {{ $t->date_of_transfer?->format('d/m/Y') }}
                                · {{ number_format($t->totalBundles()) }} bundles
                                @if ($t->isInterCompany())
                                    · <span class="badge" style="background:rgba(217,119,6,.14);color:#b45309;">Inter-company</span>
                                @endif
                            </div>
                        </button>
                    @empty
                        <div class="text-muted" style="padding:0.8rem;">
                            Nothing in transit — every dispatched truck has been received.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ---------------- The open transfer ---------------- --}}
        <div>
            @if (! $transfer)
                <div class="card card-pad text-muted">Select a truck on the left to count it in.</div>
            @else
                <div class="card" style="margin-bottom:1rem;">
                    <div class="card-head" style="flex-wrap:wrap;gap:0.75rem;">
                        <div>
                            <h2 class="card-title">{{ $transfer->fromWarehouse?->name }} → {{ $transfer->toWarehouse?->name }}</h2>
                            <div class="text-sm text-muted">
                                Transfer #{{ $transfer->transfer_number }}
                                @if ($transfer->truck_number) · truck {{ $transfer->truck_number }} @endif
                                · dispatched {{ $transfer->dispatched_at?->format('d/m/Y H:i') }}
                                @if ($transfer->dispatched_by_name) by {{ $transfer->dispatched_by_name }} @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2" style="margin-left:auto;flex-wrap:wrap;">
                            @if ($transfer->isInterCompany())
                                <span class="badge" style="background:rgba(217,119,6,.14);color:#b45309;">Inter-company</span>
                            @else
                                <span class="badge badge-success">Internal</span>
                            @endif
                            <span class="badge badge-muted">{{ number_format($transfer->totalBundles()) }} bundles sent</span>
                        </div>
                    </div>

                    <div class="card-pad" style="overflow-x:auto;">
                        <table class="data" style="width:100%;min-width:560px;">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th style="width:120px;text-align:right;">Sent</th>
                                    <th style="width:150px;">Received</th>
                                    <th style="width:120px;text-align:right;">Short</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transfer->lines as $line)
                                    @php $counted = (int) ($counts[$line->id] ?? $line->bundles); @endphp
                                    <tr wire:key="line-{{ $line->id }}">
                                        <td>
                                            <div>{{ $line->product_name }}</div>
                                            <div class="text-sm text-muted">{{ $line->product_code }}</div>
                                        </td>
                                        <td style="text-align:right;">{{ number_format($line->bundles) }}</td>
                                        <td>
                                            <input type="number" min="0" max="{{ $line->bundles }}" step="1"
                                                   class="form-control" style="text-align:right;"
                                                   wire:model.live.debounce.400ms="counts.{{ $line->id }}"
                                                   @disabled(! $transfer->inTransit())>
                                            @error('counts.' . $line->id) <div class="form-error">{{ $message }}</div> @enderror
                                        </td>
                                        <td style="text-align:right;">
                                            @php $short = $line->bundles - $counted; @endphp
                                            @if ($short > 0)
                                                <span class="form-error">{{ number_format($short) }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card" style="padding:1rem 1.4rem;">
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label">Note <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control" rows="2" wire:model="note"
                                  placeholder="Anything worth recording about this delivery"></textarea>
                    </div>

                    <div class="flex" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;">
                        <div>
                            @if ($transfer->inTransit() && $this->canCancel())
                                @if ($confirmingCancel)
                                    <span class="text-sm" style="margin-right:.5rem;">Put the bundles back on {{ $transfer->fromWarehouse?->name }}?</span>
                                    <button type="button" class="btn btn-danger btn-sm" wire:click="cancel">Yes, cancel it</button>
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmingCancel', false)">No</button>
                                @else
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmingCancel', true)">
                                        Cancel this transfer
                                    </button>
                                @endif
                            @endif
                        </div>

                        @if ($transfer->inTransit())
                            <button type="button" class="btn btn-primary" wire:click="receive">Receive into {{ $transfer->toWarehouse?->name }}</button>
                        @else
                            <span class="badge badge-success">Received {{ $transfer->received_at?->format('d/m/Y H:i') }}</span>
                        @endif
                    </div>
                </div>
            @endif

            {{-- ---------------- Recently received ---------------- --}}
            @if ($this->recent->isNotEmpty())
                <div class="card" style="margin-top:1rem;">
                    <div class="card-head">
                        <div>
                            <h2 class="card-title">Recently received</h2>
                            <div class="text-sm text-muted">Sign off, or open one to check what arrived.</div>
                        </div>
                    </div>
                    <div class="card-pad" style="overflow-x:auto;">
                        <table class="data" style="width:100%;min-width:640px;">
                            <thead>
                                <tr>
                                    <th>Transfer</th>
                                    <th>Route</th>
                                    <th style="width:110px;text-align:right;">Bundles</th>
                                    <th style="width:100px;text-align:right;">Short</th>
                                    <th style="width:150px;">Received</th>
                                    <th style="width:140px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->recent as $t)
                                    <tr wire:key="r-{{ $t->id }}">
                                        <td>#{{ $t->transfer_number }}</td>
                                        <td class="text-sm">{{ $t->fromWarehouse?->name }} → {{ $t->toWarehouse?->name }}</td>
                                        <td style="text-align:right;">{{ number_format($t->totalReceived()) }}</td>
                                        <td style="text-align:right;">
                                            @if ($t->shortfall() > 0)
                                                <span class="form-error">{{ number_format($t->shortfall()) }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-sm">
                                            {{ $t->received_at?->format('d/m/Y H:i') }}
                                            <div class="text-muted">{{ $t->received_by_name }}</div>
                                        </td>
                                        <td style="text-align:right;">
                                            @if ($t->isApproved())
                                                <span class="badge badge-success" title="{{ $t->approved_by_name }}">Approved</span>
                                            @elseif ($this->canApprove())
                                                <button type="button" class="btn btn-ghost btn-sm" wire:click="approve({{ $t->id }})">Approve</button>
                                            @else
                                                <span class="badge badge-muted">Awaiting approval</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
