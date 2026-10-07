{{--
    Payments made against one waybill, with the form that adds or corrects one.

    Read live each time it opens: these rows ARE the record's balance (see
    BplWaybillPayment::recalculate()), so a cached copy could show a total the
    row itself disagrees with.

    Rendered server-side only while open, like the BIL stock movements modal —
    the other grid modals stay in the DOM under x-show, which is fine for a
    form and wrong for a body that costs a query to build.
--}}
@if ($showPayments)
    @php
        $rows = $this->payments;
        $agreed = (float) ($paymentContext['amount'] ?? 0);
        $paid = (float) $rows->sum('amount');
        $left = round($agreed - $paid, 2);
    @endphp

    <div class="modal-backdrop" x-data @keydown.escape.window="$wire.closePayments()" style="display:flex;">
        <div class="modal-card" style="max-width:820px;width:96%;" @click.outside="$wire.closePayments()">
            <div class="modal-head">
                <div>
                    <h3 class="modal-title mono" style="margin:0;">{{ $paymentContext['barcode'] ?? '' }}</h3>
                    <p class="text-muted text-sm" style="margin:.2rem 0 0;">
                        {{ $paymentContext['transporter'] ?? '—' }} &middot; {{ $paymentContext['customer'] ?? '—' }}
                    </p>
                </div>
                <button class="modal-close" wire:click="closePayments">&times;</button>
            </div>

            <div class="modal-body" style="max-height:70vh;overflow:auto;">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1rem;">
                    @foreach ([
                        ['Agreed', $agreed, 'inherit'],
                        ['Paid', $paid, 'var(--success)'],
                        ['Outstanding', $left, $left > 0 ? 'var(--danger)' : 'var(--muted)'],
                    ] as [$label, $value, $colour])
                        <div class="card card-pad" style="background:var(--surface-2);">
                            <div class="text-muted text-sm">{{ $label }}</div>
                            <div style="font-size:1.35rem;font-weight:700;color:{{ $colour }};">
                                &#8358;{{ number_format($value, 2) }}
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="table-wrap">
                    <table class="data" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:120px;">Date</th>
                                <th style="width:160px;text-align:right;">Amount (₦)</th>
                                <th>Voucher No.</th>
                                <th style="width:120px;">Recorded</th>
                                <th style="width:90px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr wire:key="wbh-{{ $row->id }}" @class(['is-selected' => $editingHistoryId === $row->id])>
                                    <td>{{ $row->payment_date?->format('d/m/Y') ?? '—' }}</td>
                                    <td style="text-align:right;">{{ number_format((float) $row->amount, 2) }}</td>
                                    <td>{{ $row->payment_note ?: '—' }}</td>
                                    <td class="text-muted text-sm">
                                        {{ $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at)->format('d/m/Y') : '—' }}
                                    </td>
                                    <td style="text-align:right;white-space:nowrap;">
                                        @if ($this->mayDo('edit'))
                                            <button type="button" class="btn btn-ghost btn-icon btn-sm"
                                                    wire:click="editPayment({{ $row->id }})" title="Correct this payment">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
                                            </button>
                                        @endif
                                        @if ($this->mayDo('delete'))
                                            <button type="button" class="btn btn-danger btn-icon btn-sm"
                                                    wire:click="deletePayment({{ $row->id }})"
                                                    wire:confirm="Remove this payment of {{ number_format((float) $row->amount, 2) }}?"
                                                    title="Remove this payment">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="empty-row">Nothing paid against this waybill yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($this->mayDo('edit'))
                    <div class="card card-pad" style="margin-top:1rem;">
                        <div class="text-sm" style="font-weight:600;margin-bottom:.6rem;">
                            {{ $editingHistoryId ? 'Correct payment' : 'Record a payment' }}
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1.4fr auto;gap:.75rem;align-items:end;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Amount (₦)</label>
                                <input type="number" class="form-control qty-input" wire:model="historyAmount" min="0" step="100">
                                @error('historyAmount') <div class="form-error">{{ $message }}</div> @enderror
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Date</label>
                                @include('bil::partials.date-field', ['model' => 'historyDate', 'compact' => true])
                                @error('historyDate') <div class="form-error">{{ $message }}</div> @enderror
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Voucher No.</label>
                                <input type="text" class="form-control" wire:model="historyNote" maxlength="100" autocomplete="off">
                                @error('historyNote') <div class="form-error">{{ $message }}</div> @enderror
                            </div>
                            <div class="flex gap-2">
                                <button type="button" class="btn btn-primary" wire:click="savePayment"
                                        wire:loading.attr="disabled" wire:target="savePayment">
                                    {{ $editingHistoryId ? 'Save' : 'Add' }}
                                </button>
                                @if ($editingHistoryId)
                                    <button type="button" class="btn btn-ghost" wire:click="resetHistoryForm">Cancel</button>
                                @endif
                            </div>
                        </div>
                        <div class="form-hint" style="margin-top:.5rem;">
                            At most &#8358;{{ number_format($this->outstanding(), 2) }} may be recorded here.
                            A zero amount records a voucher without moving money.
                        </div>
                    </div>
                @endif
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" wire:click="closePayments">Close</button>
            </div>
        </div>
    </div>
@endif
