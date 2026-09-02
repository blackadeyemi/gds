{{--
    Waybill. Reproduces the legacy printout/sales_waybill.php: the same heading
    (logo / CODE93 barcode / title with the DELIVERY number and the waybill
    date), the same info card, the same product table, and the same three
    signature lines.

    The card's left column is what the legacy called "Invoice Number(s)" — the
    sales order ids. It is the one place in the chain those are labelled that
    way, and it is kept, because this is the sheet the haulier is paid against
    and the wording is what the office reconciles by.

    TRANSPORTATION COST is what makes it a waybill rather than another copy of
    the delivery note, so it is on the card, formatted to two decimals as the
    legacy printed it.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waybill Print Out{{ count($waybills) === 1 ? ' — ' . $waybills[0]->barcode : '' }}</title>
    <link rel="icon" href="{{ asset('images/bilicon.ico') }}" />
    <style type="text/css">
        * { box-sizing:border-box; }
        body { font-family:Arial, Helvetica, sans-serif; font-size:14px; color:#000; background:#f4f4f4; margin:0; padding:16px; }

        .sheet { background:#fff; max-width:1000px; margin:0 auto 22px; padding:18px 22px 28px; border:1px solid #ddd; page-break-after:always; }
        .sheet:last-of-type { page-break-after:auto; }

        table.wrapper { width:100%; border-collapse:collapse; }
        table.wrapper td { width:33%; vertical-align:middle; border:0; padding:0; }
        .logo img { width:150px; }
        .barcodeWrap { margin:auto; text-align:center; }
        .title { text-align:right; }
        .title h2 { font-size:25px; margin:0; }
        .title small { font-size:11px; }
        hr { border:0; border-top:3px solid #607d8b; margin:10px 0 14px; }

        .card { border:1px solid #e6e6e6; border-radius:3px; line-height:1.8; padding:10px 14px; margin-bottom:16px; }
        table.table-grid { width:100%; border-collapse:collapse; }
        table.table-grid td.layout { width:50%; vertical-align:top; border:0; padding:0 10px 0 0; }
        .card p { margin:0; }
        .card span { font-weight:600; margin-left:10px; text-transform:uppercase; }

        .f-f-cursive h2, .f-f-cursive span, .signature label { font-family:cursive; }

        table.lines { width:100%; border-collapse:collapse; }
        table.lines th, table.lines td { padding:5px 10px; border:1px solid #ccc; text-align:left; }
        table.lines thead th, table.lines tfoot th { background:#607d8b; color:#fff; font-weight:600; }
        table.lines tfoot th { background:#f0f0f0; color:#000; }
        .foc { color:#c62828; font-weight:bold; }

        .signature { max-width:90%; margin:40px auto 0; line-height:1.1; font-weight:bold; }
        .signature label { display:block; margin-bottom:2.5em; }
        .signature .left { float:left; text-align:center; }
        .signature .right { float:right; text-align:center; }
        .signature .clear { clear:both; }
        .signature .customer { text-align:center; margin-top:22px; }
        .signature small { font-size:11px; }

        .hidden-date { display:none; }

        .toolbar { max-width:1000px; margin:0 auto 14px; text-align:right; }
        .toolbar button { font:inherit; padding:7px 16px; border:1px solid #607d8b; background:#607d8b; color:#fff; border-radius:4px; cursor:pointer; }

        @media print {
            body { background:#fff; padding:0; font-size:12px; }
            .sheet { border:0; margin:0; max-width:none; padding:10px 14px; }
            .card { line-height:1.7; }
            .toolbar { display:none; }
            .hidden-date { display:block; margin:14px 0 14px 34px; }
            @page { margin:8mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print {{ count($waybills) }} waybill(s) again</button>
    </div>

    @foreach ($waybills as $i => $waybill)
        <div class="sheet">
            <p class="hidden-date"><small>Printed On: {{ now()->format('d F, Y @H:i A') }}</small></p>

            <table class="wrapper">
                <tr>
                    <td class="logo"><img src="{{ asset('images/belimpex_brands logo.png') }}" alt="Belimpex"></td>
                    <td><div class="barcodeWrap" id="bc{{ $i }}"></div></td>
                    <td class="title f-f-cursive">
                        <h2>Waybill</h2>
                        <small>number:</small> <span>{{ $waybill->deliverynumber }}</span> /
                        <small>date:</small> <span>{{ $waybill->dateofwaybill }}</span>
                    </td>
                </tr>
            </table>

            <hr />

            <div class="card f-f-cursive">
                <table class="table-grid">
                    <tr>
                        <td class="layout">
                            <p><label>Customer:</label> <span>{{ $waybill->customername ?: '—' }}</span></p>
                            <p><label>Invoice Number(s):</label> <span>{{ implode(', ', $waybill->orders) ?: '—' }}</span></p>
                            <p><label>Delivery Number:</label> <span>{{ $waybill->deliverybarcode ?: '—' }}</span></p>
                            <p><label>Receipt Number:</label> <span>{{ $waybill->receiptnumber ?? '—' }}</span></p>
                            <p><label>Warehouse Location:</label> <span>{{ $waybill->warehouse ?: '—' }}</span></p>
                        </td>
                        <td class="layout">
                            @if ($waybill->customeraddress)
                                <p><label>Customer Address:</label> <span>{{ $waybill->customeraddress }}</span></p>
                            @endif
                            <p><label>Transporter:</label> <span>{{ $waybill->transportername ?: '—' }}</span></p>
                            <p><label>Truck Number:</label> <span>{{ $waybill->trucknumber ?: '—' }}</span></p>
                            <p><label>Truck Driver:</label> <span>{{ $waybill->truckdriver ?: '—' }}</span></p>
                            <p><label>Transportation Cost:</label> <span>&#x20A6;{{ number_format($waybill->transportcost, 2) }}</span></p>
                        </td>
                    </tr>
                </table>
            </div>

            <table class="lines">
                <thead>
                    <tr>
                        <th style="width:60px;">S/N</th>
                        <th style="width:170px;">PRODUCT CODE</th>
                        <th>PRODUCT</th>
                        <th style="width:180px;">QUANTITY</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($waybill->lines as $n => $line)
                        <tr>
                            <td>{{ $n + 1 }}</td>
                            <td>{{ $line->productcode }}</td>
                            <td>{{ $line->productname }}@if ($line->foc) <span class="foc">(FOC)</span>@endif</td>
                            <td>{{ number_format($line->quantityloaded) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                {{-- A total under a single line just repeats it, so the legacy
                     sheet omits it there and so does this. --}}
                @if (count($waybill->lines) > 1)
                    <tfoot>
                        <tr>
                            <th colspan="3">TOTAL</th>
                            <th>{{ number_format($waybill->total) }}</th>
                        </tr>
                    </tfoot>
                @endif
            </table>

            <div class="signature">
                <div class="left">
                    <label>Sent By:</label>
                    <p><strong>____________________________</strong><br /><small>DATE &amp; SIGNATURE</small></p>
                </div>
                <div class="right">
                    <label>Received by Driver:</label>
                    <p><strong>____________________________</strong><br /><small>DATE &amp; SIGNATURE</small></p>
                </div>
                <div class="clear"></div>
                <div class="customer">
                    <label>Customer:</label>
                    <p><strong>____________________________</strong><br /><small>DATE &amp; SIGNATURE</small></p>
                </div>
            </div>
        </div>
    @endforeach

    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <script src="{{ asset('js/jquery-barcode.js') }}"></script>
    <script type="text/javascript">
        // CODE93 at fontSize 14 — the same symbology and size the legacy sheet
        // prints, so the scanners on the gate are unchanged.
        var codes = @json(collect($waybills)->pluck('barcode'));
        window.addEventListener('load', function () {
            codes.forEach(function (code, i) {
                $('#bc' + i).barcode(String(code), 'code93', { fontSize: 14 });
            });

            // AFTER the barcodes are drawn, or they print as empty boxes. No
            // window.close() on afterprint: it fires on cancel too, and closing
            // the tab on someone who only wanted to look is worse.
            window.print();
        });
    </script>
</body>
</html>
