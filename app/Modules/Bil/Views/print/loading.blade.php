{{--
    Loading print-out. Reproduces the legacy printout/sales_loading.php as it
    prints today — the same three-part heading (logo / CODE93 barcode / title
    with number and date), the same bordered two-column info card, the same
    S/N + code + product + quantity table with a TOTAL foot, and the same
    Sent By / Received By signature block.

    Kept faithful because a cageroom clerk, a driver and a security post all
    read this sheet, and none of them asked for a new one.

    The one addition: it takes a LIST of loads and page-breaks between them, so
    a day's work prints in one go. The legacy screen made you open a browser tab
    per load.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loading Print Out{{ count($loads) === 1 ? ' — ' . $loads[0]->barcode : '' }}</title>
    <link rel="icon" href="{{ asset('images/bilicon.ico') }}" />
    <style type="text/css">
        * { box-sizing:border-box; }
        body { font-family:Arial, Helvetica, sans-serif; font-size:14px; color:#000; background:#f4f4f4; margin:0; padding:16px; }

        .sheet { background:#fff; max-width:1000px; margin:0 auto 22px; padding:18px 22px 28px; border:1px solid #ddd; page-break-after:always; }
        .sheet:last-of-type { page-break-after:auto; }

        /* Heading: logo left, barcode centre, title right. */
        table.wrapper { width:100%; border-collapse:collapse; }
        table.wrapper td { width:33%; vertical-align:middle; border:0; padding:0; }
        .logo img { width:150px; }
        .barcodeWrap { margin:auto; text-align:center; }
        .title { text-align:right; }
        .title h2 { font-size:25px; margin:0; }
        .title small { font-size:11px; }
        hr { border:0; border-top:3px solid #607d8b; margin:10px 0 14px; }

        /* Info card: labels plain, values bold and upper-case. */
        .card { border:1px solid #e6e6e6; border-radius:3px; line-height:1.8; padding:10px 14px; margin-bottom:16px; }
        table.table-grid { width:100%; border-collapse:collapse; }
        table.table-grid td.layout { width:50%; vertical-align:top; border:0; padding:0 10px 0 0; }
        .card p { margin:0; }
        .card span { font-weight:600; margin-left:10px; text-transform:uppercase; }

        /* The cursive family the legacy sheet uses for the title and values. */
        .f-f-cursive h2, .f-f-cursive span, .signature label { font-family:cursive; }

        table.lines { width:100%; border-collapse:collapse; }
        table.lines th, table.lines td { padding:5px 10px; border:1px solid #ccc; text-align:left; }
        table.lines thead th, table.lines tfoot th { background:#607d8b; color:#fff; font-weight:600; }
        table.lines tfoot th { background:#f0f0f0; color:#000; }
        /* Free of charge — it changes what is owed, so it is never quiet. */
        .foc { color:#c62828; font-weight:bold; }

        .signature { max-width:90%; margin:40px auto 0; line-height:1.1; font-weight:bold; overflow:hidden; }
        .signature label { display:block; margin-bottom:2.5em; }
        .signature .left { float:left; text-align:center; }
        .signature .right { float:right; text-align:center; }
        .signature small { font-size:11px; }

        .hidden-date { display:none; }

        .toolbar { max-width:1000px; margin:0 auto 14px; text-align:right; }
        .toolbar button { font:inherit; padding:7px 16px; border:1px solid #607d8b; background:#607d8b; color:#fff; border-radius:4px; cursor:pointer; }

        @media print {
            body { background:#fff; padding:0; font-size:12px; }
            .sheet { border:0; margin:0; max-width:none; padding:10px 14px; }
            .card { line-height:1.7; }
            .toolbar { display:none; }
            /* Only on paper, so the desk copy says when it was run. */
            .hidden-date { display:block; margin:14px 0 14px 34px; }
            @page { margin:8mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print {{ count($loads) }} load(s)</button>
    </div>

    @foreach ($loads as $i => $load)
        <div class="sheet">
            <p class="hidden-date"><small>Printed On: {{ now()->format('d F, Y @H:i A') }}</small></p>

            <table class="wrapper">
                <tr>
                    <td class="logo"><img src="{{ asset('images/belimpex_brands logo.png') }}" alt="Belimpex"></td>
                    <td><div class="barcodeWrap" id="bc{{ $i }}"></div></td>
                    <td class="title f-f-cursive">
                        <h2>Loading</h2>
                        <small>number:</small> <span>{{ $load->loadnumber }}</span> /
                        <small>date:</small> <span>{{ $load->dateofloading }}</span>
                    </td>
                </tr>
            </table>

            <hr />

            <div class="card f-f-cursive">
                <table class="table-grid">
                    <tr>
                        <td class="layout">
                            <p><label>Customer:</label> <span>{{ $load->customername ?: '—' }}</span></p>
                            <p><label>Sales Order(s):</label> <span>{{ implode(', ', $load->orders) ?: '—' }}</span></p>
                            <p><label>Warehouse:</label> <span>{{ $load->warehouse ?: '—' }}</span></p>
                            <p><label>Cage room(s):</label> <span>{{ implode(', ', $load->cagerooms) ?: '—' }}</span></p>
                            <p><label>Loader:</label> <span>{{ $load->loader ?: '—' }}</span></p>
                        </td>
                        <td class="layout">
                            @if ($load->customeraddress)
                                <p><label>Customer Address:</label> <span>{{ $load->customeraddress }}</span></p>
                            @endif
                            <p><label>Transporter:</label> <span>{{ $load->transportername ?: '—' }}</span></p>
                            <p><label>Vehicle Number:</label> <span>{{ $load->trucknumber ?: '—' }}</span></p>
                            <p><label>Vehicle Driver:</label> <span>{{ $load->truckdriver ?: '—' }}</span></p>
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
                    @foreach ($load->lines as $n => $line)
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
                @if (count($load->lines) > 1)
                    <tfoot>
                        <tr>
                            <th colspan="3">TOTAL</th>
                            <th>{{ number_format($load->total) }}</th>
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
                    <label>Received By:</label>
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
        var codes = @json(collect($loads)->pluck('barcode'));
        window.addEventListener('load', function () {
            codes.forEach(function (code, i) {
                $('#bc' + i).barcode(String(code), 'code93', { fontSize: 14 });
            });
        });
    </script>
</body>
</html>
