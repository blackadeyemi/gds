{{--
    The proforma invoice sent to the customer — port of the legacy
    app/views/bpl_proforma_invoice.php, and (with $isFinal) the FINAL invoice
    the packing list raises, which is the same sheet priced on what shipped:
    the legacy kept a near-identical second template, bpl_final_invoice.php.

    Differences from that sheet: it carries the Belimpex or Bel Papyrus
    letterhead by the ORDER's company as before, but the bank block is always
    the account named on the proforma (the legacy hard-coded one customer's
    bank details and ignored the account it was raised with), GBP can be spelled
    out in the "Say … Only" line, and the freight charge is computed whether or
    not the order has lines — the legacy worked it out inside the item loop, so
    an unpriced order printed a total with no freight in it.
--}}
@php
    // The same sheet serves the FINAL invoice, which the packing list raises
    // against what actually shipped — see Support\PackingList::finalInvoice().
    $isFinal = $isFinal ?? false;
    $rolls = $rolls ?? null;
    $belimpex = strtolower($company) === 'belimpex';
    $logo = $belimpex ? 'images/belimpex_brands logo.png' : 'images/belpapyrus_companies logo.png';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $isFinal ? 'Invoice' : 'Proforma' }} {{ $number }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/pwa/favicon-32.png') }}" />
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/pwa/favicon-16.png') }}" />
    <style type="text/css">
        body { margin: 0; background: #fff; color: #000; font-family: Arial, Helvetica, sans-serif; font-size: 13px; }
        .sheet { width: 860px; margin: 0 auto; padding: 26px 34px 90px; }
        .head p { margin: 6px 0; line-height: 1.45; }
        .head h1 { font-size: 17px; margin: 14px 0 0; letter-spacing: 1px; }
        .box { border: 1px solid #000; padding: 8px 10px; margin-bottom: 12px; }
        .box p { margin: 2px 0; }
        .k { display: inline-block; width: 100px; }
        .sold { margin-left: 140px; }
        table.goods { border-collapse: collapse; width: 100%; margin-bottom: 14px; }
        table.goods th, table.goods td { border: 1px solid #000; padding: 6px 8px; }
        table.goods th { text-align: left; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        table.terms { width: 100%; }
        table.terms td { padding: 2px 0; vertical-align: top; }
        table.terms td:first-child { width: 230px; }
        .sign { margin-top: 18px; font-weight: bold; font-size: 15px; }
        footer { margin-top: 26px; border-top: 1px solid #000; padding-top: 6px; font-size: 11px; }
        footer table { width: 100%; }
        @media print { .sheet { width: auto; padding: 8px 14px; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="head">
        <img src="{{ asset($logo) }}" alt="{{ $company }}" style="width:150px;" onerror="this.style.display='none'">
        <p>
            Plot 10, Acme Road, Ogba Industrial Estate, Ikeja<br>
            P.O. Box 241, Lagos, Nigeria<br>
            T. +234 17745232 / F. +234 1 7936485<br>
            www.belpapyrus.ng
        </p>
        <small>R.C. No: 87929</small>
        <h1>{{ $isFinal ? 'FINAL' : 'PROFORMA' }} INVOICE</h1>
    </div>

    <div class="box" style="margin-top:12px;">
        <p><span class="k">Number:</span><strong>{{ $number }}</strong></p>
        <p><span class="k">Date:</span>{{ $date ? \Illuminate\Support\Carbon::parse(str_replace('/', '-', $date))->format('d F, Y') : '' }}</p>
        @if ($nxp)
            <p><span class="k">NXP:</span>{{ $nxp }}</p>
        @endif
        @if ($isFinal && $rolls !== null)
            <p><span class="k">Rolls:</span>{{ number_format($rolls) }}</p>
        @endif
    </div>

    <div class="box">
        <small>Sold to:</small>
        <div class="sold">
            <p><strong>{{ $customer['label'] }}</strong> - {{ $customer['name'] }}</p>
            <p>{!! nl2br(e($customer['address'])) !!}</p>
            <p>Email: {{ $customer['email'] }}</p>
            <p>Tel: {{ $customer['phone'] }}</p>
        </div>
    </div>

    <table class="goods">
        <thead>
            <tr>
                <th>Description</th>
                <th class="center" style="width:90px;">Qty/MT</th>
                <th class="center" style="width:110px;">Unit Price</th>
                <th class="right" style="width:150px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['fullname'] }}</td>
                    <td class="center">{{ rtrim(rtrim(number_format($line['tonnes'], 3), '0'), '.') }}</td>
                    <td class="center">{{ number_format($line['price'], 2) }}</td>
                    <td class="right">{{ number_format($line['amount'], 2) }} {{ $symbol }}</td>
                </tr>
            @endforeach

            <tr class="bold">
                <td>FOB price in {{ $currency }}<br><br>Freight Charges: {{ $freight }}</td>
                <td class="center"><br><br>{{ $containers }}</td>
                <td class="center"><br><br>{{ number_format($freightPrice, 2) }}</td>
                <td class="right">
                    {{ number_format($goods, 2) }} {{ $symbol }}<br><br>
                    {{ number_format($freightCharge, 2) }} {{ $symbol }}
                </td>
            </tr>

            <tr class="bold">
                <td colspan="3">Say {{ $currency }} {{ $inWords }} Only</td>
                <td class="right">{{ number_format($grand, 2) }} {{ $symbol }}</td>
            </tr>
        </tbody>
    </table>

    <table class="terms">
        <tr><td>Terms of Delivery:</td><td><i>{{ $terms }}</i></td></tr>
        <tr><td>Shipment:</td><td><i>As per {{ $shipment }} available</i></td></tr>
        <tr><td>Payment:</td><td><i>{{ $paymentTerms }}</i></td></tr>
        <tr><td>Packing:</td><td><i>{{ $packing }}</i></td></tr>
        <tr><td>Insurance:</td><td><i>{{ $insurance }}</i></td></tr>
        <tr><td>Tolerance:</td><td><i>{{ $tolerance }}</i></td></tr>

        @if ($shipment === 'Vessel')
            <tr><td>Port of Loading:</td><td><i>{{ $portOfLoading }}</i></td></tr>
            <tr><td>Port of Discharge:</td><td><i>{{ $customer['port'] }}</i></td></tr>
        @endif

        <tr><td>Country of Origin:</td><td><i>Federal Republic of Nigeria</i></td></tr>
        <tr><td>Country of Supply:</td><td><i>Federal Republic of Nigeria</i></td></tr>

        @if ($bank)
            <tr><td colspan="2" style="padding-top:10px;"><strong>BANK DETAILS</strong></td></tr>

            @if ($bank['routed'])
                @if ($bank['intermediary_bank'])
                    <tr><td>Intermediary Bank:</td><td><strong>{{ $bank['intermediary_bank'] }}</strong></td></tr>
                    <tr><td>Intermediary Bank Swift Code:</td><td><strong>{{ $bank['intermediary_swiftcode'] }}</strong></td></tr>
                    @if ($bank['intermediary_number'])
                        <tr><td>Intermediary Bank Account Number:</td><td><strong>{{ $bank['intermediary_number'] }}</strong></td></tr>
                    @endif
                @endif

                @if ($bank['correspondent_bank'])
                    <tr><td>Correspondent Bank:</td><td><strong>{{ $bank['correspondent_bank'] }}</strong></td></tr>
                    <tr><td>Correspondent Bank Swift Code:</td><td><strong>{{ $bank['correspondent_swiftcode'] }}</strong></td></tr>
                    @if ($bank['correspondent_number'])
                        <tr><td>Correspondent Bank Account Number:</td><td><strong>{{ $bank['correspondent_number'] }}</strong></td></tr>
                    @endif
                @endif

                <tr><td>Beneficiary Bank:</td><td><strong>{{ $bank['bank'] }}</strong></td></tr>
                <tr><td>Beneficiary Bank Swift Code:</td><td><strong>{{ $bank['swiftcode'] }}</strong></td></tr>
                <tr><td>Beneficiary Bank Account Number:</td><td><strong>{{ $bank['number'] }}</strong></td></tr>
                <tr><td colspan="2"><strong>Further Credit to:</strong></td></tr>
                <tr><td>Account Name:</td><td><strong>{{ $bank['account'] }}</strong></td></tr>
                <tr><td>Account Number:</td><td><strong>{{ $bank['further_acc'] }}</strong></td></tr>
            @else
                <tr><td>Account Name:</td><td><strong>{{ $bank['account'] }}</strong></td></tr>
                <tr><td>Account Number:</td><td><strong>{{ $bank['number'] }}</strong></td></tr>
                <tr><td>Company Address:</td><td><strong>Plot 10, Acme Road, Ikeja, Lagos, Nigeria</strong></td></tr>
                <tr><td>Bank Name:</td><td><strong>{{ $bank['bank'] }}</strong></td></tr>
                @if ($bank['sortcode'])
                    <tr><td>Sort Code:</td><td><strong>{{ $bank['sortcode'] }}</strong></td></tr>
                @endif
                <tr><td>Swift Code:</td><td><strong>{{ $bank['swiftcode'] }}</strong></td></tr>
                @if ($bank['address'])
                    <tr><td>Bank Address:</td><td><strong>{{ $bank['address'] }}</strong></td></tr>
                @endif
            @endif
        @endif
    </table>

    <div class="sign">{{ $belimpex ? 'BELIMPEX LIMITED' : 'BEL PAPYRUS LIMITED' }}</div>

    <footer>
        <table>
            <tr>
                <td><strong>Directors:</strong></td>
                <td>Boulos G. Boulos</td>
                <td>Karim G. Boulos</td>
                <td>S. Williams</td>
                <td>Carlos J. Miguel</td>
                <td>Raymond Bacha</td>
                <td>Riad Baloukji</td>
            </tr>
        </table>
    </footer>
</div>

<script>
    window.addEventListener('load', function () {
        window.print();
        setTimeout(function () { window.close(); }, 150000);
    });
</script>
</body>
</html>
