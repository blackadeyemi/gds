{{--
    Movement detail for one stock line: everything that put bundles in, and
    everything the sales side took out.

    Read live from the underlying rows each time it opens — receipts and
    adjustments on `core`, the sales chain on `bil`. Nothing here is cached onto
    the stock row, so the modal can never disagree with the reconciled total.
--}}
{{--
    Rendered server-side only while open. The other grid modals use Alpine
    `x-show`, which keeps them in the DOM — fine for a form, wrong here, where
    building the body costs eight queries across two connections.
--}}
@if ($showMovements)
    @php
        $m = $this->movements;
        $in = $m['incoming'];
        $out = $m['outgoing'];
        $inCount = array_sum(array_map('count', $in));
        $outCount = array_sum(array_map('count', $out));
        $cap = \Modules\Bil\Support\FinishedGoodsStockMovements::LIMIT;
    @endphp

<div class="modal-backdrop" x-data @keydown.escape.window="$wire.closeMovements()" style="display:flex;">
    <div class="modal-card" style="max-width:1080px;width:96%;" @click.outside="$wire.closeMovements()">
        <div class="modal-head">
            <div>
                <h3 class="modal-title" style="margin:0;">{{ $productLabel }}</h3>
                <p class="text-muted text-sm" style="margin:.2rem 0 0;">
                    {{ $warehouseLabel }} &middot; <strong>{{ number_format($currentBundles) }}</strong> bundles in stock
                </p>
            </div>
            <button class="modal-close" wire:click="closeMovements">&times;</button>
        </div>

        <div class="modal-body" style="max-height:70vh;overflow:auto;">
                {{-- Tabs, and how far back to look --}}
                <div style="display:flex;gap:.4rem;align-items:center;border-bottom:1px solid var(--line);margin-bottom:1rem;">
                    @foreach (['incoming' => 'Incoming', 'outgoing' => 'Outgoing'] as $key => $label)
                        <button type="button"
                                wire:click="$set('movementTab', '{{ $key }}')"
                                class="btn btn-ghost btn-sm"
                                style="border-bottom:2px solid {{ $movementTab === $key ? 'var(--primary,#2563eb)' : 'transparent' }};border-radius:0;font-weight:{{ $movementTab === $key ? '700' : '400' }};">
                            {{ $label }}
                            <span class="badge badge-muted">{{ number_format($key === 'incoming' ? $inCount : $outCount) }}</span>
                        </button>
                    @endforeach

                    <div style="margin-left:auto;display:flex;align-items:center;gap:.4rem;padding-bottom:.35rem;">
                        <span class="text-muted text-sm">Last</span>
                        @foreach (\Modules\Bil\Support\FinishedGoodsStockMovements::WINDOWS as $w)
                            <button type="button" wire:click="$set('movementWindow', {{ $w }})"
                                    class="btn btn-sm {{ $movementWindow === $w ? 'btn-primary' : 'btn-ghost' }}">
                                {{ $w }}d
                            </button>
                        @endforeach
                    </div>
                </div>

                @php
                    $sections = $movementTab === 'incoming'
                        ? [
                            ['Warehouse receipts', 'Pallets scanned in at a gate.', $in['receipts'], [
                                ['Barcode', fn ($r) => $r->barcode],
                                ['Gate', fn ($r) => $r->gate ?? '—'],
                                ['Bundles', fn ($r) => number_format($r->bundles)],
                                ['Date', fn ($r) => $r->date_of_entrance],
                                ['By', fn ($r) => $r->username ?: '—'],
                                ['Source', fn ($r) => $r->is_historic
                                    ? '<span class="badge badge-muted">Historic</span>'
                                    : '<span class="badge badge-success">gds</span>'],
                            ]],
                            ['Adjustments', 'Manual corrections, including the opening balance.', $in['adjustments'], [
                                ['Bundles', fn ($r) => sprintf('%+d', $r->bundles)],
                                ['Reason', fn ($r) => e($r->reason ?: '—')],
                                ['By', fn ($r) => e($r->username ?: '—')],
                                ['When', fn ($r) => \Illuminate\Support\Carbon::parse($r->created_at)->format('d M Y H:i')],
                            ]],
                            ['Sales returns', 'Returned by the customer, back into stock.', $in['sales_returns'], [
                                ['Return #', fn ($r) => $r->returnnumber],
                                ['Order', fn ($r) => e($r->orderid)],
                                ['Customer', fn ($r) => e($r->customerid ?? '—')],
                                ['Returned', fn ($r) => number_format($r->quantityreturned)],
                                ['Rejected', fn ($r) => number_format($r->quantityrejected)],
                                ['Date', fn ($r) => $r->dateofreturn],
                                ['By', fn ($r) => e($r->username ?: '—')],
                            ]],
                            ['Unloaded', 'Taken off a load before it left.', $in['loading_returns'], [
                                ['Load barcode', fn ($r) => e($r->barcode)],
                                ['Order', fn ($r) => e($r->orderid)],
                                ['Unloaded', fn ($r) => number_format($r->quantityunloaded)],
                                ['When', fn ($r) => $r->timestamp ? \Illuminate\Support\Carbon::createFromTimestamp((int) $r->timestamp)->format('d M Y') : '—'],
                                ['By', fn ($r) => e($r->username ?: '—')],
                            ]],
                        ]
                        : [
                            ['Orders', 'Ordered — not a stock movement on its own.', $out['orders'], [
                                ['Order', fn ($r) => e($r->orderid)],
                                ['Customer', fn ($r) => e($r->customerid ?? '—')],
                                ['Quantity', fn ($r) => number_format($r->quantityordered)],
                                ['FOC', fn ($r) => $r->foc ? '<span class="badge badge-muted">FOC</span>' : '—'],
                                ['Date', fn ($r) => $r->dateoforder],
                                ['By', fn ($r) => e($r->username ?? '—')],
                            ]],
                            ['Loaded out', 'Left the warehouse — this is what reduces stock.', $out['loadings'], [
                                ['Load #', fn ($r) => $r->loadnumber],
                                ['Load barcode', fn ($r) => e($r->barcode)],
                                ['Order', fn ($r) => e($r->orderid)],
                                ['Quantity', fn ($r) => number_format($r->quantityloaded)],
                                ['Cage room', fn ($r) => e($r->cageroomcode ?: '—')],
                                ['Date', fn ($r) => $r->dateofloading],
                                ['Status', fn ($r) => $r->status
                                    ? '<span class="badge badge-muted">' . e($r->status) . '</span>'
                                    : '<span class="badge badge-success">live</span>'],
                            ]],
                            ['Delivered', 'Confirmed out on a delivery note.', $out['deliveries'], [
                                ['Delivery #', fn ($r) => $r->deliverynumber],
                                ['Barcode', fn ($r) => e($r->barcode)],
                                ['Load #', fn ($r) => $r->loadnumber],
                                ['Customer', fn ($r) => e($r->deliverycustomerid ?? '—')],
                                ['Date', fn ($r) => $r->dateofdelivery],
                                ['By', fn ($r) => e($r->username ?: '—')],
                            ]],
                            ['Waybills', 'The end of the chain.', $out['waybills'], [
                                ['Barcode', fn ($r) => e($r->barcode)],
                                ['Delivery #', fn ($r) => $r->deliverynumber],
                                ['Receipt #', fn ($r) => $r->receiptnumber ?? '—'],
                                ['Date', fn ($r) => $r->dateofwaybill],
                                ['By', fn ($r) => e($r->username ?: '—')],
                            ]],
                        ];
                @endphp

                @foreach ($sections as [$title, $note, $rows, $cols])
                    <div style="margin-bottom:1.5rem;">
                        <h3 style="margin:0 0 .15rem;font-size:.95rem;">
                            {{ $title }}
                            <span class="badge badge-muted">{{ number_format(count($rows)) }}</span>
                        </h3>
                        <p class="text-muted text-sm" style="margin:0 0 .5rem;">{{ $note }}</p>

                        @if (count($rows) === 0)
                            <div class="text-muted text-sm" style="padding:.5rem .75rem;border:1px dashed var(--line);border-radius:8px;">
                                None in the last {{ $movementWindow }} days.
                            </div>
                        @else
                            <div class="table-wrap">
                                <table class="data">
                                    <thead>
                                        <tr>@foreach ($cols as [$label, $_]) <th>{{ $label }}</th> @endforeach</tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($rows as $row)
                                            <tr>@foreach ($cols as [$_, $render]) <td>{!! $render($row) !!}</td> @endforeach</tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if (count($rows) >= $cap)
                                <p class="text-muted text-sm" style="margin:.35rem 0 0;">
                                    Capped at {{ $cap }} rows even within {{ $movementWindow }} days —
                                    narrow the window, or use the reports for the full history.
                                </p>
                            @endif
                        @endif
                    </div>
            @endforeach
        </div>
    </div>
</div>
@endif
