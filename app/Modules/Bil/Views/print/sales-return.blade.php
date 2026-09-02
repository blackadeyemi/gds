{{--
    Return note. Reproduces the legacy printout/sales_return.php: the same
    heading, the same info card (sales orders and warehouse on the left,
    customer and address on the right), and the same table with BOTH quantity
    columns — returned and rejected.

    NO BARCODE. The legacy called printHeading('Return', …, '') with an empty
    barcode, because a return has none: `sales_return` carries no barcode column
    and nothing at the gate scans one. So this sheet, alone among the three,
    prints its heading without one rather than inventing something for the
    scanners to fail on.

    Like the loading and delivery sheets it takes a LIST and page-breaks
    between, so a day's returns print in one go.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Print Out{{ count($returns) === 1 ? ' — #' . $returns[0]->returnnumber : '' }}</title>
    <link rel="icon" href="{{ asset('images/bilicon.ico') }}" />
    <style type="text/css">
        * { box-sizing:border-box; }
        body { font-family:Arial, Helvetica, sans-serif; font-size:14px; color:#000; background:#f4f4f4; margin:0; padding:16px; }

        .sheet { background:#fff; max-width:1000px; margin:0 auto 22px; padding:18px 22px 28px; border:1px solid #ddd; page-break-after:always; }
        .sheet:last-of-type { page-break-after:auto; }

        table.wrapper { width:100%; border-collapse:collapse; }
        table.wrapper td { width:50%; vertical-align:middle; border:0; padding:0; }
        .logo img { width:150px; }
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
        /* Rejected goods do not go back on sale, so the figure is never quiet. */
        .rejected { color:#b45309; font-weight:bold; }

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
            .hidden-date { display:block; margin:14px 0 14px 34px; }
            @page { margin:8mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print {{ count($returns) }} return note(s) again</button>
    </div>

    @foreach ($returns as $return)
        <div class="sheet">
            <p class="hidden-date"><small>Printed On: {{ now()->format('d F, Y @H:i A') }}</small></p>

            <table class="wrapper">
                <tr>
                    <td class="logo"><img src="{{ asset('images/belimpex_brands logo.png') }}" alt="Belimpex"></td>
                    <td class="title f-f-cursive">
                        <h2>Return</h2>
                        <small>number:</small> <span>{{ $return->returnnumber }}</span> /
                        <small>date:</small> <span>{{ $return->dateofreturn }}</span>
                    </td>
                </tr>
            </table>

            <hr />

            <div class="card f-f-cursive">
                <table class="table-grid">
                    <tr>
                        <td class="layout">
                            <p><label>Sales Order(s):</label> <span>{{ implode(', ', $return->orders) ?: '—' }}</span></p>
                            <p><label>Warehouse:</label> <span>{{ $return->warehouse ?: '—' }}</span></p>
                        </td>
                        <td class="layout">
                            <p><label>Customer:</label> <span>{{ $return->customername ?: '—' }}</span></p>
                            @if ($return->customeraddress)
                                <p><label>Customer Address:</label> <span>{{ $return->customeraddress }}</span></p>
                            @endif
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
                        <th style="width:150px;">QUANTITY RETURNED</th>
                        <th style="width:150px;">QUANTITY REJECTED</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($return->lines as $n => $line)
                        <tr>
                            <td>{{ $n + 1 }}</td>
                            <td>{{ $line->productcode }}</td>
                            <td>{{ $line->productname }}@if ($line->foc) <span class="foc">(FOC)</span>@endif</td>
                            <td>{{ number_format($line->quantityreturned) }}</td>
                            <td>@if ($line->quantityrejected > 0)<span class="rejected">{{ number_format($line->quantityrejected) }}</span>@else 0 @endif</td>
                        </tr>
                    @endforeach
                </tbody>
                {{-- A total under a single line just repeats it, so the legacy
                     sheet omits it there and so does this. --}}
                @if (count($return->lines) > 1)
                    <tfoot>
                        <tr>
                            <th colspan="3">TOTAL</th>
                            <th>{{ number_format($return->total_returned) }}</th>
                            <th>{{ number_format($return->total_rejected) }}</th>
                        </tr>
                    </tfoot>
                @endif
            </table>

            <div class="signature">
                <div class="left">
                    <label>Returned By:</label>
                    <p><strong>____________________________</strong><br /><small>DATE &amp; SIGNATURE</small></p>
                </div>
                <div class="right">
                    <label>Received By:</label>
                    <p><strong>____________________________</strong><br /><small>DATE &amp; SIGNATURE</small></p>
                </div>
            </div>
        </div>
    @endforeach

    <script type="text/javascript">
        // The print dialog opens by itself, as the other sheets do. No barcode
        // to draw here, so it can fire as soon as the page is laid out.
        //
        // Deliberately no window.close() on afterprint: it also fires when the
        // dialog is cancelled, and closing the tab on someone who only wanted
        // to check the sheet is worse than leaving it open.
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
</body>
</html>
