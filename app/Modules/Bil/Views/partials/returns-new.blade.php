{{--
    New Return — the one screen in the sales chain that does not start from a
    document.

    Customer → product → how many → which delivery it comes off. The last step
    is the interesting one: `sales_return.sod_id` is a sales-order-detail id, so
    a return HAS to be booked against a specific delivery, and nothing on the
    returned goods says which. So every delivery of that product to that
    customer is listed, newest first, with what is still returnable on each.

    Measured before building: across 366 return lines since 2024 a single
    delivery always had enough left on it. The split box is for the day that is
    not true — refusing to record a return that physically happened would be
    worse than a second row.
--}}
@php
    $eligible = $this->eligible;
    $default = $this->defaultLine;
    $needsSplit = $this->needsSplit();
    $basketTotals = $this->basketTotals();
@endphp

<div class="card" style="margin-bottom:1rem;">
    <div class="card-head">
        <div>
            <h2 class="card-title">New Return</h2>
            <div class="text-sm text-muted">Say who sent it back, then add each product coming in.</div>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" style="margin-left:auto;" wire:click="cancelNew">Cancel</button>
    </div>

    <div class="card-pad">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;">
            <div class="form-group">
                <label class="form-label">Customer</label>
                @include('core::partials.searchable-select', [
                    'field' => 'customerid',
                    'options' => $this->customerOptions,
                    'valueKey' => 'value', 'labelKey' => 'label',
                    'placeholder' => '— Select customer —',
                    'live' => true,
                ])
                @error('customerid') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Date of return</label>
                @include('bil::partials.date-field', ['model' => 'dateIso'])
            </div>
        </div>
    </div>
</div>

@if ($customerid !== '')
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-head">
            <div>
                <h2 class="card-title">What is coming back</h2>
                <div class="text-sm text-muted">
                    Only products actually delivered to this customer in the last year are offered.
                </div>
            </div>
        </div>

        <div class="card-pad">
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:0.75rem;align-items:start;">
                <div class="form-group">
                    <label class="form-label">Product</label>
                    @include('core::partials.searchable-select', [
                        'field' => 'productid',
                        'options' => $this->productOptions,
                        'valueKey' => 'value', 'labelKey' => 'label',
                        'placeholder' => $this->productOptions === [] ? '— nothing delivered —' : '— Select product —',
                        'live' => true,
                        'key' => 'prod-' . $customerid,
                    ])
                    @error('productid') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Quantity returned</label>
                    <input type="number" min="1" class="form-control" style="text-align:right;"
                           wire:model.live.debounce.400ms="quantity" @disabled($productid === '')>
                    @error('quantity') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Of which rejected</label>
                    <input type="number" min="0" class="form-control" style="text-align:right;"
                           wire:model="rejected" placeholder="0" @disabled($productid === '')>
                    @error('rejected') <div class="form-error">{{ $message }}</div> @enderror
                </div>
            </div>

            @if ($productid !== '')
                @if ($eligible === [])
                    <div class="text-muted" style="margin-top:.5rem;">
                        Nothing of this product is left returnable — everything delivered has already come back.
                    </div>
                @else
                    @php
                        $want = (int) $quantity;
                        $totalLeft = $this->totalEligible();
                        $tooMany = $want > $totalLeft;
                        $ready = $want > 0 && ! $tooMany;
                    @endphp

                    {{-- WHAT WILL HAPPEN, AND THE BUTTON THAT DOES IT — both
                         directly under the fields that decide them.

                         These used to sit under the deliveries table, which
                         meant scrolling past reference data to find the action
                         and then scrolling back to check what it would do. The
                         table below is for overriding the choice, which is the
                         rarer job, so it comes second. --}}
                    <div class="card" style="background:var(--surface-2);padding:.75rem 1rem;margin:.2rem 0 1rem;">
                        <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                            <div style="flex:1;min-width:240px;">
                                @if ($want <= 0)
                                    <div class="text-sm text-muted">
                                        Enter a quantity to see which delivery it will come off.
                                    </div>
                                @elseif ($tooMany)
                                    <div class="text-sm" style="color:#c62828;font-weight:600;">
                                        Only {{ number_format($totalLeft) }} bundles of this product were delivered
                                        to this customer and not already returned.
                                    </div>
                                @elseif ($needsSplit || $split)
                                    <div class="text-sm" style="font-weight:600;">
                                        @if ($needsSplit)
                                            <span style="color:#b45309;">No single delivery has
                                            {{ number_format($want) }} left</span> — it will be split:
                                        @else
                                            Will be split across deliveries, newest first:
                                        @endif
                                    </div>
                                    <ul class="text-sm text-muted" style="margin:.3rem 0 0 1.1rem;">
                                        @foreach ($this->splitPlan as $step)
                                            <li>{{ number_format($step['quantity']) }} off
                                                <strong>{{ $step['line']->orderid }}</strong>
                                                ({{ $step['line']->last_delivery ?: $step['line']->dateoforder }})</li>
                                        @endforeach
                                    </ul>
                                @elseif ($default)
                                    <div class="text-sm">
                                        Will be booked against <strong>{{ $default->orderid }}</strong>,
                                        delivered {{ $default->last_delivery ?: $default->dateoforder }}.
                                    </div>
                                @endif
                            </div>

                            <button type="button" class="btn btn-primary" style="flex:none;"
                                    wire:click="addLine" @disabled(! $ready)>
                                Add to return
                            </button>
                        </div>

                        @unless ($needsSplit)
                            <label class="flex items-center gap-2"
                                   style="cursor:pointer;font-size:.9rem;margin-top:.6rem;">
                                <input type="checkbox" wire:model.live="split">
                                <span>Split this return across more than one delivery</span>
                            </label>
                        @endunless
                    </div>

                    <div class="text-sm text-muted" style="margin:0 0 .5rem;">
                        <strong>{{ number_format($totalLeft) }}</strong> bundles delivered and not yet
                        returned, across {{ count($eligible) }} deliver{{ count($eligible) === 1 ? 'y' : 'ies' }}.
                        Pick a different one below to override. Rejected is part of the quantity returned,
                        not extra to it.
                    </div>

                    {{-- The deliveries. Booking a return needs one of these,
                         because the table has no other handle on the goods. --}}
                    <table class="data" style="width:100%;min-width:680px;">
                        <thead>
                            <tr>
                                <th style="width:44px;"></th>
                                <th>Sales order</th>
                                {{-- Two columns once both read "Delivered" —
                                     one a date, one a quantity. --}}
                                <th style="width:120px;">Ordered on</th>
                                <th style="width:120px;">Delivered on</th>
                                <th style="width:110px;text-align:right;">Delivered</th>
                                <th style="width:110px;text-align:right;">Returned</th>
                                <th style="width:110px;text-align:right;">Left</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($eligible as $line)
                                @php
                                    $covers = (int) $quantity > 0 && $line->remaining >= (int) $quantity;
                                    $chosen = $default && (int) $default->id === (int) $line->id;
                                @endphp
                                <tr wire:key="el-{{ $line->id }}"
                                    style="{{ $chosen && ! $split ? 'background:var(--accent-soft,rgba(59,130,246,.12));' : '' }}">
                                    <td style="text-align:center;">
                                        <input type="radio" name="sodpick" value="{{ $line->id }}"
                                               wire:model.live="sodId"
                                               @checked($chosen) @disabled($split || $needsSplit)>
                                    </td>
                                    <td>
                                        {{ $line->orderid }}
                                        @if ($line->foc)
                                            <span class="badge" style="background:rgba(198,40,40,.14);color:#c62828;">FOC</span>
                                        @endif
                                    </td>
                                    <td class="text-sm text-muted">{{ $line->dateoforder }}</td>
                                    <td class="text-sm text-muted">{{ $line->last_delivery ?: '—' }}</td>
                                    <td style="text-align:right;">{{ number_format($line->delivered) }}</td>
                                    <td style="text-align:right;">
                                        {{ $line->returned > 0 ? number_format($line->returned) : '—' }}
                                    </td>
                                    <td style="text-align:right;">
                                        <strong>{{ number_format($line->remaining) }}</strong>
                                        @if ($covers)
                                            <span class="badge badge-success" title="Can cover the whole return">✓</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                @endif
            @endif
        </div>
    </div>
