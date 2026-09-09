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

    SERVER-SIDE SEARCH (opt in). These are ss-prefixed on purpose: @include
    inherits the PARENT view's variables, and a plain $search would have been
    filled in by any component that happens to have a `search` property —
    quietly switching every select on that page into a mode it cannot work in.
      $ssSearch        (string) name of a Livewire property to bind the search
                              box to. Setting it changes the control's whole
                              shape: $options is then what the SERVER already
                              matched, nothing is filtered in the browser, and
                              the list is rendered by Blade, not by Alpine.
      $ssSelectedLabel (string) what to show on the trigger, since the chosen
                              option is usually not in the current match list.
      $ssSelected      (mixed) the currently bound value, for the tick. Defaults
                              to the public property named by $field.
      $ssSearchPlaceholder, $ssEmpty, $ssHint (string) wording, all optional.

    ⚠️ WHY THE SERVER MODE EXISTS. The default mode snapshots every option into
    Alpine's x-data and re-filters the whole array on each keystroke. That is
    right for a few hundred rows and fatal beyond it: the New Loading order
    picker grew to 8,286 options — about 550KB of JSON in the page — and froze
    the browser so hard that nothing on the screen could be clicked. A cap is
    NOT the fix, because a cap silently hides orders. Searching on the server is.
--}}
@php
    $ssValueKey = $valueKey ?? 'id';
    $ssLabelKey = $labelKey ?? 'name';
    $ssOptions = collect($options)->map(fn ($o) => [
        'value' => (string) data_get($o, $ssValueKey),
        'label' => (string) data_get($o, $ssLabelKey),
    ])->values();
    $ssDisabled = $disabled ?? false;
    $ssPlaceholder = $placeholder ?? 'Select…';
    $ssRemote = $ssSearch ?? null;

    // Livewire extracts public properties into the view, so the bound value is
    // usually reachable by its own name. Only needed in server mode, where the
    // tick is drawn by Blade rather than by Alpine.
    $ssCurrent = $ssSelected ?? (isset(${$field}) ? ${$field} : null);
    $ssLabel = $ssSelectedLabel ?? null;
@endphp
<div class="ss" wire:key="{{ $key ?? ('ss-' . $field) }}"
     x-data="searchableSelect({ field: @js($field), options: {{ $ssRemote ? '[]' : Illuminate\Support\Js::from($ssOptions) }}, live: {{ ($live ?? false) ? 'true' : 'false' }}, search: @js($ssRemote) })"
     @keydown.escape.stop="close()"
     @click.outside="close()">
    <button type="button" class="form-control ss-trigger" @disabled($ssDisabled) @click="toggle()">
        @if ($ssRemote)
            <span class="ss-value @class(['ss-placeholder' => (string) $ssLabel === ''])">{{ (string) $ssLabel !== '' ? $ssLabel : $ssPlaceholder }}</span>
        @else
            <span class="ss-value" :class="{ 'ss-placeholder': !selectedLabel() }"
                  x-text="selectedLabel() || @js($ssPlaceholder)"></span>
        @endif
        <svg class="ss-caret" width="12" height="8" viewBox="0 0 12 8" fill="none"><path d="M1 1l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>

    @unless ($ssDisabled)
        <div class="ss-menu" x-show="open" x-cloak x-transition style="display:none;">
            <div class="ss-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                @if ($ssRemote)
                    <input type="text" wire:model.live.debounce.300ms="{{ $ssRemote }}" x-ref="q"
                           placeholder="{{ $ssSearchPlaceholder ?? 'Search…' }}"
                           @keydown.enter.prevent="chooseFirst()">
                @else
                    <input type="text" x-model="search" x-ref="q" placeholder="Search…"
                           @keydown.enter.prevent="chooseFirst()">
                @endif
            </div>
            <div class="ss-list">
                @if ($ssRemote)
                    @foreach ($ssOptions as $o)
                        <button type="button" class="ss-option @class(['ss-selected' => (string) $ssCurrent === $o['value']])"
                                @click="choose(@js($o['value']))">
                            <span>{{ $o['label'] }}</span>
                            @if ((string) $ssCurrent === $o['value'])
                                <svg class="ss-check" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                            @endif
                        </button>
                    @endforeach
                    @if ($ssOptions->isEmpty())
                        <div class="ss-empty">{{ $ssEmpty ?? 'No matches' }}</div>
                    @endif
                    @isset($ssHint)
                        <div class="ss-empty" style="text-align:left;">{{ $ssHint }}</div>
                    @endisset
                @else
                    <template x-for="o in filtered()" :key="o.value">
                        <button type="button" class="ss-option" :class="{ 'ss-selected': isSelected(o.value) }" @click="choose(o.value)">
                            <span x-text="o.label"></span>
                            <svg x-show="isSelected(o.value)" class="ss-check" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                        </button>
                    </template>
                    <div class="ss-empty" x-show="filtered().length === 0">No matches</div>
                @endif
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
