<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>{{ $label }}</title>
    <style>
        body { font-family: 'Inter', Arial, sans-serif; color: #111; margin: 32px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .meta { color: #666; font-size: 12px; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #ddd; }
        th { text-transform: uppercase; font-size: 11px; letter-spacing: .04em; color: #444; }
        @media print { body { margin: 12mm; } }
    </style>
</head>
<body onload="window.print()">
    <h1>{{ $label }}</h1>
    <div class="meta">Consumer Tissue Data System &middot; {{ now()->format('l, F j, Y \a\t g:i A') }}</div>
    <table>
        <thead>
            <tr>
                <th style="width:44px">#</th>
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
