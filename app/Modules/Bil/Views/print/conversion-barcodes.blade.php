{{--
    Pallet labels for a conversion run. Same 3.95in x 2in stock and CODE93
    symbology as the legacy factory_production_result.php print-out, so the
    existing scanners and label rolls are unchanged.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finished Goods — Pallet Labels</title>
    <link rel="icon" href="{{ asset('images/bilicon.ico') }}" />
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <style type="text/css">
        body { background:#FDFDFD; padding:0; margin:0; }
        body > div > div:last-of-type { page-break-after:auto; }
        .printdiv { height:165px; width:375px; margin:5px auto 30px auto; page-break-after:always; }
        @media print {
            @page { margin:0; size:3.95in 2in; }
        }
        #row1 { font-family:"Trebuchet MS", Arial, Helvetica, sans-serif; font-size:9px; }
        #row2 { font-family:Tahoma, Geneva, sans-serif; font-size:13px; font-weight:bold; text-align:left; text-transform:uppercase; }
        #row3 { font-family:Tahoma, Geneva, sans-serif; font-size:12px; text-align:left; text-transform:uppercase; }
        #row5 { font-family:Tahoma, Geneva, sans-serif; font-size:11px; font-weight:bold; text-align:left; }
        #row6 { font-family:Arial, Helvetica, sans-serif; font-size:10px; text-align:center; text-transform:uppercase; }
    </style>
</head>
<body>
    <div style="width:100%;background:#fff">
        @foreach ($rows as $i => $row)
            <div class="printdiv">
                <table width="370" align="center">
                    <tr id="row1">
                        <td width="254" height="25"><img src="{{ asset('images/belimpex-barcode.png') }}" width="100" height="26" /></td>
                        <td width="104">{{ now()->format('H:i A') }}</td>
                    </tr>
                    <tr id="row2"><td height="23" colspan="2">{{ $row->productcode }}</td></tr>
                    <tr id="row3"><td height="20" colspan="2">{{ $row->productname }}</td></tr>
                    <tr><td height="27" colspan="2"><div align="center"><div class="barcodeTarget{{ $i }}"></div></div></td></tr>
                    <tr id="row5"><td height="21" colspan="2">QTY/PLT: {{ $row->bundles }}</td></tr>
                    <tr id="row6"><td height="28" colspan="2">{{ $row->factory }}&#8212;{{ $row->linename }}</td></tr>
                </table>
            </div>
        @endforeach
    </div>

    <script src="{{ asset('js/jquery-barcode.js') }}"></script>
    <script type="text/javascript">
        var codes = @json($rows->pluck('barcode'));
        window.addEventListener('load', function () {
            codes.forEach(function (code, i) {
                $('.barcodeTarget' + i).html('').barcode(String(code), 'code93');
            });
            window.print();
            setTimeout(function () { window.close(); }, 150000);
        });
    </script>
</body>
</html>
