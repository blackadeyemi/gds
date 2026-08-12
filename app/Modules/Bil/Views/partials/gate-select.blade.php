{{--
    Gate picker for the scanning screens.

    Expects:
      $label   field label ("Entrance", "Exit Location", …)
      $gates   Collection of WarehouseGate or FactoryGate the user may use
      $field   the Livewire property to bind, e.g. 'gate_id'
      $group   closure gate => group heading (warehouse or factory name)
      $empty   what to say when the user has no gates

    Gates are grouped under their parent so a user with several warehouses can
    tell them apart. An empty list is stated plainly rather than rendered as an
    empty select — "why is this dropdown blank" is a support call.
--}}
<div class="form-group">
    <label class="form-label">{{ $label }}</label>
    @if ($gates->isEmpty())
        <input type="text" class="form-control" value="{{ $empty ?? 'None assigned' }}" disabled>
    @else
        <select class="form-control" wire:model="{{ $field }}">
            @foreach ($gates->groupBy($group) as $heading => $group_)
                <optgroup label="{{ $heading }}">
                    @foreach ($group_ as $gate)
                        <option value="{{ $gate->id }}">{{ $gate->name }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    @endif
    @error($field) <div class="form-error">{{ $message }}</div> @enderror
</div>
