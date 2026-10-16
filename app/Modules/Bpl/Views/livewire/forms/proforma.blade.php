@php
    $lines = $this->lines;
    $goods = $this->goodsValue();
    $freightCharge = $this->freightCharge();
    $symbol = $this->currencies->firstWhere('id', $currency_id)->code ?? '';
@endphp

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    {{-- ---------------- The document ---------------- --}}
    <div>
        <div class="form-group">
            <label class="form-label">Sales Order</label>
            @if ($editingId)
                @php $order = $this->openOrders->firstWhere('id', $order_id); @endphp
                <input type="text" class="form-control mono" readonly
                       value="{{ $order->orderno ?? ('order ' . $order_id) }}"
                       style="background:var(--surface-2);color:var(--muted);cursor:default;">
                <div class="form-hint">{{ $order->customername ?? '' }} · a proforma belongs to its order.</div>
            @else
                @include('bil::partials.combobox', [
                    'model' => 'order_id',
                    'labelText' => 'Sales Order',
                    'bare' => true,
                    'placeholder' => 'Search order…',
                    'items' => $this->openOrders->map(fn ($o) => [
                        'value' => $o->id,
                        'label' => ($o->orderno ?: 'ref ' . $o->ref) . ' · ' . ($o->customername ?: '—') . ' · ' . $o->date,
                    ]),
                ])
                <div class="form-hint">Orders with no proforma yet.</div>
            @endif
            @error('order_id') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
            <div class="form-group">
                <label class="form-label">Date</label>
                @include('bil::partials.date-field', ['model' => 'dateIso'])
                @error('dateIso') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Customer Ref</label>
                <input type="text" class="form-control" wire:model="customer_ref" maxlength="50" autocomplete="off">
                @error('customer_ref') <div class="form-error">{{ $message }}</div> @enderror
                <div class="form-hint">Printed after the invoice number.</div>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Freight</label>
            <select class="form-control" wire:model="freight">
                <option value="">— Choose —</option>
                @foreach (\Modules\Bpl\Models\BplProforma::FREIGHTS as $f)
                    <option value="{{ $f }}">{{ $f }}</option>
                @endforeach
            </select>
            @error('freight') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        <div style="display:grid;grid-template-columns:110px 1fr;gap:0.75rem;">
            <div class="form-group">
                <label class="form-label">Containers</label>
                <input type="number" class="form-control qty-input" wire:model.live.debounce.400ms="container" min="1" max="99" step="1">
                @error('container') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Freight Price per Container</label>
                <input type="number" class="form-control qty-input" wire:model.live.debounce.400ms="freight_price" min="0" step="0.01">
                @error('freight_price') <div class="form-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
            <div class="form-group">
                <label class="form-label">Terms of Delivery</label>
                <select class="form-control" wire:model="terms">
                    <option value="">— Choose —</option>
                    @foreach (\Modules\Bpl\Models\BplProforma::TERMS as $t)
                        <option value="{{ $t }}">{{ $t }}</option>
                    @endforeach
                </select>
                @error('terms') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Shipment</label>
                <select class="form-control" wire:model="shipment">
                    <option value="">— Choose —</option>
                    @foreach (\Modules\Bpl\Models\BplProforma::SHIPMENTS as $s)
                        <option value="{{ $s }}">{{ $s }}</option>
                    @endforeach
                </select>
                @error('shipment') <div class="form-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Payment Term</label>
            <select class="form-control" wire:model="payment_term_id">
                <option value="">— Choose —</option>
                @foreach ($this->paymentTerms as $t)
                    <option value="{{ $t->id }}">{{ $t->payment_terms }}</option>
                @endforeach
            </select>
            @error('payment_term_id') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        <div style="display:grid;grid-template-columns:130px 1fr;gap:0.75rem;">
            <div class="form-group">
                <label class="form-label">Currency</label>
                <select class="form-control" wire:model.live="currency_id">
                    <option value="">—</option>
                    @foreach ($this->currencies as $c)
                        <option value="{{ $c->id }}">{{ $c->code }}</option>
                    @endforeach
                </select>
                @error('currency_id') <div class="form-error">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Receiving Account</label>
                <select class="form-control" wire:model="account_id" wire:key="pfacc-{{ $currency_id ?: 'all' }}">
                    <option value="">— Choose —</option>
                    @foreach ($this->accounts as $a)
                        <option value="{{ $a->id }}">
                            {{ $a->bank }}{{ $a->number ? ' (' . $a->number . ')' : '' }}{{ $a->currency ? ' · ' . $a->currency : '' }}
                        </option>
                    @endforeach
                </select>
                @error('account_id') <div class="form-error">{{ $message }}</div> @enderror
                <div class="form-hint">
                    @if ($currency_id)
                        Accounts held in this currency (and any with none set). Its bank details are printed on the sheet.
                    @else
                        Choose a currency first and only the accounts it can be paid into are offered.
                    @endif
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">NXP Number</label>
            <input type="text" class="form-control mono" wire:model="nxp" maxlength="100" autocomplete="off"
                   placeholder="e.g. XG-2026-00057-172563">
            @error('nxp') <div class="form-error">{{ $message }}</div> @enderror
            <div class="form-hint">Added when the export form comes back; leave empty until then.</div>
        </div>
    </div>

    {{-- ---------------- The prices ---------------- --}}
    <div>
        <div class="text-sm" style="font-weight:600;margin-bottom:.5rem;">Prices</div>

        @if (! $order_id)
            <div class="card card-pad text-muted text-sm" style="background:var(--surface-2);">
                Choose a sales order and its lines appear here to be priced.
            </div>
        @elseif ($lines === [])
            <div class="card card-pad text-sm" style="border-color:var(--danger);color:var(--danger);">
                That order has no products on it yet.
            </div>
        @else
            <div class="table-wrap">
                <table class="data" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th style="width:90px;text-align:right;">Qty/MT</th>
                            <th style="width:130px;text-align:right;">Unit Price</th>
                            <th style="width:120px;text-align:right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            @php
                                $price = (float) ($prices[$line['order_item_id']] ?? 0);
                                $amount = round($price * $line['weight'] / 1000, 2);
                            @endphp
                            <tr wire:key="pfl-{{ $line['order_item_id'] }}">
                                <td class="text-sm">{{ $line['fullname'] }}</td>
                                <td style="text-align:right;">{{ number_format($line['tonnes'], 3) }}</td>
                                <td>
                                    <input type="number" class="form-control qty-input"
                                           wire:model.live.debounce.500ms="prices.{{ $line['order_item_id'] }}"
                                           min="0.01" step="0.01" placeholder="0.00">
                                    @error('prices.' . $line['order_item_id'])
                                        <div class="form-error">{{ $message }}</div>
                                    @enderror
                                </td>
                                <td style="text-align:right;">{{ $amount > 0 ? number_format($amount, 2) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3" style="text-align:right;">Goods</th>
                            <th style="text-align:right;">{{ number_format($goods, 2) }}</th>
                        </tr>
                        <tr>
                            <th colspan="3" style="text-align:right;">
                                Freight ({{ (int) $container }} &times; {{ number_format((float) $freight_price, 2) }})
                            </th>
                            <th style="text-align:right;">{{ number_format($freightCharge, 2) }}</th>
                        </tr>
                        <tr>
                            <th colspan="3" style="text-align:right;">Total {{ $symbol }}</th>
                            <th style="text-align:right;">{{ number_format($goods + $freightCharge, 2) }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="form-hint" style="margin-top:.5rem;">
                Unit price is per tonne. Every line needs one before the proforma can be saved.
            </div>
        @endif
    </div>
</div>
