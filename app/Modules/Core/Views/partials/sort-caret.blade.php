@if ($sortField === $field)
    <span style="color:var(--brand);font-size:0.7em;">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>
@else
    <span style="opacity:.3;font-size:0.7em;">▲</span>
@endif
