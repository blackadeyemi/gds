@php
    // One copy of the product list for the whole grid, held on the wrapper's
    // Alpine scope. Every row's picker reads it by name (see $optionsExpr on
    // core::partials.searchable-select) instead of embedding its own copy —
    // with ~300 products and up to 200 lines that is the difference between a
    // few KB and several MB of markup.
    // `code` rides along so the Product Code column can fill itself in from the
    // chosen product without a server round-trip — see the cell below.
    $productOptions = $this->products->map(fn ($p) => [
        'value' => (string) $p->productid,
        'label' => $p->productname,
        'code' => (string) $p->productcode,
    ])->values();
    $locked = $this->orderLocked;
@endphp
<div x-data="{
        salesProducts: {{ Illuminate\Support\Js::from($productOptions) }},
        // Product code for whatever a row currently holds. Reads through
        // $wire.get(), so it tracks the deferred value the picker just set
        // without waiting for (or triggering) a request.
        codeFor(field) {
            const v = this.$wire.get(field);
            if (v === null || v === undefined || v === '') return '—';
            const p = this.salesProducts.find((p) => String(p.value) === String(v));
            return p ? p.code : '—';
        },
        // Same reason: quantities are deferred, so the running total has to be
        // read from the client-side state or it would sit stale until save.
        filledRows() {
            return Object.values(this.$wire.get('rows') || {})
                .filter((r) => r.productid !== null && r.productid !== '' && r.productid !== undefined);
        },
        totalOrdered() {
            return this.filledRows().reduce((s, r) => s + (parseInt(r.quantity) || 0), 0)
                .toLocaleString('en-US');
        }
     }">
    <div class="page-head">
        <h1>Sales Orders</h1>
        <p>Record a customer's order for finished goods. Loading, delivery and the sales reports all work from these lines.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Finding an existing order                                           --}}
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
                                <th style="width:130px;">Order #</th>
                                <th style="width:120px;">Order date</th>
                                <th style="width:150px;">Location</th>
                                <th>Customer</th>
                                <th style="width:130px;">User</th>
                                <th style="width:110px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->orders as $o)
                                @php $hasLoading = in_array($o->orderid, $this->listLoaded, true); @endphp
                                <tr wire:key="order-{{ $o->id }}">
                                    <td>
                                        <strong>{{ $o->orderid }}</strong>
                                        @if ($hasLoading)
                                            <span class="badge badge-muted" style="margin-left:.35rem;">loaded</span>
                                        @endif
                                    </td>
                                    <td>{{ $o->dateoforder }}</td>
                                    <td>{{ $this->depotNames[$o->warehousecode] ?? ($o->warehousecode ?: '—') }}</td>
                                    <td>{{ $o->customername ?: '—' }}</td>
                                    <td class="text-sm text-muted">{{ $o->username }}</td>
                                    <td style="text-align:right;white-space:nowrap;">
                                        <button type="button" class="btn btn-ghost btn-icon btn-sm"
                                                wire:click="editOrder({{ $o->id }})" title="Open this order">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
                                        </button>
                                        @if ($this->canDelete())
                                            <button type="button" class="btn btn-danger btn-icon btn-sm"
                                                    wire:click="deleteOrder({{ $o->id }})"
                                                    wire:confirm="Delete sales order {{ $o->orderid }} and all its lines? This cannot be undone."
                                                    @disabled($hasLoading)
                                                    title="{{ $hasLoading ? 'A delivery has been made — cannot delete.' : 'Delete this order' }}">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted" style="text-align:center;padding:1.5rem;">No orders match.</td></tr>
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
                        {{ $editingId ? 'Editing order ' . $originalNumber : 'New sales order' }}
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
                            <strong>Goods have already been loaded against this order.</strong>
                            The order number, location and customer are fixed, and a loaded line can neither be
                            removed nor cut below the quantity already sent out.
                        </div>
                    @endif

                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0.75rem;">
                        <div class="form-group">
                            <label class="form-label">Order number</label>
                            <input type="text" class="form-control" wire:model="number" maxlength="20"
                                   placeholder="e.g. 116836" autocomplete="off" @disabled($locked)>
                            @error('number') <div class="form-error">{{ $message }}</div> @enderror
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

                        @include('bil::partials.combobox', [
                            'model' => 'warehouseId',
                            'labelText' => 'Order location',
                            'placeholder' => 'Search location…',
                            'items' => $this->warehouses->map(fn ($w) => ['value' => $w->id, 'label' => $w->name]),
                            'disabled' => $locked,
                        ])

                        <div style="grid-column:span 2;">
                            @include('bil::partials.combobox', [
                                'model' => 'customerid',
                                'labelText' => 'Customer',
                                'placeholder' => 'Search customer…',
                                'items' => $this->customers->map(fn ($c) => ['value' => $c->id, 'label' => $c->customername]),
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
                    <span class="badge badge-muted" style="margin-left:auto;">
                        {{ count($rows) }} {{ \Illuminate\Support\Str::plural('row', count($rows)) }}
                        · <span x-text="totalOrdered()">0</span> ordered
                    </span>
                </div>

                <div class="card-pad">
                    {{-- Deliberately NOT wrapped in .table-wrap: that sets
                         overflow-x:auto, which makes a scroll container and CLIPS
                         each row's dropdown panel at the table edge. The grid is
                         five narrow columns, so it does not need to scroll. --}}
                    <table class="data order-lines">
                        <thead>
                            <tr>
                                <th style="width:130px;">Product code</th>
                                <th>Product name</th>
                                <th style="width:150px;text-align:center;">Quantity</th>
                                <th style="width:80px;text-align:center;" title="Tick if this line is free of charge">FOC</th>
                                <th style="width:56px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                                @foreach ($rows as $uid => $row)
                                    @php
                                        $loadedQty = $this->loadedFor($row);
                                        $rowLocked = $loadedQty > 0;
                                    @endphp
                                    <tr wire:key="sorow-{{ $uid }}">
                                        <td class="text-sm" x-text="codeFor('rows.{{ $uid }}.productid')">{{ $row['productid'] ? ($this->productCodes[$row['productid']] ?? '—') : '—' }}</td>
                                        <td class="product-cell">
                                            {{-- Deliberately NOT live: the only thing that changed on
                                                 selection was the code cell, which now fills itself in
                                                 client-side. A live picker meant a full re-render (and
                                                 the whole customer list back over the wire) on every
                                                 product chosen, once per line. --}}
                                            @include('bil::partials.combobox', [
                                                'model' => 'rows.' . $uid . '.productid',
                                                'itemsExpr' => 'salesProducts',
                                                'placeholder' => 'Search product…',
                                                'bare' => true,
                                                'live' => false,
                                                'disabled' => $rowLocked,
                                                'key' => 'sosel-' . $uid,
                                            ])
                                        </td>
                                        <td>
                                            {{-- Centred and enlarged: the quantity is the one number an
                                                 order clerk reads back off the sheet, and it is easier to
                                                 scan down a column when it sits in the middle. --}}
                                            <input type="number" class="form-control qty-input"
                                                   wire:model="rows.{{ $uid }}.quantity"
                                                   min="{{ max(1, $loadedQty) }}" step="1" placeholder="0">
                                            @if ($rowLocked)
                                                <div class="text-muted text-sm" style="margin-top:.2rem;">{{ number_format($loadedQty) }} loaded</div>
                                            @endif
                                            @error('rows.' . $uid . '.quantity') <div class="form-error">{{ $message }}</div> @enderror
                                        </td>
                                        <td style="text-align:center;">
                                            <input type="checkbox" wire:model="rows.{{ $uid }}.foc" @disabled($rowLocked)>
                                        </td>
                                        <td style="text-align:center;">
                                            <button type="button" class="btn btn-danger btn-icon btn-sm"
                                                    wire:click="removeRow({{ $uid }})"
                                                    @disabled($rowLocked)
                                                    title="{{ $rowLocked ? 'Already loaded — cannot remove.' : 'Remove this row' }}">
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
                                   wire:model="addCount" min="1" max="{{ \Modules\Bil\Livewire\Sales\Orders::MAX_ADD_ROWS }}">
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
