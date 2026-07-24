<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>{{ $label }}</title>
    {{-- Lean styling: dompdf is slow with per-cell borders, so only the header
         is underlined and padding is minimal. Keeps large exports responsive. --}}
    <style>
        * { margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; color: #111; margin: 14px; }
        h1 { font-size: 15px; margin-bottom: 2px; }
        .meta { color: #666; font-size: 10px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        thead th { text-align: left; border-bottom: 1.5px solid #333; padding: 3px 5px; text-transform: uppercase; font-size: 9px; }
        tbody td { text-align: left; padding: 2px 5px; }
    </style>
</head>
<body>
    <h1>{{ $label }}</h1>
    <div class="meta">Consumer Tissue Data System &middot; {{ now()->format('l, F j, Y \a\t g:i A') }} &middot; {{ count($rows) }} row(s)</div>
    <table>
        <thead>
            <tr>
                <th style="width:34px">#</th>
                @foreach ($headings as $h)<th>{{ $h }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $row)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    @foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headings) + 1 }}">No records.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
