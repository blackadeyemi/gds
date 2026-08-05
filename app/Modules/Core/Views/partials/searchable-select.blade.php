{{--
    Searchable single-select bound to a Livewire property.

    Params:
      $field       (string)  Livewire property name to bind (e.g. 'role_id').
      $options     (iterable) rows to choose from.
      $valueKey    (string)  option value accessor (default 'id').
      $labelKey    (string)  option label accessor (default 'name').
      $placeholder (string)  shown when nothing is selected.
      $live        (bool)    hit the server on select (for dependent fields).
      $disabled    (bool)    render as a disabled control.
      $key         (string)  optional wire:key — pass a value that changes with
                             the option set (e.g. the parent id) so the control
                             re-initialises with fresh options after a morph.
--}}
@php
    $ssValueKey = $valueKey ?? 'id';
    $ssLabelKey = $labelKey ?? 'name';
    $ssOptions = collect($options)->map(fn ($o) => [
        'value' => (string) data_get($o, $ssValueKey),
        'label' => (string) data_get($o, $ssLabelKey),
    ])->values();
    $ssDisabled = $disabled ?? false;
@endphp
<div class="ss" wire:key="{{ $key ?? ('ss-' . $field) }}"
     x-data="searchableSelect({ field: @js($field), options: {{ Illuminate\Support\Js::from($ssOptions) }}, live: {{ ($live ?? false) ? 'true' : 'false' }} })"
     @keydown.escape.stop="close()"
     @click.outside="close()">
    <button type="button" class="form-control ss-trigger" @disabled($ssDisabled) @click="toggle()">
        <span class="ss-value" :class="{ 'ss-placeholder': !selectedLabel() }"
              x-text="selectedLabel() || @js($placeholder ?? 'Select…')"></span>
        <svg class="ss-caret" width="12" height="8" viewBox="0 0 12 8" fill="none"><path d="M1 1l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>

    @unless ($ssDisabled)
        <div class="ss-menu" x-show="open" x-cloak x-transition style="display:none;">
            <div class="ss-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                <input type="text" x-model="search" x-ref="q" placeholder="Search…"
                       @keydown.enter.prevent="chooseFirst()">
            </div>
            <div class="ss-list">
                <template x-for="o in filtered()" :key="o.value">
                    <button type="button" class="ss-option" :class="{ 'ss-selected': isSelected(o.value) }" @click="choose(o.value)">
                        <span x-text="o.label"></span>
                        <svg x-show="isSelected(o.value)" class="ss-check" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                    </button>
                </template>
                <div class="ss-empty" x-show="filtered().length === 0">No matches</div>
            </div>
        </div>
    @endunless
</div>

{{-- The Alpine component lives in public/js/searchable-select.js, loaded by the
     admin layout. It must NOT be pushed from here: this partial can render
     conditionally (inside a modal, behind a ply count), and a page that loads
     without any instance would then never register it — every control added
     later by a Livewire re-render would throw "searchableSelect is not
     defined". --}}
