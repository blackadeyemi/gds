{{--
    One proforma in full: what the list leaves out (shipment, terms of
    delivery, payment term, NXP, currency, the freight split and the receiving
    account), its priced lines, and where it has got to.

    The milestones are rows in `core.actions` (see Support\ProformaMilestones)
    — the same rows the legacy screens write and read.

    Read live each time it opens, and rendered server-side only while open:
    building it costs several queries, and the other grid modals' x-show keeps
    them in the DOM.
--}}
@if ($showDetails && $this->details)
    @php
        $row = $this->details;
        $lines = $this->detailLines;
        $bank = $this->detailBank;
        $stagesDone = $this->progress;
        $symbol = \Modules\Bpl\Support\ProformaInvoice::symbol($row->symbol);
        $goods = (float) $row->goods_value;
        $freight = (float) $row->freight_charge;

        $facts = [
            'Date' => $row->date,
            'Customer' => $row->customername ?: '—',
            'Customer ref' => $row->customer_ref ?: '—',
            'Shipment' => $row->shipment ?: '—',
            'Terms of delivery' => $row->terms ?: '—',
            'Payment' => $row->payment_terms ?: '—',
            'NXP number' => $row->nxp ?: '—',
            'Currency' => $row->currency ?: '—',
            'Freight' => ($row->freight ?: '—') . ' · ' . (int) $row->container . ' × ' . number_format((float) $row->freight_price, 2),
        ];

        $stages = [
            \Modules\Bpl\Support\ProformaMilestones::APPROVED => 'The customer has accepted the proforma. The NXP number is raised against this.',
            \Modules\Bpl\Support\ProformaMilestones::PRODUCED => 'The order has been made and is ready for its containers.',
            \Modules\Bpl\Support\ProformaMilestones::LOADED => 'The containers have been loaded.',
        ];
    @endphp

    <div class="modal-backdrop" x-data @keydown.escape.window="$wire.closeDetails()" style="display:flex;">
        <div class="modal-card" style="max-width:840px;width:96%;" @click.outside="$wire.closeDetails()">
            <div class="modal-head">
                <div>
                    <h3 class="modal-title mono" style="margin:0;">{{ $row->number }}</h3>
                    <p class="text-muted text-sm" style="margin:.2rem 0 0;">
                        {{ $row->customername ?: '—' }}
                        @if ($row->deleted_at)
                            <span class="badge badge-danger" style="margin-left:.4rem;">deleted</span>
                        @endif
                    </p>
                </div>
                <button class="modal-close" wire:click="closeDetails">&times;</button>
            </div>

            <div class="modal-body" style="max-height:72vh;overflow:auto;">
                {{-- What the list no longer carries --}}
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.6rem 1.25rem;">
                    @foreach ($facts as $label => $value)
                        <div>
                            <div class="text-muted text-sm">{{ $label }}</div>
                            <div @class(['mono' => in_array($label, ['NXP number', 'Date'], true)])>{{ $value }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- The priced lines --}}
                <div class="table-wrap" style="margin-top:1.25rem;">
                    <table class="data" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th style="width:100px;text-align:right;">Qty/MT</th>
                                <th style="width:120px;text-align:right;">Unit price</th>
                                <th style="width:140px;text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $line)
                                <tr>
                                    <td class="text-sm">{{ $line['fullname'] }}</td>
                                    <td style="text-align:right;">{{ number_format($line['tonnes'], 3) }}</td>
                                    <td style="text-align:right;">
                                        @if ($line['price'] === null)
                                            <span class="badge badge-warning">not priced</span>
                                        @else
                                            {{ number_format($line['price'], 2) }}
                                        @endif
                                    </td>
                                    <td style="text-align:right;">
                                        {{ $line['amount'] === null ? '—' : $symbol . number_format($line['amount'], 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="empty-row">No lines on this order.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3" style="text-align:right;">Goods</th>
                                <th style="text-align:right;">{{ $symbol }}{{ number_format($goods, 2) }}</th>
                            </tr>
                            <tr>
                                <th colspan="3" style="text-align:right;">
                                    Freight ({{ (int) $row->container }} × {{ number_format((float) $row->freight_price, 2) }})
                                </th>
                                <th style="text-align:right;">{{ $symbol }}{{ number_format($freight, 2) }}</th>
                            </tr>
                            <tr>
                                <th colspan="3" style="text-align:right;">Total {{ $row->currency }}</th>
                                <th style="text-align:right;">{{ $symbol }}{{ number_format($goods + $freight, 2) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Where the money goes --}}
                @if ($bank)
                    <div class="card card-pad" style="margin-top:1rem;background:var(--surface-2);">
                        <div class="text-sm" style="font-weight:600;margin-bottom:.4rem;">Receiving account</div>
                        <div class="text-sm">
                            {{ $bank['account'] }} · {{ $bank['bank'] }}{{ $bank['number'] ? ' (' . $bank['number'] . ')' : '' }}
                            @if ($bank['routed'])
                                <div class="text-muted" style="margin-top:.2rem;">
                                    Routed
                                    @if ($bank['intermediary_bank']) via {{ $bank['intermediary_bank'] }} @endif
                                    @if ($bank['correspondent_bank']) / {{ $bank['correspondent_bank'] }} @endif
                                    @if ($bank['further_acc']) · further credit {{ $bank['further_acc'] }} @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Progress --}}
                <div class="text-sm" style="font-weight:600;margin:1.25rem 0 .5rem;">Progress</div>

                @foreach ($stages as $stage => $hint)
                    @php $action = $stagesDone[$stage] ?? null; @endphp
                    <div class="card card-pad" style="margin-bottom:.6rem;{{ $action ? 'border-color:var(--success);' : '' }}">
                        <div style="display:flex;align-items:center;gap:.75rem;">
                            <div style="flex:1;">
                                <div style="font-weight:600;">
                                    {{ \Modules\Bpl\Support\ProformaMilestones::label($stage) }}
                                    @if ($action)
                                        <span class="badge badge-success" style="margin-left:.4rem;">done</span>
                                    @endif
                                </div>
                                <div class="text-muted text-sm" style="margin-top:.2rem;">
                                    @if ($action)
                                        {{ $action['date'] }} · {{ $action['user'] ?: 'unknown' }}
                                    @else
                                        {{ $hint }}
                                    @endif
                                </div>
                            </div>

                            @if ($this->mayDo('edit'))
                                @if ($action)
                                    @php
                                        $locked = $stage === \Modules\Bpl\Support\ProformaMilestones::APPROVED && trim((string) $row->nxp) !== '';
                                    @endphp
                                    <button type="button" class="btn btn-ghost btn-sm"
                                            wire:click="unmarkStage('{{ $stage }}')"
                                            wire:confirm="Take back “{{ \Modules\Bpl\Support\ProformaMilestones::label($stage) }}”?"
                                            @disabled($locked)
                                            title="{{ $locked ? 'NXP ' . $row->nxp . ' was issued against this approval.' : 'Take this back' }}">
                                        Undo
                                    </button>
                                @else
                                    <button type="button" class="btn btn-primary btn-sm" wire:click="markStage('{{ $stage }}')">
                                        Mark done
                                    </button>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach

                <p class="text-muted text-sm" style="margin:.75rem 0 0;">
                    Progress is recorded against your username and today's date. The legacy screens read the same
                    records. They do not send the old notification emails.
                </p>
            </div>

            <div class="modal-foot">
                <a href="{{ route('bpl.sales.proformas.print', $row->order_id) }}" target="_blank" rel="noopener"
                   class="btn btn-ghost">Print proforma</a>
                <button type="button" class="btn btn-ghost" wire:click="closeDetails">Close</button>
            </div>
        </div>
    </div>
@endif
