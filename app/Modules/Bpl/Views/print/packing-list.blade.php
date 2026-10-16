{{--
    The packing list that travels with the shipment — port of the legacy
    app/views/bpl_packing_list.php.

    Weights print in tonnes; they are stored in kilograms. A container holding
    more than one lot gets a row per lot, with the container number, its net
    and its gross spanning them — and the "Net Weight (MT)" column then appears
    twice, per lot and per container, exactly as the legacy sheet did.
--}}
@php
    $belimpex = strtolower($company) === 'belimpex';
    $logo = $belimpex ? 'images/belimpex_brands logo.png' : 'images/belpapyrus_companies logo.png';
    $mt = fn ($kg) => rtrim(rtrim(number_format((float) $kg / 1000, 3, '.', ''), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Packing List {{ $number }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/pwa/favicon-32.png') }}" />
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/pwa/favicon-16.png') }}" />
    <style type="text/css">
        body { margin: 0; background: #fff; color: #000; font-family: Arial, Helvetica, sans-serif; font-size: 13px; }
        .sheet { width: 900px; margin: 0 auto; padding: 26px 34px 90px; }
        .head p { margin: 6px 0; line-height: 1.45; }
        .title { display: flex; justify-content: space-between; align-items: flex-end; margin: 18px 0 12px; }
        .title h1 { font-size: 20px; margin: 0; text-decoration: underline; }
        table.meta { width: 100%; margin-bottom: 14px; }
        table.meta th { text-align: left; width: 150px; vertical-align: top; padding: 4px 0; }
        table.meta td { padding: 4px 0; }
        table.goods { border-collapse: collapse; width: 100%; }
        table.goods th, table.goods td { border: 1px solid #000; padding: 6px 8px; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        ul.summary { list-style: none; padding: 0; font-weight: bold; margin: 16px 0; }
        ul.summary li { line-height: 1.6; }
        ul.summary label { display: inline-block; width: 150px; }
        .sign { font-weight: bold; font-size: 15px; margin-top: 10px; }
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
    </div>

    <div class="title">
        <div>
            <h1>PACKING LIST</h1>
            <p>{{ $date ? \Illuminate\Support\Carbon::parse(str_replace('/', '-', $date))->format('d F, Y') : '' }}</p>
        </div>
        <div>Number: <strong>{{ $number }}</strong></div>
    </div>

    <table class="meta">
        <tr>
            <th>Customer:</th>
            <td>
                <strong style="font-size:15px;">{{ $customer['name'] }}</strong><br>
                {!! nl2br(e($customer['address'])) !!}<br>
                Tel: {{ $customer['phone'] }}<br>
                Email: {{ $customer['email'] }}
            </td>
        </tr>
        <tr><th>Description</th><td>Tissue Paper in Jumbo Rolls</td></tr>
        <tr><th>NXP Number:</th><td>{{ $nxp }}</td></tr>
    </table>

    <table class="goods">
        <thead>
            <tr>
                <th class="center" style="width:50px;">S/N</th>
                <th class="center">Description of Goods</th>
                <th class="center" style="width:150px;">{{ $carrier }} No</th>
                <th class="center" style="width:90px;">No of Rolls</th>
                <th class="center" style="width:120px;">Net Weight (MT)</th>
                @if ($perContainerNet)
                    <th class="center" style="width:120px;">Net Weight (MT)</th>
                @endif
                <th class="right" style="width:130px;">Gross Weight (MT)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($containers as $index => $container)
                @php
                    $goods = $container['goods'] ?? [];
                    $count = max(count($goods), 1);
                    $containerNet = array_sum(array_column($goods, 'weight'));
                    $containerGross = $containerNet + (float) ($container['weight'] ?? 0);
                @endphp
                @foreach ($goods as $key => $lot)
                    <tr>
                        @if ($key === 0)
                            <td rowspan="{{ $count }}" class="center">{{ $index + 1 }}</td>
                        @endif
                        <td>{{ $lot['description'] ?? '' }}</td>
                        @if ($key === 0)
                            <td rowspan="{{ $count }}" class="center">{{ $container['name'] ?? '' }}</td>
                        @endif
                        <td class="center">{{ number_format((int) ($lot['rolls'] ?? 0)) }}</td>
                        <td class="center">{{ $mt($lot['weight'] ?? 0) }}</td>
                        @if ($key === 0)
                            @if ($perContainerNet)
                                <td rowspan="{{ $count }}" class="center">{{ $mt($containerNet) }}</td>
                            @endif
                            <td rowspan="{{ $count }}" class="right">{{ $mt($containerGross) }}</td>
                        @endif
                    </tr>
                @endforeach
            @endforeach
        </tbody>
        <tfoot>
            <tr class="bold">
                <td colspan="3" class="center">
                    Total: {{ count($containers) }} {{ $carrier }}{{ count($containers) > 1 ? 's' : '' }}
                </td>
                <td class="center">{{ number_format($rolls) }}</td>
                <td class="center">{{ $mt($net) }}</td>
                @if ($perContainerNet)
                    <td class="center">{{ $mt($net) }}</td>
                @endif
                <td class="right">{{ $mt($gross) }}</td>
            </tr>
        </tfoot>
    </table>

    <ul class="summary">
        <li><label>Number of Rolls:</label>{{ number_format($rolls) }}</li>
        <li><label>Net Weight:</label>{{ $mt($net) }} MT</li>
        <li><label>Gross Weight:</label>{{ $mt($gross) }} MT</li>
    </ul>

    <div class="sign">{{ $belimpex ? 'BEL IMPEX LIMITED' : 'BEL PAPYRUS LIMITED' }}</div>

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
