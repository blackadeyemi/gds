{{--
    Loading print-out. Rebuild of the legacy printout/sales_loading.php, carrying
    the same information in the same order so a cageroom clerk reads it without
    relearning anything: customer and address, the sales orders it draws on, the
    warehouse and cage rooms, the loader, then transporter and vehicle.

    Takes a LIST of loads and page-breaks between them, because printing a day's
    work in one go is the point — the legacy screen made you open a tab per load.
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
        body { font-family:Arial, Helvetica, sans-serif; font-size:12px; color:#111; background:#f4f4f4; margin:0; padding:16px; }
        .sheet { background:#fff; max-width:820px; margin:0 auto 22px; padding:22px 26px; border:1px solid #ddd; page-break-after:always; }
        .sheet:last-of-type { page-break-after:auto; }
        .head { display:flex; align-items:flex-start; gap:16px; border-bottom:2px solid #607d8b; padding-bottom:10px; margin-bottom:14px; }
        .head h1 { font-size:17px; margin:0 0 2px; letter-spacing:.02em; }
        .head .meta { margin-left:auto; text-align:right; font-size:12px; }
        .head .barcode { font-family:"Courier New", monospace; font-size:15px; font-weight:bold; }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:4px 26px; margin-bottom:14px; }
        .grid p { margin:0 0 4px; }
        .grid label { display:inline-block; min-width:118px; color:#555; }
        table { width:100%; border-collapse:collapse; }
        th, td { border:1px solid #ccc; padding:5px 7px; text-align:left; }
        thead th { background:#607d8b; color:#fff; font-weight:600; }
        td.num, th.num { text-align:right; }
        tfoot td { font-weight:bold; background:#f0f0f0; }
        /* Free of charge — it changes what is owed, so it is never quiet. */
        .foc { color:#c62828; font-weight:bold; }
        .sign { margin-top:26px; display:grid; grid-template-columns:repeat(3,1fr); gap:22px; }
        .sign div { border-top:1px solid #333; padding-top:5px; font-size:11px; color:#333; }
        .toolbar { max-width:820px; margin:0 auto 14px; text-align:right; }
        .toolbar button { font:inherit; padding:7px 16px; border:1px solid #607d8b; background:#607d8b; color:#fff; border-radius:4px; cursor:pointer; }
        @media print {
            body { background:#fff; padding:0; }
            .sheet { border:0; margin:0; max-width:none; padding:14px 18px; }
            .toolbar { display:none; }
            @page { margin:10mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print {{ count($loads) }} load(s)</button>
    </div>

    @foreach ($loads as $load)
        <div class="sheet">
            <div class="head">
                <div>
                    <h1>LOADING</h1>
                    <div>Load number <strong>{{ $load->loadnumber }}</strong></div>
                    <div>{{ $load->dateofloading }}</div>
                </div>
                <div class="meta">
                    <div class="barcode">{{ $load->barcode }}</div>
                    <div>{{ $load->status ? 'Delivered ' . $load->status : 'Not delivered' }}</div>
                </div>
            </div>

            <div class="grid">
                <div>
                    <p><label>Customer:</label> <span>{{ $load->customername ?: '—' }}</span></p>
                    <p><label>Sales Order(s):</label> <span>{{ implode(', ', $load->orders) ?: '—' }}</span></p>
                    <p><label>Warehouse:</label> <span>{{ $load->warehouse ?: '—' }}</span></p>
                    <p><label>Cage room(s):</label> <span>{{ implode(', ', $load->cagerooms) ?: '—' }}</span></p>
                    <p><label>Loader:</label> <span>{{ $load->loader ?: '—' }}</span></p>
                </div>
                <div>
                    @if ($load->customeraddress)
                        <p><label>Customer Address:</label> <span>{{ $load->customeraddress }}</span></p>
                    @endif
                    <p><label>Transporter:</label> <span>{{ $load->transportername ?: '—' }}</span></p>
                    <p><label>Vehicle Number:</label> <span>{{ $load->trucknumber ?: '—' }}</span></p>
                    <p><label>Vehicle Driver:</label> <span>{{ $load->truckdriver ?: '—' }}</span></p>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:110px;">Product Code</th>
                        <th>Product Name</th>
                        <th class="num" style="width:90px;">Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($load->lines as $line)
                        <tr>
                            <td>{{ $line->productcode }}</td>
                            <td>{{ $line->productname }}@if ($line->foc) <span class="foc">(FOC)</span>@endif</td>
                            <td class="num">{{ number_format($line->quantityloaded) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" style="text-align:right;">Total</td>
                        <td class="num">{{ number_format($load->total) }}</td>
                    </tr>
                </tfoot>
            </table>

            <div class="sign">
                <div>Loader</div>
                <div>Driver</div>
                <div>Security</div>
            </div>
        </div>
    @endforeach
</body>
</html>
