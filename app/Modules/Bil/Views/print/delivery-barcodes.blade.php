<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raw Materials — Print Barcodes</title>
    <link rel="icon" href="{{ asset('images/bilicon.ico') }}" />
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <style type="text/css">
        body { background:#FDFDFD; padding:0; margin:0; }
        body > table:last-of-type { page-break-after:auto; }
        table { page-break-after:always; }
        @media print {
            @page { margin:0; size:3.95in 2in; }
        }
        .printdiv {
            height:165px; width:375px;
            margin:5px auto 30px auto;
        }
        #row1 { font-family:"Trebuchet MS", Arial, Helvetica, sans-serif; font-size:9px; }
        #row2 { font-family:Tahoma, Geneva, sans-serif; font-size:13px; font-weight:bold; text-align:left; text-transform:uppercase; }
        #row3 { font-family:Tahoma, Geneva, sans-serif; font-size:12px; font-weight:bold; text-align:center; text-transform:uppercase; }
        #row4 { font-family:Verdana, Geneva, sans-serif; }
        #row5 { font-family:Tahoma, Geneva, sans-serif; font-size:11px; font-weight:bold; text-align:left; }
        #row6 { font-family:Arial, Helvetica, sans-serif; font-size:11px; font-weight:bold; text-align:center; text-transform:uppercase; }
    </style>
</head>
<body>
    <div style="width:100%;background:#fff">
        @foreach ($rows as $i => $row)
            <div class="printdiv">
                <table width="370" align="center">
                    <tr id="row1">
                        <td width="241" height="25"><img src="{{ asset('images/belimpex-barcode.png') }}" width="100" height="26" /></td>
                        <td width="117">{{ now()->format('d-m-y h:i:s') }}</td>
                    </tr>
                    <tr id="row2"><td height="13" colspan="2">{{ $row->storecode }}</td></tr>
                    <tr id="row3"><td height="10" colspan="2">{{ $row->productname }}</td></tr>
                    <tr id="row4"><td height="5" colspan="2"><div align="center"><div class="barcodeTarget{{ $i }}"></div></div></td></tr>
                    <tr id="row5">
                        <td height="21">WEIGHT/PIECES:{{ $row->weight }}</td>
                        <td>{{ $row->groupcode }}</td>
                    </tr>
                    <tr id="row6"><td height="28" colspan="2">{{ $row->suppliercode }}</td></tr>
                </table>
            </div>
        @endforeach
    </div>

    <script src="{{ asset('js/jquery-barcode.js') }}"></script>
    <script type="text/javascript">
        var passedArray = @json($rows->pluck('barcode'));
        window.addEventListener('load', function () {
            passedArray.forEach(function (code, i) {
                $('.barcodeTarget' + i).html('').barcode(String(code), 'code93');
            });
            window.print();
            setTimeout(function () { window.close(); }, 150000);
        });
    </script>
</body>
</html>
