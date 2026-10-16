{{--
    One step of a BPL order's trail, as a coloured badge.

    The colour carries the meaning so a long trail can be skimmed: green for the
    steps that close something (shipped, arrived, paid), muted for the
    record-keeping ones. Params: $stage, $label.
--}}
@php
    $tone = [
        'order' => 'badge-muted',
        'proforma' => 'badge-muted',
        'approved' => 'badge-success',
        'produced' => 'badge-muted',
        'loaded' => 'badge-muted',
        'shipment' => 'badge-success',
        'bl' => 'badge-muted',
        'arrival' => 'badge-success',
        'payment' => 'badge-success',
    ][$stage] ?? 'badge-muted';
@endphp
<span class="badge {{ $tone }}" style="white-space:nowrap;">{{ $label }}</span>
