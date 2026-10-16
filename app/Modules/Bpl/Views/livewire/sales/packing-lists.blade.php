@php
    $lines = $this->lines;
    $loaded = $this->loaded();
@endphp
<div>
    <div class="page-head">
        <h1>Packing Lists</h1>
        <p>What went into each container, the document that travels with it, and the final invoice priced on what shipped.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">
            {{ session('ok') }}
            @if (session('printId'))
                <a href="{{ route('bpl.sales.packing-lists.print', session('printId')) }}" target="_blank" rel="noopener"
                   class="btn btn-ghost btn-sm" style="margin-left:.75rem;">Print packing list</a>
            @endif
        </div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- The list                                                            --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($mode === 'list')
        <div class="card">
            <div class="card-head">
                <h2 class="card-title">Packing lists</h2>
                <div class="flex items-center gap-2" style="margin-left:auto;">
                    <input type="text" class="form-control" style="width:260px;" wire:model.live.debounce.300ms="search"
                           placeholder="Number or customer…">
                    @if ($this->mayDo('create'))
                        <button type="button" class="btn btn-primary btn-sm" wire:click="startNew">New packing list</button>
                    @endif
                </div>
            </div>
            <div class="card-pad">
                <div class="table-wrap">
                    <table class="data" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:190px;">Number</th>
                                <th>Customer</th>
                                <th style="width:100px;">Date</th>
                                <th style="width:90px;text-align:right;">Containers</th>
                                <th style="width:80px;text-align:right;">Rolls</th>
                                <th style="width:120px;text-align:right;">Net (kg)</th>
                                <th style="width:120px;text-align:right;">Gross (kg)</th>
                                <th style="width:170px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                @php
                                    $t = $this->totalsFor($row);
                                    $isPartial = str_ends_with((string) $row->number, ')');
                                    $isSplit = in_array((int) $row->order_id, $this->splitOrders, true);
                                    $hasBl = in_array((int) $row->id, $this->shipped, true);
                                @endphp
                                <tr wire:key="pl-{{ $row->id }}">
                                    <td>
                                        <span class="mono">{{ $this->label($row) }}</span>
                                        @if ($isPartial)
                                            <span class="badge badge-muted" style="margin-left:.3rem;">partial</span>
                                        @elseif ($isSplit)
                                            <span class="badge badge-warning" style="margin-left:.3rem;">split</span>
                                        @endif
                                        @if ($hasBl)
                                            <span class="badge badge-success" style="margin-left:.3rem;">B/L</span>
                                        @endif
                                    </td>
                                    <td>{{ $row->customername ?: '—' }}</td>
                                    <td>{{ $row->date }}</td>
                                    <td style="text-align:right;">{{ $t['containers'] }}</td>
                                    <td style="text-align:right;">{{ number_format($t['rolls']) }}</td>
                                    <td style="text-align:right;">{{ number_format($t['net'], 2) }}</td>
                                    <td style="text-align:right;">{{ number_format($t['gross'], 2) }}</td>
                                    <td style="text-align:right;white-space:nowrap;">
                                        <a href="{{ route('bpl.sales.packing-lists.print', $row->id) }}" target="_blank" rel="noopener"
                                           class="btn btn-ghost btn-icon btn-sm" title="Print packing list">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                                        </a>
                                        <a href="{{ route('bpl.sales.packing-lists.invoice', $row->id) }}" target="_blank" rel="noopener"
                                           class="btn btn-ghost btn-icon btn-sm" title="Final invoice">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 17V9M9.5 11h5M9.5 14.5h5"/></svg>
                                        </a>
                                        @if ($this->mayDo('edit') && ! $isPartial && ! $isSplit && ! $hasBl)
                                            <button type="button" class="btn btn-ghost btn-icon btn-sm"
                                                    wire:click="edit({{ $row->id }})" title="Open">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
                                            </button>
                                            @if ($t['containers'] > 1)
                                                <button type="button" class="btn btn-ghost btn-icon btn-sm"
                                                        wire:click="openSplit({{ $row->id }})" title="Split into partial shipments">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="6" r="2"/><circle cx="12" cy="18" r="2"/><path d="M5 12h14"/></svg>
                                                </button>
                                            @endif
                                        @endif
                                        @if ($this->mayDo('delete') && ! $hasBl)
                                            <button type="button" class="btn btn-danger btn-icon btn-sm"
                                                    wire:click="deletePacking({{ $row->id }})"
                                                    wire:confirm="Delete packing list {{ $this->label($row) }}? This cannot be undone."
                                                    title="Delete">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="empty-row">No packing lists match.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="text-muted text-sm" style="margin-top:.6rem;">
                    Showing the {{ $perPage }} most recent. A split packing list is the parent of its partial shipments;
                    one with a bill of lading is fixed.
                </div>
            </div>
        </div>
    @else

    {{-- ------------------------------------------------------------------ --}}
    {{-- Building the containers                                             --}}
    {{-- ------------------------------------------------------------------ --}}
        <div class="card">
            <div class="card-head">
                <h2 class="card-title">{{ $editingId ? 'Editing packing list' : 'New packing list' }}</h2>
                <button type="button" class="btn btn-ghost btn-sm" style="margin-left:auto;" wire:click="showList">
                    &larr; Back to list
                </button>
            </div>

            <div class="card-pad">
                @if ($formError)
                    <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">
                        {{ $formError }}
                    </div>
                @endif

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;">
                    <div style="grid-column:span 2;">
                        @if ($editingId)
                            <div class="form-group">
                                <label class="form-label">Proforma</label>
                                <input type="text" class="form-control mono" readonly
                                       value="{{ $this->openProformas->firstWhere('id', $orderId)->orderno ?? ('order ' . $orderId) }}"
                                       style="background:var(--surface-2);color:var(--muted);cursor:default;">
                            </div>
                        @else
                            @include('bil::partials.combobox', [
                                'model' => 'orderId',
                                'labelText' => 'Proforma',
                                'placeholder' => 'Search proforma…',
                                'items' => $this->openProformas->map(fn ($p) => [
                                    'value' => $p->id,
                                    'label' => ($p->orderno ?: 'ref ' . $p->ref) . ' · ' . ($p->customername ?: '—')
                                        . ' · ' . $p->container . ' ' . \Illuminate\Support\Str::plural('container', (int) $p->container),
                                ]),
                            ])
                            <div class="form-hint">Orders with a proforma and no packing list yet.</div>
                        @endif
                        @error('orderId') <div class="form-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Date</label>
                        @include('bil::partials.date-field', ['model' => 'dateIso'])
                        @error('dateIso') <div class="form-error">{{ $message }}</div> @enderror
                    </div>
                </div>

                @if ($orderId && $lines !== [])
                    {{-- What the order says, and how much of it is in a container --}}
                    <div class="table-wrap" style="margin-top:1rem;">
                        <table class="data" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>Ordered</th>
                                    <th style="width:130px;text-align:right;">Ordered (kg)</th>
                                    <th style="width:130px;text-align:right;">Loaded (kg)</th>
                                    <th style="width:130px;text-align:right;">Left (kg)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lines as $line)
                                    @php
                                        $done = (float) ($loaded[$line['order_item_id']] ?? 0);
                                        $left = round($line['weight'] - $done, 2);
                                    @endphp
                                    <tr>
                                        <td class="text-sm">{{ $line['fullname'] }}</td>
                                        <td style="text-align:right;">{{ number_format($line['weight'], 2) }}</td>
                                        <td style="text-align:right;">{{ number_format($done, 2) }}</td>
                                        <td style="text-align:right;">
                                            <span class="badge {{ $left < 0 ? 'badge-danger' : ($left > 0 ? 'badge-muted' : 'badge-success') }}">
                                                {{ number_format($left, 2) }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- One card per container --}}
                    @foreach ($containers as $index => $container)
                        <div class="card" style="margin-top:1rem;" wire:key="cont-{{ $index }}">
                            <div class="card-head">
                                <h3 class="card-title" style="font-size:1rem;">Container {{ $index + 1 }}</h3>
                                @if (count($containers) > 1)
                                    <button type="button" class="btn btn-danger btn-icon btn-sm" style="margin-left:auto;"
                                            wire:click="removeContainer({{ $index }})" title="Remove this container">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                    </button>
                                @endif
                            </div>
                            <div class="card-pad">
                                <div style="display:grid;grid-template-columns:1fr 200px;gap:0.75rem;">
                                    <div class="form-group">
                                        <label class="form-label">Container / Truck Number</label>
                                        <input type="text" class="form-control mono" style="text-transform:uppercase;"
                                               wire:model="containers.{{ $index }}.name" maxlength="50" autocomplete="off">
                                        @error('containers.' . $index . '.name') <div class="form-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Empty Weight (kg)</label>
                                        <input type="number" class="form-control qty-input"
                                               wire:model="containers.{{ $index }}.tare" min="1" step="10">
                                        @error('containers.' . $index . '.tare') <div class="form-error">{{ $message }}</div> @enderror
                                    </div>
                                </div>

                                <div class="table-wrap">
                                    <table class="data" style="width:100%;">
                                        <thead>
                                            <tr>
                                                <th>Goods</th>
                                                <th style="width:130px;text-align:center;">Rolls</th>
                                                <th style="width:160px;text-align:center;">Weight (kg)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($lines as $line)
                                                <tr wire:key="cg-{{ $index }}-{{ $line['order_item_id'] }}">
                                                    <td class="text-sm">{{ $line['fullname'] }}</td>
                                                    <td>
                                                        <input type="number" class="form-control qty-input" min="1" step="1"
                                                               wire:model.live.debounce.600ms="containers.{{ $index }}.goods.{{ $line['order_item_id'] }}.rolls">
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control qty-input" min="0.01" step="0.01"
                                                               wire:model.live.debounce.600ms="containers.{{ $index }}.goods.{{ $line['order_item_id'] }}.weight">
                                                        @error('containers.' . $index . '.goods.' . $line['order_item_id'] . '.weight')
                                                            <div class="form-error">{{ $message }}</div>
                                                        @enderror
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <div class="form-hint" style="margin-top:.4rem;">
                                    Leave a line empty when this container carries none of it.
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <div class="flex items-center gap-2" style="margin-top:1rem;flex-wrap:wrap;">
                        <button type="button" class="btn btn-ghost" wire:click="addContainer">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                            Add container
                        </button>
                        <span class="text-muted text-sm">
                            The proforma quoted
                            {{ $this->openProformas->firstWhere('id', $orderId)->container ?? count($containers) }};
                            add or remove if the load changed.
                        </span>
                    </div>

                    <div style="margin-top:1.25rem;max-width:520px;">
                        <button type="button" class="btn btn-primary" style="width:100%;"
                                wire:click="save" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save changes' : 'Create packing list' }}</span>
                            <span wire:loading wire:target="save">Saving…</span>
                        </button>
                    </div>
                @elseif ($orderId)
                    <div class="card card-pad text-sm" style="border-color:var(--danger);color:var(--danger);margin-top:1rem;">
                        That order has no priced proforma lines.
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Partial shipments                                                   --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($splittingId && $this->splitting)
        @php $packing = $this->splitting; @endphp
        <div class="modal-backdrop" x-data @keydown.escape.window="$wire.closeSplit()" style="display:flex;">
            <div class="modal-card" style="max-width:720px;width:96%;" @click.outside="$wire.closeSplit()">
                <div class="modal-head">
                    <div>
                        <h3 class="modal-title" style="margin:0;">Partial shipments</h3>
                        <p class="text-muted text-sm" style="margin:.2rem 0 0;">
                            Each shipment becomes its own packing list, numbered (A), (B)…
                        </p>
                    </div>
                    <button class="modal-close" wire:click="closeSplit">&times;</button>
                </div>

                <div class="modal-body" style="max-height:70vh;overflow:auto;">
                    <div class="form-group" style="max-width:220px;">
                        <label class="form-label">Number of shipments</label>
                        <input type="number" class="form-control qty-input" wire:model.live="splitCount" min="2" max="12" step="1">
                    </div>

                    @if ($splitError)
                        <div class="form-error" style="margin-bottom:.75rem;">{{ $splitError }}</div>
                    @endif

                    <div class="table-wrap">
                        <table class="data" style="width:100%;">
                            <thead>
                                <tr>
                                    <th style="width:60px;">#</th>
                                    <th>Container</th>
                                    <th style="width:110px;text-align:right;">Rolls</th>
                                    <th style="width:130px;text-align:right;">Net (kg)</th>
                                    <th style="width:150px;">Shipment</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($packing->containers as $i => $container)
                                    <tr wire:key="split-{{ $i }}">
                                        <td>{{ $i + 1 }}</td>
                                        <td class="mono">{{ $container['name'] ?? '' }}</td>
                                        <td style="text-align:right;">{{ number_format(array_sum(array_column($container['goods'] ?? [], 'rolls'))) }}</td>
                                        <td style="text-align:right;">{{ number_format(array_sum(array_column($container['goods'] ?? [], 'weight')), 2) }}</td>
                                        <td>
                                            <select class="form-control" wire:model="splitAssignment.{{ $i }}">
                                                @for ($n = 1; $n <= max(2, min(12, (int) $splitCount)); $n++)
                                                    <option value="{{ $n }}">Shipment {{ chr(64 + $n) }}</option>
                                                @endfor
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="text-muted text-sm" style="margin-top:.6rem;">
                        The original packing list stays, marked split; the partial shipments are what ship and invoice.
                    </div>
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" wire:click="closeSplit">Cancel</button>
                    <button type="button" class="btn btn-primary" wire:click="saveSplit">Create shipments</button>
                </div>
            </div>
        </div>
    @endif
</div>