@endif

{{-- The basket: the sheet being built. A return number is per customer per
     day, so everything they sent back in one go belongs on one. --}}
@if ($basket !== [])
    <div class="card">
        <div class="card-head">
            <div>
                <h2 class="card-title">On this return</h2>
                <div class="text-sm text-muted">
                    {{ $basketTotals['lines'] }} line(s) ·
                    {{ number_format($basketTotals['returned'] - $basketTotals['rejected']) }} back to stock
                    @if ($basketTotals['rejected'] > 0)
                        · {{ number_format($basketTotals['rejected']) }} rejected
                    @endif
                </div>
            </div>
        </div>

        <div class="card-pad" style="overflow-x:auto;">
            <table class="data" style="width:100%;min-width:680px;">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Booked against</th>
                        <th style="width:110px;text-align:right;">Returned</th>
                        <th style="width:110px;text-align:right;">Rejected</th>
                        <th style="width:90px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($basket as $i => $row)
                        <tr wire:key="bk-{{ $i }}">
                            <td>{{ $row['productname'] }}</td>
                            <td class="text-sm text-muted">
                                {{ $row['orderid'] }}
                                @if ($row['last_delivery']) · delivered {{ $row['last_delivery'] }} @endif
                            </td>
                            <td style="text-align:right;">{{ number_format($row['returned']) }}</td>
                            <td style="text-align:right;">
                                {{ $row['rejected'] > 0 ? number_format($row['rejected']) : '—' }}
                            </td>
                            <td style="text-align:right;">
                                <button type="button" class="btn btn-ghost btn-sm"
                                        wire:click="removeFromBasket({{ $i }})">Remove</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="flex" style="justify-content:flex-end;margin-top:1rem;">
                <button type="button" class="btn btn-primary" wire:click="saveNew">Record return</button>
            </div>
        </div>
    </div>
@endif
