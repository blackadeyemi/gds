{{--
    Returns — the two legacy return screens as one.

    The left column is what has already come back. New Return, on the right,
    starts from the customer rather than from an order, because that is how a
    return actually arrives: bundles turn up with no paperwork of their own and
    the office works out which delivery they came off.
--}}
@php
    $selected = $this->selected;
    $lines = $this->lines;
    $totals = $this->totals();
@endphp

<div>
    <div class="page-head" style="display:flex;align-items:flex-start;gap:1rem;">
        <div>
            <h1>Returns</h1>
            <p>Goods a customer has sent back after delivery. Sellable bundles go back into warehouse stock; rejected ones are held as damaged.</p>
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
                    <h2 class="card-title">Recent returns</h2>
                    {{-- Say what the list is a slice of, or a short list reads
                         as "this is everything there has ever been". --}}
                    <div class="text-sm text-muted">
                        @if ($search !== '')
                            {{ count($this->returns) }}{{ $this->hasMore() ? '+' : '' }} match{{ count($this->returns) === 1 ? '' : 'es' }}
                        @else
                            showing {{ count($this->returns) }} of {{ number_format($this->returnCount) }}
                        @endif
                    </div>
                </div>
            </div>

            <div class="card-pad">
                @if ($this->canCreate())
                    <button type="button" class="btn btn-primary"
                            style="width:100%;margin-bottom:.7rem;" wire:click="startNew">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        New Return
                    </button>
                @endif

                <div class="form-group">
                    <input type="search" class="form-control" placeholder="Customer, date, return number…"
                           wire:model.live.debounce.400ms="search">
                </div>

                <div style="max-height:36rem;overflow:auto;margin:0 -0.4rem;">
                    @forelse ($this->returns as $r)
                        <button type="button" wire:key="r-{{ $r->dateofreturn }}-{{ $r->returnnumber }}"
                                wire:click="openReturn('{{ $r->dateofreturn }}', {{ $r->returnnumber }})"
                                class="btn btn-ghost"
                                style="display:block;width:100%;text-align:left;padding:0.55rem 0.7rem;margin-bottom:0.2rem;border-radius:6px;{{ $dateofreturn === $r->dateofreturn && $returnnumber === (int) $r->returnnumber ? 'background:var(--accent-soft,rgba(59,130,246,.12));' : '' }}">
                            <div style="display:flex;align-items:center;gap:.4rem;">
                                <span style="font-weight:600;">Return #{{ $r->returnnumber }}</span>
                                <span class="text-sm text-muted">{{ $r->dateofreturn }}</span>
                            </div>
                            <div class="text-sm text-muted">{{ $r->customername ?: 'No customer' }}</div>
                            <div class="text-sm text-muted">
                                {{ $r->line_count }} line(s) · {{ number_format($r->returned) }} bundles
                                @if ($r->rejected > 0)
                                    · <span style="color:#b45309;">{{ number_format($r->rejected) }} rejected</span>
                                @endif
                            </div>
                        </button>
                    @empty
                        <div class="text-muted" style="padding:0.8rem;">
                            @if ($search !== '')
                                Nothing matches “{{ $search }}”.
                            @else
                                No returns recorded yet.
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
            @if ($mode === 'new')
                @include('bil::partials.returns-new')
            @elseif (! $selected)
                <div class="card card-pad text-muted">
                    Pick a return on the left to correct or reprint it — or start a <strong>New Return</strong>.
                </div>
            @else
                <div class="card" style="margin-bottom:1rem;">
                    <div class="card-head" style="flex-wrap:wrap;gap:0.75rem;">
                        <div>
                            <h2 class="card-title">Return #{{ $selected->returnnumber }}</h2>
                            <div class="text-sm text-muted">
                                {{ $selected->customername ?: 'no customer' }} · {{ $selected->dateofreturn }}
                                @if ($selected->warehouse) · {{ $selected->warehouse }} @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2" style="margin-left:auto;flex-wrap:wrap;">
                            <span class="badge badge-muted">{{ $totals['lines'] }} line(s)</span>
                            <span class="badge badge-success">{{ number_format($totals['to_stock']) }} back to stock</span>
                            @if ($totals['rejected'] > 0)
                                <span class="badge" style="background:rgba(217,119,6,.14);color:#b45309;">{{ number_format($totals['rejected']) }} rejected</span>
                            @endif
                        </div>
                    </div>

                    <div class="card-pad">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.6rem 1.4rem;">
                            <div><div class="form-label">Customer</div><div>{{ $selected->customername ?: '—' }}</div></div>
                            <div><div class="form-label">Sales order(s)</div><div>{{ implode(', ', $selected->orders) ?: '—' }}</div></div>
                            <div><div class="form-label">Date of return</div><div>{{ $selected->dateofreturn }}</div></div>
                            <div><div class="form-label">Recorded by</div><div>{{ $selected->username ?: '—' }}</div></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-pad" style="padding-bottom:0;">
                        <div class="flex gap-2" style="border-bottom:1px solid var(--border);">
                            @foreach (['lines' => 'Lines', 'print' => 'Print'] as $key => $label)
                                <button type="button" wire:click="setTab('{{ $key }}')"
                                        class="btn btn-ghost btn-sm"
                                        style="border-radius:6px 6px 0 0;margin-bottom:-1px;{{ $tab === $key ? 'border-bottom:2px solid var(--brand,#3b82f6);font-weight:600;' : '' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="card-pad" style="overflow-x:auto;">
                        @if ($tab === 'lines')
                            <table class="data" style="width:100%;min-width:760px;">
                                <thead>
                                    <tr>
                                        <th>Order</th>
                                        <th>Product</th>
                                        <th style="width:100px;text-align:right;">Returned</th>
                                        <th style="width:100px;text-align:right;">Rejected</th>
                                        <th style="width:110px;text-align:right;">Back to stock</th>
                                        <th style="width:200px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $line)
                                        <tr wire:key="rl-{{ $line->id }}">
                                            <td class="text-sm">{{ $line->orderid ?: '—' }}</td>
                                            <td>
                                                <div>{{ $line->productname ?: '—' }}</div>
                                                <div class="text-sm text-muted">{{ $line->productcode }}@if ($line->foc) · <span class="badge" style="background:rgba(198,40,40,.14);color:#c62828;">FOC</span>@endif</div>
                                            </td>
                                            <td style="text-align:right;">
                                                @if ($editingLine === $line->id)
                                                    <input type="number" min="1" class="form-control" style="text-align:right;"
                                                           wire:model="editReturned">
                                                @else
                                                    <strong>{{ number_format($line->quantityreturned) }}</strong>
                                                @endif
                                            </td>
                                            <td style="text-align:right;">
                                                @if ($editingLine === $line->id)
                                                    <input type="number" min="0" class="form-control" style="text-align:right;"
                                                           wire:model="editRejected">
                                                @elseif ($line->quantityrejected > 0)
                                                    <span style="color:#b45309;">{{ number_format($line->quantityrejected) }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td style="text-align:right;">{{ number_format($line->to_stock) }}</td>
                                            <td style="text-align:right;">
                                                @if ($editingLine === $line->id)
                                                    <button type="button" class="btn btn-primary btn-sm" wire:click="saveLine">Save</button>
                                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('editingLine', null)">Cancel</button>
                                                    @error('editReturned') <div class="form-error">{{ $message }}</div> @enderror
                                                @elseif ($confirmingRemove === $line->id)
                                                    <span class="text-sm">Remove?</span>
                                                    <button type="button" class="btn btn-danger btn-sm" wire:click="removeLine">Yes</button>
                                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmingRemove', null)">No</button>
                                                @else
                                                    @if ($this->canModify())
                                                        <button type="button" class="btn btn-ghost btn-sm"
                                                                wire:click="editLine({{ $line->id }}, {{ $line->quantityreturned }}, {{ $line->quantityrejected }})">Correct</button>
                                                    @endif
                                                    @if ($this->canDelete())
                                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmingRemove', {{ $line->id }})">Remove</button>
                                                    @endif
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <div class="text-sm text-muted" style="margin-top:.6rem;">
                                <strong>Rejected</strong> is part of what was returned, not extra to it — it is held in the
                                damaged-goods warehouse instead of going back on sale.
                            </div>

                        @else
                            <div class="flex" style="justify-content:flex-end;margin-bottom:.7rem;">
                                <a href="{{ $this->printUrlFor($selected->dateofreturn . '|' . $selected->returnnumber) }}"
                                   target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Print return note</a>
                            </div>
                            <table class="data" style="width:100%;min-width:620px;">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">S/N</th>
                                        <th>Product Code</th>
                                        <th>Product</th>
                                        <th style="width:110px;text-align:right;">Returned</th>
                                        <th style="width:110px;text-align:right;">Rejected</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $n => $line)
                                        <tr wire:key="rp-{{ $line->id }}">
                                            <td>{{ $n + 1 }}</td>
                                            <td>{{ $line->productcode }}</td>
                                            <td>{{ $line->productname }}</td>
                                            <td style="text-align:right;">{{ number_format($line->quantityreturned) }}</td>
                                            <td style="text-align:right;">{{ number_format($line->quantityrejected) }}</td>
                                        </tr>
                                    @endforeach
                                    @if (count($lines) > 1)
                                        <tr>
                                            <td colspan="3" style="text-align:right;font-weight:600;">Total</td>
                                            <td style="text-align:right;font-weight:600;">{{ number_format($totals['returned']) }}</td>
                                            <td style="text-align:right;font-weight:600;">{{ number_format($totals['rejected']) }}</td>
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

    @include('bil::partials.returns-printouts')
</div>
