{{--
    One stage of an order's trail, as a coloured badge.

    The colour carries the meaning so a long trail can be skimmed: green for the
    two confirmations that close a step (delivered, waybilled), amber for stock
    coming back, muted for the record-keeping ones. Params: $stage, $label.
--}}
@php
    $tone = [
        'order' => 'badge-muted',
        'loading' => 'badge-muted',
        'unload' => 'badge-warning',
        'delivery' => 'badge-success',
        'waybill' => 'badge-success',
        'return' => 'badge-warning',
    ][$stage] ?? 'badge-muted';
@endphp
<span class="badge {{ $tone }}" style="white-space:nowrap;">{{ $label }}</span>
