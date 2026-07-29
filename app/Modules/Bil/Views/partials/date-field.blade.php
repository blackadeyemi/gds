{{--
    Reusable DD/MM/YYYY date entry (flatpickr, altInput). Renders a calendar field
    that shows/accepts d/m/Y while binding the ISO Y-m-d value to a Livewire
    property. Replaces bare <input type="date">, whose format follows the
    browser/OS locale and can't be forced to day-first.

    Params:
      $model    (string, required) Livewire property to bind, e.g. 'dateFrom'.
      $live     (bool)  push changes live (re-render) vs deferred. Default false.
      $max      (bool)  cap at today. Default true.
      $disabled (bool)  render read-only (no calendar). Default false.
      $compact  (bool)  narrow width (for inline filter bars). Default false.
--}}
@php
    $live = $live ?? false;
    $max = $max ?? true;
    $disabled = $disabled ?? false;
    $compact = $compact ?? false;
@endphp
<div wire:ignore class="datefield-wrap{{ $compact ? ' datefield-compact' : '' }}"
     x-data="dateField({ model: @js($model), live: {{ $live ? 'true' : 'false' }}, max: {{ $max ? 'true' : 'false' }}, disabled: {{ $disabled ? 'true' : 'false' }} })">
    <input type="text" x-ref="input" class="form-control" placeholder="DD/MM/YYYY" autocomplete="off" @disabled($disabled)>
</div>
