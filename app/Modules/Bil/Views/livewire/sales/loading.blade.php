{{--
    Loading — the three legacy cageroom screens as one.

    The left column is the queue of OPEN loads. Pick one and the tabs on the
    right are what you can do to it: correct its lines, record a return, print
    it. The truck-and-crew panel above the tabs is the load's header — saving it
    updates every line of the barcode, as the legacy modification screen did.
--}}
@php
    $load = $this->load;
    $lines = $this->lines;
    $open = $this->isOpen();
    $totals = $this->totals();
@endphp

<div>
    {{-- Print Outs sits top-right: it is a way OUT of the page (to paper), not
         a step in it, so it does not belong among the working controls. --}}
    <div class="page-head" style="display:flex;align-items:flex-start;gap:1rem;">
        <div>
            <h1>Loading</h1>
            <p>Load a truck against a sales order, correct what went on, and record anything coming back off. A load stays editable until it is closed off.</p>
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

    <div style="display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:1rem;align-items:start;">

        {{-- ---------------- The queue ---------------- --}}
        <div class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Open loads</h2>
                    <div class="text-sm text-muted">{{ count($this->openLoads) }} on the floor</div>
                </div>
            </div>

            <div class="card-pad">
                @if ($this->canCreate())
                    {{-- Always primary. It is an action, not a tab: dimming it
                         once a load is open made the page's main action look
                         secondary. Which mode you are in is obvious from the
                         pane on the right. --}}
                    <button type="button" class="btn btn-primary"
                            style="width:100%;margin-bottom:.7rem;" wire:click="startNew">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        New Loading
                    </button>
                @endif

                <div class="form-group">
                    <input type="search" class="form-control" placeholder="Barcode, truck, driver, customer…"
                           wire:model.live.debounce.400ms="search">
                </div>

                <div style="max-height:42rem;overflow:auto;margin:0 -0.4rem;">
                    @forelse ($this->openLoads as $l)
                        <button type="button" wire:key="l-{{ $l->barcode }}" wire:click="openLoad('{{ $l->barcode }}')"
                                class="btn btn-ghost"
                                style="display:block;width:100%;text-align:left;padding:0.55rem 0.7rem;margin-bottom:0.2rem;border-radius:6px;{{ $l->barcode === $barcode ? 'background:var(--accent-soft,rgba(59,130,246,.12));' : '' }}">
                            <div style="font-weight:600;font-family:monospace;">{{ $l->barcode }}</div>
                            <div class="text-sm text-muted">{{ $l->customername ?: 'No customer' }}</div>
                            <div class="text-sm text-muted">
                                {{ $l->trucknumber }} · {{ $l->line_count }} line(s) · {{ number_format($l->loaded) }} bundles loaded
                            </div>
                        </button>
                    @empty
                        <div class="text-muted" style="padding:0.8rem;">
                            @if ($search !== '')
                                Nothing open matches “{{ $search }}”.
                            @else
                                No open loads — every truck has been closed off.
                            @endif
                        </div>
                    @endforelse
                </div>

                {{-- A closed load is still reachable, read-only, to reprint. --}}
                <label class="flex items-center gap-2" style="cursor:pointer;font-size:0.9rem;margin-top:0.7rem;">
                    <input type="checkbox" wire:model.live="showByDate">
                    <span>Find a closed load by date</span>
                </label>

                @if ($showByDate)
                    <div class="form-group" style="margin-top:.5rem;">
                        @include('bil::partials.date-field', ['model' => 'dateIso', 'live' => true])
                    </div>
                    <div style="max-height:22rem;overflow:auto;margin:0 -0.4rem;">
                        @forelse ($this->dateLoads as $l)
                            <button type="button" wire:key="d-{{ $l->barcode }}" wire:click="openLoad('{{ $l->barcode }}')"
                                    class="btn btn-ghost"
                                    style="display:block;width:100%;text-align:left;padding:0.45rem 0.7rem;margin-bottom:0.15rem;border-radius:6px;{{ $l->barcode === $barcode ? 'background:var(--accent-soft,rgba(59,130,246,.12));' : '' }}">
                                <div class="text-sm" style="font-family:monospace;">{{ $l->barcode }}</div>
                                <div class="text-sm text-muted">
                                    {{ $l->customername ?: '—' }}
                                    @if ($l->status) · <span class="badge badge-muted">closed</span> @endif
                                </div>
                            </button>
                        @empty
                            <div class="text-muted text-sm" style="padding:0.6rem;">No loads on that date.</div>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>

        {{-- ---------------- Right: new loading, or the open load ---------------- --}}
        <div>
            @if ($mode === 'new')
                @include('bil::partials.loading-new')
            @elseif (! $load)
                <div class="card card-pad text-muted">
                    Pick a load on the left to correct it, record a return or print it —
                    or start a <strong>New Loading</strong>.
                </div>
            @else
                {{-- The load --}}
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
                            @if ($open)
                                <span class="badge badge-success">Open</span>
                            @else
                                <span class="badge badge-muted">Closed {{ $load->status }}</span>
                            @endif
                            <span class="badge badge-muted">{{ $totals['lines'] }} line(s)</span>
                            <span class="badge badge-muted">{{ number_format($totals['loaded']) }} bundles loaded</span>
                            @if ($totals['returned'] > 0)
                                <span class="badge" style="background:rgba(217,119,6,.14);color:#b45309;">{{ number_format($totals['returned']) }} returned</span>
                            @endif
                        </div>
                    </div>

                    {{-- Truck and crew: the load's header. Saving updates every
                         line of the barcode, because the truck belongs to the
                         load, not to a product. --}}
                    <div class="card-pad">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:0.75rem;">
                            <div class="form-group">
                                <label class="form-label">Transporter</label>
                                @include('core::partials.searchable-select', [
                                    'field' => 'transporterid',
                                    'options' => $this->transporterOptions,
                                    'valueKey' => 'value', 'labelKey' => 'label',
                                    'placeholder' => '— Select —',
                                    'disabled' => ! ($open && $this->canModify()),
                                    'key' => 'tr-' . $load->barcode,
                                ])
                                @error('transporterid') <div class="form-error">{{ $message }}</div> @enderror
                            </div>
                            <div class="form-group">
                                <label class="form-label">Cageroom</label>
                                @include('core::partials.searchable-select', [
                                    'field' => 'cageroomcode',
                                    'options' => $this->cageroomOptions,
                                    'valueKey' => 'value', 'labelKey' => 'label',
                                    'placeholder' => '— Select —',
                                    'disabled' => ! ($open && $this->canModify()),
                                    'key' => 'cg-' . $load->barcode,
                                ])
                                @error('cageroomcode') <div class="form-error">{{ $message }}</div> @enderror
                            </div>
                            <div class="form-group">
                                <label class="form-label">Truck number</label>
                                <input type="text" class="form-control" list="trucklist" wire:model="trucknumber"
                                       @disabled(! ($open && $this->canModify()))>
                                @error('trucknumber') <div class="form-error">{{ $message }}</div> @enderror
                            </div>
                            <div class="form-group">
                                <label class="form-label">Driver</label>
                                <input type="text" class="form-control" list="driverlist" wire:model="truckdriver"
                                       @disabled(! ($open && $this->canModify()))>
                                @error('truckdriver') <div class="form-error">{{ $message }}</div> @enderror
                            </div>
                            <div class="form-group">
                                <label class="form-label">Loader</label>
                                <input type="text" class="form-control" wire:model="loader"
                                       @disabled(! ($open && $this->canModify()))>
                                @error('loader') <div class="form-error">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        @if ($open && $this->canModify())
                            <div class="flex" style="justify-content:flex-end;">
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="saveHeader">Save truck &amp; crew</button>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Tabs, scoped to this load --}}
                <div class="card">
                    <div class="card-pad" style="padding-bottom:0;">
                        <div class="flex gap-2" style="border-bottom:1px solid var(--border);">
                            @foreach (['lines' => 'Lines', 'returns' => 'Returns', 'print' => 'Print'] as $key => $label)
                                <button type="button" wire:click="setTab('{{ $key }}')"
                                        class="btn btn-ghost btn-sm"
                                        style="border-radius:6px 6px 0 0;margin-bottom:-1px;{{ $tab === $key ? 'border-bottom:2px solid var(--brand,#3b82f6);font-weight:600;' : '' }}">
                                    {{ $label }}
                                    @if ($key === 'returns' && $totals['returned'] > 0)
                                        <span class="badge" style="background:rgba(217,119,6,.14);color:#b45309;margin-left:.3rem;">{{ number_format($totals['returned']) }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="card-pad" style="overflow-x:auto;">
                        @if (! $open)
                            <div class="text-muted" style="margin-bottom:.7rem;">
                                This load was closed on {{ $load->status }} — it can be printed but not changed.
                            </div>
                        @endif

                        {{-- ---- Lines (Modification) ---- --}}
                        @if ($tab === 'lines')
                            <table class="data" style="width:100%;min-width:720px;">
                                <thead>
                                    <tr>
                                        <th>Order</th>
                                        <th>Product</th>
                                        <th style="width:90px;text-align:right;">Ordered</th>
                                        <th style="width:90px;text-align:right;">On truck</th>
                                        <th style="width:90px;text-align:right;">Returned</th>
                                        <th style="width:200px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $line)
                                        <tr wire:key="ln-{{ $line->id }}">
                                            <td class="text-sm">{{ $line->orderid }}</td>
                                            <td>
                                                <div>{{ $line->productname }}</div>
                                                <div class="text-sm text-muted">{{ $line->productcode }}@if ($line->foc) · <span class="badge" style="background:rgba(198,40,40,.14);color:#c62828;">FOC</span>@endif</div>
                                            </td>
                                            <td style="text-align:right;">{{ number_format($line->quantityordered) }}</td>
                                            <td style="text-align:right;">
                                                @if ($editingLine === $line->id)
                                                    <input type="number" min="0" class="form-control" style="text-align:right;"
                                                           wire:model="editQty" wire:keydown.enter="saveLine">
                                                    @error('editQty') <div class="form-error">{{ $message }}</div> @enderror
                                                @else
                                                    <strong>{{ number_format($line->loaded_net) }}</strong>
                                                @endif
                                            </td>
                                            <td style="text-align:right;">
                                                @if ($line->returned > 0)
                                                    <span style="color:#b45309;">{{ number_format($line->returned) }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td style="text-align:right;">
                                                @if ($open && $this->canModify())
                                                    @if ($editingLine === $line->id)
                                                        <button type="button" class="btn btn-primary btn-sm" wire:click="saveLine">Save</button>
                                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('editingLine', null)">Cancel</button>
                                                    @elseif ($confirmingRemove === $line->id)
                                                        <span class="text-sm">Remove?</span>
                                                        <button type="button" class="btn btn-danger btn-sm" wire:click="removeLine">Yes</button>
                                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmingRemove', null)">No</button>
                                                    @else
                                                        <button type="button" class="btn btn-ghost btn-sm"
                                                                wire:click="editLine({{ $line->id }}, {{ $line->loaded_net }})">Correct</button>
                                                        @if ($line->returned > 0)
                                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="undoReturn({{ $line->id }})">Undo return</button>
                                                        @endif
                                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmingRemove', {{ $line->id }})">Remove</button>
                                                    @endif
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            @if ($open && $this->canModify())
                                <div class="text-sm text-muted" style="margin-top:.6rem;">
                                    <strong>Correcting</strong> a quantity clears any return recorded against that line —
                                    the corrected figure is the whole truth about it.
                                </div>
                            @endif

                        {{-- ---- Returns ---- --}}
                        @elseif ($tab === 'returns')
                            <table class="data" style="width:100%;min-width:680px;">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th style="width:110px;text-align:right;">Put on</th>
                                        <th style="width:110px;text-align:right;">On truck</th>
                                        <th style="width:110px;text-align:right;">Returned</th>
                                        <th style="width:220px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $line)
                                        <tr wire:key="rt-{{ $line->id }}">
                                            <td>
                                                <div>{{ $line->productname }}</div>
                                                <div class="text-sm text-muted">{{ $line->productcode }}</div>
                                            </td>
                                            <td style="text-align:right;">{{ number_format($line->loaded_gross) }}</td>
                                            <td style="text-align:right;"><strong>{{ number_format($line->loaded_net) }}</strong></td>
                                            <td style="text-align:right;">
                                                @if ($line->returned > 0)
                                                    <span style="color:#b45309;">{{ number_format($line->returned) }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td style="text-align:right;">
                                                @if ($open && $this->canReturn())
                                                    @if ($returningLine === $line->id)
                                                        <input type="number" min="1" max="{{ $line->loaded_net }}"
                                                               class="form-control" style="width:90px;display:inline-block;text-align:right;"
                                                               placeholder="Qty" wire:model="returnQty" wire:keydown.enter="saveReturn">
                                                        <button type="button" class="btn btn-primary btn-sm" wire:click="saveReturn">Return</button>
                                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('returningLine', null)">Cancel</button>
                                                        @error('returnQty') <div class="form-error">{{ $message }}</div> @enderror
                                                    @else
                                                        <button type="button" class="btn btn-ghost btn-sm"
                                                                wire:click="startReturn({{ $line->id }})"
                                                                @disabled($line->loaded_net <= 0)>Take off truck</button>
                                                    @endif
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <div class="text-sm text-muted" style="margin-top:.6rem;">
                                <strong>Put on</strong> is what originally went onto the truck; <strong>on truck</strong> is
                                what is still there. Warehouse stock follows the second figure automatically.
                            </div>

                        {{-- ---- Print ---- --}}
                        @else
                            <div class="flex" style="justify-content:flex-end;margin-bottom:.7rem;">
                                <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">Print this load</button>
                            </div>
                            <table class="data" style="width:100%;min-width:620px;">
                                <thead>
                                    <tr>
                                        <th>Product Code</th>
                                        <th>Product</th>
                                        <th style="width:110px;text-align:right;">Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $line)
                                        <tr wire:key="pr-{{ $line->id }}">
                                            <td>{{ $line->productcode }}</td>
                                            <td>{{ $line->productname }}</td>
                                            <td style="text-align:right;">{{ number_format($line->loaded_net) }}</td>
                                        </tr>
                                    @endforeach
                                    <tr>
                                        <td colspan="2" style="text-align:right;font-weight:600;">Total</td>
                                        <td style="text-align:right;font-weight:600;">{{ number_format($totals['loaded']) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Type-ahead sources, shared by the header and the new-loading form. --}}
    <datalist id="trucklist">
        @foreach ($this->trucks as $t) <option value="{{ $t }}"></option> @endforeach
    </datalist>
    <datalist id="driverlist">
        @foreach ($this->drivers as $d) <option value="{{ $d }}"></option> @endforeach
    </datalist>

    @include('bil::partials.loading-printouts')
</div>
