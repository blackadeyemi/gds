{{--
    The processor slip — port of the legacy
    app/views/bpl_delivery_processor_printout.php.

    The receiving mill's copy of a delivery: every roll on its own line with
    its hardroll number, barcode and weight, and the weighbridge figures across
    the top. The customer's waybill groups the same rolls by product; this one
    is what the load is checked in against, roll by roll.

    Differences from the legacy slip: the header date is the DELIVERY's date
    (the legacy printed the day it was printed), the empty/full/net figures are
    left off until the truck has actually been weighed rather than printing
    zeros, and the "Download SQL" button asks the server for the file instead
    of writing data.sql into the web root.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Processor slip {{ $number }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/pwa/favicon-32.png') }}" />
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/pwa/favicon-16.png') }}" />
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <style type="text/css">
        body { margin: 0; padding: 0; background: #fff; color: #000;
               font-family: Arial, Helvetica, sans-serif; font-size: 13px; }
        .sheet { width: 1000px; margin: 0 auto; padding: 26px 34px 60px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; }
        .address { font-size: 13px; line-height: 1.45; margin-top: 6px; }
        .title { text-align: right; }
        .title h2 { margin: 0; font-size: 22px; }
        .rule { border: 0; border-top: 2px solid #003300; margin: 16px 0; }
        .details { display: flex; justify-content: space-between; font-size: 13px; }
        .details p { margin: 4px 0; }
        .details span.k { font-weight: bold; }
        .internal { text-align: center; font-size: 20px; font-weight: bold; padding: 8px 0; }
        table { border-collapse: collapse; width: 100%; font-size: 12px; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 5px 7px; text-align: left; }
        th { background: #04AA6D; color: #fff; }
        tbody tr:nth-child(even) { background: #f2f2f2; }
        tfoot td { font-weight: bold; }
        .num { text-align: right; }
        .signatures { display: flex; justify-content: space-between; margin-top: 44px; font-size: 13px; }
        .signatures .line { margin-top: 30px; border-top: 1px solid #000; width: 190px; }
        .tools { margin-top: 14px; }
        .tools a { display: inline-block; padding: 6px 12px; border: 1px solid #04AA6D; color: #04AA6D;
                   text-decoration: none; border-radius: 4px; font-size: 12px; }
        .pending { margin-top: 12px; font-size: 12px; color: #92400e; }
        @media print { .sheet { width: auto; padding: 8px 14px; } .tools { display: none; } }
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
            <h2>Delivery Form</h2>
            <div style="font-size:13px;margin-top:6px;">
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
                <p><span class="k">Loader Name:</span> {{ $loader ?: '—' }}</p>
            </div>
            <div>
                <p><span class="k">Vehicle No:</span> {{ $vehicle ?: '—' }}</p>
                <p><span class="k">Container No:</span> {{ $container ?: '—' }}</p>
            </div>
            <div>
                <p><span class="k">Empty Weight:</span> {{ $truckWeight ? number_format($truckWeight, 2) . ' kg' : '—' }}</p>
                <p><span class="k">Full Weight:</span> {{ $fullWeight ? number_format($fullWeight, 2) . ' kg' : '—' }}</p>
                <p><span class="k">Net Weight:</span> {{ $netWeight !== null ? number_format($netWeight, 2) . ' kg' : '—' }}</p>
            </div>
        </div>
    @endif

    @if ($importable)
        <div class="tools">
            <a href="{{ route('bpl.logistics.delivery.import-sql', $number) }}">Download import file (.sql)</a>
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th style="width:50px;">S/N</th>
                <th style="width:140px;">Hardroll No.</th>
                <th style="width:160px;">Barcode</th>
                <th style="width:170px;">Delivery Barcode</th>
                <th style="width:90px;">Grade</th>
                <th>Product</th>
                <th style="width:70px;" class="num">Qty</th>
                <th style="width:110px;" class="num">Weight (kg)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rolls as $i => $roll)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $roll->hardrollnumber ?: '—' }}</td>
                    <td>{{ $roll->barcode }}</td>
                    <td>{{ $number }}</td>
                    <td>{{ $roll->gradetype ?: '—' }}</td>
                    <td>{{ $roll->productname ?: '—' }}</td>
                    <td class="num">1</td>
                    <td class="num">{{ number_format((float) $roll->weight, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6">Total</td>
                <td class="num">{{ number_format(count($rolls)) }}</td>
                <td class="num">{{ number_format($totalWeight, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if (! $internal && $fullWeight <= 0)
        <div class="pending">This load has not been weighed yet — the empty, full and net weights are filled in at the weighbridge.</div>
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
