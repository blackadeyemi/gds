{{--
    The waybill a BPL truck leaves with — port of the legacy
    app/views/bpl_delivery_printout.php.

    CODE93 via public/js/jquery-barcode.js, the same symbology the gate
    scanners already read, so the number on this sheet can be scanned back at
    the waybill-payment screen.

    Differences from the legacy sheet: the header date is the DELIVERY's date
    (the legacy printed the date it was printed, so a reprint a week later
    carried the wrong one), the roll barcodes are listed after the summary so
    the load can be checked against the truck, and the net weight only appears
    once the loaded truck has been weighed.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Waybill {{ $number }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/pwa/favicon-32.png') }}" />
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/pwa/favicon-16.png') }}" />
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <style type="text/css">
        body { margin: 0; padding: 0; background: #fff; color: #000;
               font-family: Arial, Helvetica, sans-serif; }
        .sheet { width: 960px; margin: 0 auto; padding: 30px 40px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; }
        .address { font-size: 13px; line-height: 1.45; margin-top: 6px; }
        .title { text-align: right; }
        .title h2 { margin: 0; font-size: 26px; letter-spacing: 2px; }
        .title .meta { font-size: 13px; margin-top: 6px; }
        .rule { border: 0; border-top: 2px solid #003300; margin: 18px 0; }
        .details { display: flex; justify-content: space-between; font-size: 14px; }
        .details p { margin: 4px 0; }
        .details span.k { font-weight: bold; }
        .internal { text-align: center; font-size: 20px; font-weight: bold; padding: 8px 0; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; margin-top: 18px; }
        th, td { border: 1px solid #ddd; padding: 7px 8px; text-align: left; }
        th { background: #04AA6D; color: #fff; }
        tbody tr:nth-child(even) { background: #f2f2f2; }
        tfoot td { font-weight: bold; }
        .num { text-align: right; }
        .rolls { font-size: 11px; margin-top: 14px; }
        .rolls td { padding: 4px 6px; }
        .signatures { display: flex; justify-content: space-between; margin-top: 50px; font-size: 13px; }
        .signatures .line { margin-top: 34px; border-top: 1px solid #000; width: 190px; }
        .pending { margin-top: 14px; font-size: 12px; color: #92400e; }
        @media print { .sheet { width: auto; padding: 10px 16px; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="head">
        <div>
            <img src="{{ asset('images/belpapyrus_companies logo.png') }}" alt="Bel Papyrus" style="width:230px;"
                 onerror="this.style.display='none'">
            <div class="address">
                Plot 10 Block D, ACME Road,<br>
                Ogba Industrial Estate,<br>
                P.O Box 241, Lagos, Nigeria<br>
                Tel: 08033464898
            </div>
        </div>

        <div class="barcodes" style="text-align:center;"></div>

        <div class="title">
            <h2>WAYBILL</h2>
            <div class="meta">
                <div class="mono">{{ $number }}</div>
                <div>Date: {{ $date }}</div>
            </div>
        </div>
    </div>

    <hr class="rule">

    @if ($internal)
        <div class="internal">{{ $customer }}</div>
    @else
        <div class="details">
            <div>
                <p><span class="k">Customer:</span> {{ $customer }}</p>
                <p><span class="k">Driver:</span> {{ $driver ?: '—' }}</p>
                <p><span class="k">Driver Phone:</span> {{ $driverPhone ?: '—' }}</p>
            </div>
            <div>
                <p><span class="k">Vehicle No:</span> {{ $vehicle ?: '—' }}</p>
                <p><span class="k">Container No:</span> {{ $container ?: '—' }}</p>
                <p><span class="k">Loader:</span> {{ $loader ?: '—' }}</p>
            </div>
            <div>
                <p><span class="k">Empty Weight:</span> {{ $truckWeight ? number_format($truckWeight, 2) . ' kg' : '—' }}</p>
                <p><span class="k">Loaded Weight:</span> {{ $fullWeight ? number_format($fullWeight, 2) . ' kg' : '—' }}</p>
                <p><span class="k">Net Weight:</span> {{ $netWeight !== null ? number_format($netWeight, 2) . ' kg' : '—' }}</p>
            </div>
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th style="width:50px;">S/N</th>
                <th style="width:90px;">Grade</th>
                <th>Product</th>
                <th style="width:90px;" class="num">Quantity</th>
                <th style="width:130px;" class="num">Weight (kg)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($summary as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line['grade'] }}</td>
                    <td>{{ $line['product'] }}</td>
                    <td class="num">{{ number_format($line['rolls']) }}</td>
                    <td class="num">{{ number_format($line['weight'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Total</td>
                <td class="num">{{ number_format(collect($summary)->sum('rolls')) }}</td>
                <td class="num">{{ number_format(collect($summary)->sum('weight'), 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <table class="rolls">
        <thead>
            <tr>
                <th style="width:50px;">S/N</th>
                <th style="width:180px;">Barcode</th>
                <th style="width:130px;">Roll No.</th>
                <th>Product</th>
                <th style="width:110px;" class="num">Weight (kg)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rolls as $i => $roll)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $roll->barcode }}</td>
                    <td>{{ $roll->hardrollnumber ?: '—' }}</td>
                    <td>{{ $roll->productname ?: '—' }}</td>
                    <td class="num">{{ number_format((float) $roll->weight, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($status !== 'completed' && ! $internal)
        <div class="pending">This load has not been weighed yet — the loaded and net weights are filled in at the weighbridge.</div>
    @endif

    <div class="signatures">
        <div>Driver Signature<div class="line"></div></div>
        <div>Loader Signature<div class="line"></div></div>
        <div>Authorized Signature<div class="line"></div></div>
    </div>
</div>

<script src="{{ asset('js/jquery-barcode.js') }}"></script>
<script type="text/javascript">
    var waybillNumber = @json($number);

    window.addEventListener('load', function () {
        $('.barcodes').html('').barcode(waybillNumber, 'code93');
        window.print();
        setTimeout(function () { window.close(); }, 150000);
    });
</script>
</body>
</html>
