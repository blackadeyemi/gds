{{--
    Reel label for a BPL hardroll or softroll — the port of the legacy
    app/views/bpl_print.php and softroll_print.php, which are the same sheet
    with a handful of different cells.

    CODE93 via public/js/jquery-barcode.js, the same symbology and the same
    label stock the existing scanners already read.

    Slices: a hardroll product with slice > 1 is cut into that many pieces, each
    scanned under `<barcode>-<n>`. Those sub-labels go down the left margin, as
    they did before. Softrolls are never sliced.
--}}
@php
    $sliceLabels = [];
    if (($roll['slice'] ?? 1) > 1) {
        $sliceWeight = round($roll['weight'] / (int) $roll['slice'], 2);
        for ($i = 1; $i <= (int) $roll['slice']; $i++) {
            $sliceLabels[] = ['barcode' => $roll['barcode'] . '-' . $i, 'weight' => $sliceWeight];
        }
    }

    // The big figure is the weight that came off the scale. Where a core /
    // wrapper allowance was deducted, the net is noted under it rather than
    // replacing it — the label has to reconcile with the weighbridge ticket.
    $allowance = (float) ($roll['net_weight'] ?? 0);
    $grossWeight = $roll['weight'] + $allowance;

    $comments = array_values(array_filter($roll['comments'] ?? [], fn ($c) => trim((string) $c) !== ''));

    // The MANCAP certification mark is per grade — each file carries that
    // grade's certificate number, so the wrong one is a false claim, not a
    // cosmetic slip. The legacy template fell back to SKT's mark for any
    // unlisted grade; that fallback is deliberately NOT reproduced. A grade
    // with no mark of its own simply prints without one.
    $mancap = null;
    $gradeKey = strtoupper(trim((string) ($roll['gradetype'] ?? '')));
    if ($gradeKey !== '' && preg_match('/^[A-Z]{2,4}$/', $gradeKey)) {
        $candidate = public_path('images/pmp/' . $gradeKey . '.PNG');
        if (is_file($candidate)) {
            $mancap = asset('images/pmp/' . $gradeKey . '.PNG');
        }
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $roll['barcode'] }} — {{ ucfirst($stream) }} Label</title>
    <link rel="icon" href="{{ asset('images/bilicon.ico') }}" />
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <style type="text/css">
        body { margin: 0; padding: 0; background: #fff; }
        .printlayout { width: 1000px; margin: 10px auto; font-family: Arial, Helvetica, sans-serif; overflow: hidden; }
        div.grid { float: left; }
        div.grid.push-left { width: 245px; margin-right: 10px; }
        div.grid.push-right { width: {{ $sliceLabels ? '737px' : '992px' }}; border: 2px solid #000; padding: 2px; }
        div.grid ul { list-style: none; margin: 0; padding: 0; }
        div.grid ul li { width: 241px; display: inline-block; margin-bottom: 10px; border: 2px solid #000; }
        div.grid h6 { text-align: left; padding: 0 6px 4px 6px; }

        table.sheet { width: 100%; border-collapse: collapse; }
        table.sheet tr { display: flex; }
        table.sheet tr td { border-top: 2px solid #000; border-left: 2px solid #000; height: 135px; position: relative; }
        table.sheet tr td:last-child { border-right: 2px solid #000; }
        table.sheet tr:last-child td { border-bottom: 2px solid #000; }

        .td-100 { width: 100%; } .td-42 { width: 42%; } .td-34 { width: 42%; }
        .td-33 { width: 33%; } .td-26 { width: 26%; } .td-24 { width: 16%; }
        .td-17 { width: 21.8%; } .td-8 { width: 7.6%; }

        table.sheet label { display: block; font-style: italic; padding: 5px; }
        table.sheet td.date label { display: initial; }
        table.sheet h1, table.sheet h2, table.sheet h3, table.sheet h4,
        table.sheet h5, table.sheet h6 { margin: 0; padding: 0; text-align: center; }
        table.sheet h1 { font-size: 40px; } table.sheet h2 { font-size: 55px; }
        table.sheet h3 { font-size: 20px; } table.sheet h4 { font-size: 23px; }
        table.sheet h5 { font-size: 28px; } table.sheet h6 { font-size: 16px; }

        .ctrl { position: absolute; bottom: 5px; width: 100%; }
        .typical { text-align: center; font-size: 13px; }
        .logos { position: absolute; top: 20px; left: 0; right: 0; text-align: center; }
        .logos img { height: 72px; vertical-align: middle; }
        .logos img + img { margin-left: 14px; }

        /* Barcode block, top right — the stream sits directly above the code so
           anyone reading the sheet does not have to decode the M / S prefix. */
        .barcode-block { position: absolute; right: 10px; top: 12px; text-align: center; }
        .stream-tag { font-size: 15px; font-weight: bold; letter-spacing: 3px; margin-bottom: 2px; }

        /* Grade cell: the label on the left, the full product name beside it. */
        .cell-head { display: flex; justify-content: space-between; align-items: baseline; padding-right: 8px; }
        .cell-head label { padding-right: 0; }
        .cell-head .productname { font-size: 13px; font-weight: bold; text-align: right; }

        /* The allowance note: bottom-left of the weight cell, out of the way of
           the figure itself. Absent entirely when nothing was deducted. */
        .allowance-note { position: absolute; left: 8px; bottom: 6px; font-size: 14px; font-style: italic; }

        .unwind img { max-width: 96%; }
        .machine-row { border-left: 2px solid #000; border-right: 2px solid #000; border-top: 2px solid #000;
                       border-bottom: 2px solid #000; position: relative; height: 90px; }
        .machine-row label { display: block; font-style: italic; padding: 5px; }
        .machine-row h1 { margin: 0; font-size: 40px; text-align: center; }
        .comments-row { border: 2px solid #000; border-top: 0; }
        .comments-row .heading { text-align: center; font-size: 20px; font-weight: bold; padding: 4px 0; }
        .comment-td { height: 46px; font-size: 18px; font-style: italic; text-align: center; }

        .note { font-size: 18px; font-weight: bold; color: #c00; text-align: center; font-style: italic; }
        .n-barcode { margin: 10px auto; text-align: center; }
        .stub h6 { margin: 0; font-size: 15px; text-align: center; }
        @media print { @page { margin: 6mm; } }
    </style>
</head>
<body>
<div class="printlayout">
    @if ($sliceLabels)
        <div class="grid push-left">
            <ul>
                @foreach (array_slice($sliceLabels, 0, 10) as $s)
                    <li>
                        <div class="n-barcode" data-code="{{ $s['barcode'] }}"></div>
                        <h6>Slices: {{ $roll['slice'] }}</h6>
                        <h6>Product: {{ $roll['productname'] }}</h6>
                        <h6>Weight: {{ $s['weight'] }}kg, Total: {{ $roll['weight'] }}kg</h6>
                        <h6>{{ $stream === 'hardroll' ? 'Hardroll' : 'Softroll' }}: {{ $roll['rollnumber'] }}</h6>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid push-right">
        <table class="sheet">
            <tr>
                <td class="td-100">
                    <label>Proudly made by:</label>
                    <div class="logos">
                        <img src="{{ asset('images/belpapyrus_companies logo.png') }}" alt="Belpapyrus">
                        @if ($mancap)
                            <img src="{{ $mancap }}" alt="MANCAP {{ $gradeKey }}">
                        @endif
                    </div>
                    <div class="barcode-block">
                        <div class="stream-tag">{{ strtoupper($stream) }}</div>
                        <div class="barcodes"></div>
                    </div>
                </td>
            </tr>

            <tr>
                <td class="td-42">
                    <div class="cell-head">
                        <label>Grade:</label>
                        <span class="productname">{{ $roll['productname'] }}</span>
                    </div>
                    <div class="ctrl"><h2>{{ $roll['gradetype'] }}</h2><h3>{{ $roll['gradename'] }}</h3></div>
                </td>
                <td class="td-24"><label>Grammage gsm:</label><h2>{{ $roll['gsm'] }}</h2></td>
                <td class="td-34">
                    <label>{{ $stream === 'hardroll' ? 'Hardroll' : 'Softroll' }} No:</label>
                    <h5>{{ $roll['rollnumber'] }}</h5>
                </td>
            </tr>

            @if ($stream === 'hardroll')
                <tr>
                    <td class="td-42">
                        <label>Customer:</label>
                        <div class="ctrl"><h5>{{ $roll['customerlabel'] }}</h5><h6>{{ $roll['customeraddress'] }}</h6></div>
                    </td>
                    <td class="td-24"><label>Ply:</label><h2>{{ $roll['ply'] }}</h2></td>
                    <td class="td-17"><label>Rolls per Bundle:</label><h2>{{ $roll['slice'] }}</h2></td>
                    <td class="td-17">
                        <label>Brightness, %ISO:</label><h2>{{ $roll['brightness'] }}</h2>
                        <div class="typical">(Typical Value)</div>
                    </td>
                </tr>

                <tr>
                    <td class="td-33"><label>Width, cm:</label><h2>{{ $roll['width'] }}</h2></td>
                    <td class="td-33"><label>Diameter, cm:</label><h2>{{ $roll['diameter'] }}</h2></td>
                    <td class="td-34">
                        <label>Weight, kg:</label><h2>{{ $grossWeight }}</h2>
                        @if ($allowance > 0)
                            <div class="allowance-note">
                                Less allowance {{ rtrim(rtrim(number_format($allowance, 2, '.', ''), '0'), '.') }}kg
                                &nbsp;·&nbsp; <strong>Net {{ $roll['weight'] }}kg</strong>
                            </div>
                        @endif
                    </td>
                </tr>
            @else
                <tr>
                    <td class="td-42"><label>Diameter, cm:</label><h2>{{ $roll['diameter'] }}</h2></td>
                    <td class="td-24"><label>Weight, kg:</label><h2>{{ $roll['weight'] }}</h2></td>
                    <td class="td-34">
                        <label>Brightness, %ISO:</label><h2>{{ $roll['brightness'] }}</h2>
                        <div class="typical">(Typical Value)</div>
                    </td>
                </tr>
            @endif

            <tr>
                <td class="td-33">
                    <label>Origin:</label>
                    <div style="padding:0 8px;font-weight:bold;font-size:14px;line-height:1.5;">
                        Plot 10, Block D, Acme Road,<br>Ogba Industrial Estate, Ikeja, Lagos<br>Federal Republic of Nigeria.
                    </div>
                </td>
                <td class="td-33 date" style="padding-left:8px;">
                    <label>Date:</label><h4 style="margin-bottom:14px;">{{ $roll['dateofmanufacture'] }}</h4>
                    <label>Time:</label><h4>{{ now()->format('H:i:s') }}</h4>
                </td>
                @if ($stream === 'hardroll')
                    {{-- Joints are counted on a hardroll only; a softroll has
                         no such field, and printing "0" invents a measurement. --}}
                    <td class="td-8"><label>Joints:</label><h2>{{ $roll['joints'] ?? 0 }}</h2></td>
                @endif
                <td class="{{ $stream === 'hardroll' ? 'td-26' : 'td-34' }} unwind">
                    <label>Unwinding Direction:</label>
                    <div class="ctrl"><img src="{{ asset('images/unwind-arrow.png') }}" alt="Unwind this way"></div>
                </td>
            </tr>
        </table>

        <div class="machine-row">
            <label>Paper Machine:</label>
            <h1>{{ $roll['papermachine'] }}</h1>
        </div>

        {{-- Only when there is something to say. An empty COMMENTS block took a
             sixth of the sheet to print three blank boxes. --}}
        @if ($comments)
            <div class="comments-row">
                <div class="heading">COMMENTS</div>
                <table style="width:100%;border-collapse:collapse;">
                    <tr>
                        @foreach ($comments as $c)
                            <td class="comment-td" style="width:{{ round(100 / count($comments), 4) }}%;">{{ $c }}</td>
                        @endforeach
                    </tr>
                </table>
            </div>
        @endif

        <p class="note">TEAR OUT THIS PART ONLY</p>
        <hr><hr><hr>

        {{-- Four identical tear-off stubs: one goes on the paperwork, the rest
             travel with the reel. --}}
        @foreach ([0, 1] as $rowIndex)
            <table style="width:100%;margin-top:14px;">
                <tr>
                    @foreach ([0, 1] as $col)
                        <td width="50%" class="stub" style="padding-bottom:10px;">
                            <div class="stub-barcode n-barcode"></div>
                            <h6>Product: {{ $roll['productname'] }}</h6>
                            <h6>Weight: {{ $roll['weight'] }}kg,
                                {{ $stream === 'hardroll' ? 'Hardroll' : 'Softroll' }}: {{ $roll['rollnumber'] }}</h6>
                        </td>
                    @endforeach
                </tr>
            </table>
        @endforeach
    </div>

    <div style="clear:both;"></div>

    @if (count($sliceLabels) > 10)
        <div class="grid" style="margin-top:10px;">
            <ul>
                @foreach (array_slice($sliceLabels, 10) as $s)
                    <li>
                        <div class="n-barcode" data-code="{{ $s['barcode'] }}"></div>
                        <h6>Product: {{ $roll['productname'] }}</h6>
                        <h6>Weight: {{ $s['weight'] }}kg</h6>
                        <h6>{{ $roll['rollnumber'] }}</h6>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

<script src="{{ asset('js/jquery-barcode.js') }}"></script>
<script type="text/javascript">
    var mainCode = @json($roll['barcode']);

    window.addEventListener('load', function () {
        $('.barcodes, .stub-barcode').each(function () { $(this).html('').barcode(mainCode, 'code93'); });
        // Each slice carries its own code on the element, so the drawing order
        // never has to line up with a separate array.
        $('.n-barcode[data-code]').each(function () {
            $(this).html('').barcode($(this).data('code'), 'code93');
        });
        window.print();
        setTimeout(function () { window.close(); }, 150000);
    });
</script>
</body>
</html>
