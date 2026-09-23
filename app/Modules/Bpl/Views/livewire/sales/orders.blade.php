@php
    $locked = $this->orderLocked;
    $preview = $this->numberPreview;
@endphp
<div x-data="{
        // The hardroll catalog (~4,400 products) for every line's picker —
        // fetched ONCE and filled in place, so each row's combobox (which
        // holds a reference to this same array) sees it arrive. Embedding it
        // here instead would put ~240 KB into every Livewire response.
        orderProducts: [],
        productsLoaded: false,
        async init() {
            try {
                const res = await fetch(@js(route('bpl.sales.orders.products')), { headers: { Accept: 'application/json' } });
                this.orderProducts.push(...(await res.json()));
            } finally {
                this.productsLoaded = true;
            }
        },
        // Grade for whatever a row currently holds, read client-side so it
        // tracks the deferred picker value without a request.
        gradeFor(field) {
            const v = this.$wire.get(field);
            if (v === null || v === undefined || v === '') return '—';
            const p = this.orderProducts.find((p) => String(p.value) === String(v));
            return p ? (p.grade || '—') : '—';
        },
        totalWeight() {
            return Object.values(this.$wire.get('rows') || {})
                .filter((r) => r.productid !== null && r.productid !== '' && r.productid !== undefined)
                .reduce((s, r) => s + (parseFloat(r.weight) || 0), 0)
                .toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
     }">
    <div class="page-head">
        <h1>BPL Sales Orders</h1>
        <p>Record a customer's order for jumbo rolls, by weight. Proformas and packing lists work from these lines.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Orders already placed                                               --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($mode === 'list')
        <div class="card">
            <div class="card-head">
                <h2 class="card-title">Sales order list</h2>
                <button type="button" class="btn btn-ghost btn-sm" style="margin-left:auto;" wire:click="showForm">
                    &larr; Back to form
                </button>
            </div>
            <div class="card-pad">
                <div class="flex items-end gap-2" style="flex-wrap:wrap;margin-bottom:1rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Date of order</label>
                        @include('bil::partials.date-field', ['model' => 'listDateIso', 'live' => true, 'compact' => true])
                    </div>
                    <div class="form-group" style="margin-bottom:0;flex:1 1 220px;">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" wire:model.live.debounce.300ms="listSearch"
                               placeholder="Order number or customer…">
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:210px;">Order #</th>
                                <th style="width:110px;">Order date</th>
                                <th style="width:110px;">Company</th>
                                <th>Customer</th>
                                <th style="width:70px;text-align:right;">Lines</th>
                                <th style="width:130px;text-align:right;">Weight (kg)</th>
                                <th style="width:110px;">User</th>
                                <th style="width:100px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->orders as $o)
                                @php $hasDownstream = in_array((int) $o->id, $this->listLocked, true); @endphp
                                <tr wire:key="bso-{{ $o->id }}">
                                    <td>
                                        <strong class="mono">{{ $o->number ?: '—' }}</strong>
                                        @if ($hasDownstream)
                                            <span class="badge badge-muted" style="margin-left:.35rem;">invoiced</span>
                                        @endif
                                    </td>
                                    <td>{{ $o->date }}</td>
                                    <td>{{ $o->company }}</td>
                                    <td>{{ $o->customername ?: '—' }}</td>
                                    <td style="text-align:right;">{{ number_format((int) $o->line_count) }}</td>
                                    <td style="text-align:right;">{{ number_format((float) $o->total_weight, 2) }}</td>
                                    <td class="text-sm text-muted">{{ $o->username }}</td>
                                    <td style="text-align:right;white-space:nowrap;">
                                        <button type="button" class="btn btn-ghost btn-icon btn-sm"
                                                wire:click="editOrder({{ $o->id }})" title="Open this order">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
                                        </button>
                                        @if ($this->canDelete())
                                            <button type="button" class="btn btn-danger btn-icon btn-sm"
                                                    wire:click="deleteOrder({{ $o->id }})"
                                                    wire:confirm="Delete sales order {{ $o->number }} and all its lines?"
                                                    @disabled($hasDownstream)
                                                    title="{{ $hasDownstream ? 'A proforma or packing list exists — cannot delete.' : 'Delete this order' }}">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-muted" style="text-align:center;padding:1.5rem;">No orders match.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="text-muted text-sm" style="margin-top:.6rem;">Showing the 200 most recent matches.</div>
            </div>
        </div>
    @else

    {{-- ------------------------------------------------------------------ --}}
    {{-- Placing / editing an order                                          --}}
    {{-- ------------------------------------------------------------------ --}}
        <form wire:submit="save">
            <div class="card">
                <div class="card-head">
                    <h2 class="card-title">
                        {{ $editingId ? 'Editing order ' . ($orderno ?: $ref) : 'New sales order' }}
                    </h2>
                    <div class="flex items-center gap-2" style="margin-left:auto;">
                        @if ($editingId)
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="startNew">New order</button>
                        @endif
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="showList">Sales order list</button>
                    </div>
                </div>

                <div class="card-pad">
                    @if ($locked)
                        <div class="card" style="border-color:var(--warning,#b8860b);margin-bottom:1rem;padding:0.7rem 1.25rem;">
                            <strong>A {{ $this->downstream['packing'] ? 'packing list' : 'proforma' }} has been raised against this order.</strong>
                            Its number, company and customer are fixed, and a line priced on the proforma can neither be
                            removed nor changed to another product.
                        </div>
                    @endif

                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0.75rem;">
                        <div class="form-group" style="grid-column:span 2;">
                            <label class="form-label">Order number</label>
                            <input type="text" class="form-control mono" readonly tabindex="-1"
                                   value="{{ $preview['number'] ?? '' }}"
                                   placeholder="{{ $preview['problem'] ? 'Cannot be numbered' : 'Generated from the customer' }}">
                            @if ($preview['problem'])
                                <div class="form-error">{{ $preview['problem'] }}</div>
                            @elseif ($preview['number'] && ! $preview['final'])
                                <div class="text-muted text-sm" style="margin-top:.25rem;">Assigned when the order is saved.</div>
                            @endif
                        </div>

                        <div class="form-group">
                            <label class="form-label">User</label>
                            <select class="form-control" wire:model="username" @disabled(count($this->orderUsers) < 2)>
                                @foreach ($this->orderUsers as $u)
                                    <option value="{{ $u }}">{{ $u }}</option>
                                @endforeach
                            </select>
                            @error('username') <div class="form-error">{{ $message }}</div> @enderror
                        </div>

                        <div class="form-group">
                            <label class="form-label">Company</label>
                            <select class="form-control" wire:model="company" @disabled($locked)>
                                @foreach ($this->companies() as $c)
                                    <option value="{{ $c }}">{{ $c }}</option>
                                @endforeach
                            </select>
                            @error('company') <div class="form-error">{{ $message }}</div> @enderror
                        </div>

                        <div style="grid-column:span 2;">
                            {{-- Live: the order number is built from the customer. --}}
                            @include('bil::partials.combobox', [
                                'model' => 'customerid',
                                'labelText' => 'Customer',
                                'placeholder' => 'Search customer…',
                                'items' => $this->customers->map(fn ($c) => ['value' => $c->id, 'label' => $c->customername . ($c->customerlabel ? ' (' . $c->customerlabel . ')' : '')]),
                                'disabled' => $locked,
                            ])
                        </div>

                        <div class="form-group">
                            <label class="form-label">Date of order</label>
                            @include('bil::partials.date-field', ['model' => 'dateIso', 'disabled' => ! $this->canBackdate()])
                            @error('dateIso') <div class="form-error">{{ $message }}</div> @enderror
                            @unless ($this->canBackdate())
                                <div class="text-muted text-sm" style="margin-top:.25rem;">Locked to today — needs the “backdate” permission.</div>
                            @endunless
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-top:1rem;">
                <div class="card-head">
                    <h2 class="card-title">Products</h2>
                    <span class="text-muted text-sm" x-show="!productsLoaded" style="margin-left:.75rem;">Loading products…</span>
                    <span class="badge badge-muted" style="margin-left:auto;">
                        {{ count($rows) }} {{ \Illuminate\Support\Str::plural('row', count($rows)) }}
                        · <span x-text="totalWeight()">0.00</span> kg
                    </span>
                </div>

                <div class="card-pad">
                    {{-- Deliberately NOT wrapped in .table-wrap: its overflow-x
                         clips each row's dropdown panel at the table edge. --}}
                    <table class="data order-lines">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="width:110px;">Grade</th>
                                <th style="width:170px;text-align:center;">Weight (kg)</th>
                                <th style="width:56px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $uid => $row)
                                @php $rowPriced = $this->rowPriced($row); @endphp
                                <tr wire:key="bsorow-{{ $uid }}">
                                    <td class="product-cell">
                                        @include('bil::partials.combobox', [
                                            'model' => 'rows.' . $uid . '.productid',
                                            'itemsExpr' => 'orderProducts',
                                            'placeholder' => 'Search product…',
                                            'bare' => true,
                                            'live' => false,
                                            'disabled' => $rowPriced,
                                            'key' => 'bsosel-' . $uid,
                                        ])
                                        @if ($rowPriced)
                                            <div class="text-muted text-sm" style="margin-top:.2rem;">Priced on the proforma</div>
                                        @endif
                                    </td>
                                    <td class="text-sm" x-text="gradeFor('rows.{{ $uid }}.productid')">—</td>
                                    <td>
                                        <input type="number" class="form-control qty-input"
                                               wire:model="rows.{{ $uid }}.weight"
                                               min="0.01" step="0.01" placeholder="0.00">
                                        @error('rows.' . $uid . '.weight') <div class="form-error">{{ $message }}</div> @enderror
                                    </td>
                                    <td style="text-align:center;">
                                        <button type="button" class="btn btn-danger btn-icon btn-sm"
                                                wire:click="removeRow({{ $uid }})"
                                                @disabled($rowPriced)
                                                title="{{ $rowPriced ? 'Priced on a proforma — cannot remove.' : 'Remove this row' }}">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="flex items-end gap-2" style="margin-top:1rem;flex-wrap:wrap;">
                        <div class="form-group" style="margin-bottom:0;width:110px;">
                            <label class="form-label">Rows to add</label>
                            <input type="number" class="form-control qty-input"
                                   wire:model="addCount" min="1" max="{{ \Modules\Bpl\Livewire\Sales\Orders::MAX_ADD_ROWS }}">
                        </div>
                        <button type="button" class="btn btn-ghost" wire:click="addRows">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                            Add rows
                        </button>
                        @error('addCount') <div class="form-error" style="align-self:center;">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="card-pad" style="border-top:1px solid var(--border);">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save changes' : 'Place order' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>
        </form>
    @endif
</div>
