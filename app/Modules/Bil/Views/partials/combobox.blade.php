{{--
    Type-ahead single-select — the entry-screen picker: you type straight into
    the field and the list filters as you go (client-side; lists here are a few
    hundred rows). Writes the chosen value to a Livewire property.

    Params:
      $model     (string, required) Livewire property to bind, e.g. 'productid'
                 or a nested path such as 'rows.7.productid'.
      $items     (iterable) rows of ['value' => …, 'label' => …].
      $itemsExpr (string)   Alpine EXPRESSION naming a list already in scope,
                 used INSTEAD of $items. For a grid of rows sharing one long
                 list, declare it once on an enclosing x-data and name it here —
                 otherwise every row embeds its own copy of the whole list.
      $labelText (string)   field label; omitted when $bare.
      $placeholder (string)
      $bare      (bool)  drop the .form-group wrapper and the label, for use in
                 a table cell. Default false.
      $live      (bool)  hit the server on select. Default TRUE — a dependent
                 field usually needs it. Pass false in a grid where nothing
                 server-side changes, so picking does not re-render the page.
      $key       (string) wire:key override; needed when the same $model shape
                 repeats (grid rows).

    The displayed label is DERIVED from the bound value on every render rather
    than captured in init(). A one-shot init goes stale the moment the server
    changes the value behind an unchanged wire:key — e.g. loading a different
    record into the same grid row — and the field then shows the previous
    record's choice while holding the new record's value.
--}}
@php
    $cbBare = $bare ?? false;
    $cbLive = $live ?? true;
    $cbItems = ($itemsExpr ?? null) ?: Illuminate\Support\Js::from(collect($items ?? [])->values());
@endphp
<div class="{{ $cbBare ? 'combobox-bare' : 'form-group' }}" wire:key="{{ $key ?? ('combobox-' . $model) }}"
     x-data="{
        open: false,
        query: '',
        items: {{ $cbItems }},
        get value() { return this.$wire.get(@js($model)); },
        get label() {
            const v = this.value;
            if (v === null || v === undefined || v === '') return '';
            const hit = this.items.find(i => String(i.value) === String(v));
            return hit ? hit.label : '';
        },
        init() {
            this.query = this.label;
            this.$watch('open', o => { if (o) this.query = ''; });
        },
        get filtered() {
            let q = this.query.trim().toLowerCase();
            let list = q === '' ? this.items : this.items.filter(i => i.label.toLowerCase().includes(q));
            return list.slice(0, 60);
        },
        choose(i) {
            this.query = i.label;
            this.open = false;
            this.$wire.set(@js($model), i.value, {{ $cbLive ? 'true' : 'false' }});
        },
        clear() {
            this.query = '';
            this.open = false;
            this.$wire.set(@js($model), null, {{ $cbLive ? 'true' : 'false' }});
        },
        restore() { this.open = false; this.query = this.label; }
     }"
     @click.outside="restore()"
     @keydown.escape="restore()">
    @unless ($cbBare)
        <label class="form-label">{{ $labelText }}</label>
    @endunless
    <div class="combobox">
        <input type="text" class="form-control"
               :value="open ? query : label"
               @input="query = $event.target.value; open = true"
               @focus="open = true" @click="open = true"
               @keydown.enter.prevent="filtered.length && choose(filtered[0])"
               placeholder="{{ $placeholder ?? 'Search…' }}" autocomplete="off" spellcheck="false"
               @if ($disabled ?? false) disabled @endif>
        @unless ($disabled ?? false)
            <div class="combobox-panel" x-show="open" x-cloak x-transition.opacity.duration.100ms>
                <template x-for="i in filtered" :key="i.value">
                    <button type="button" class="combobox-item"
                            :class="{ 'is-selected': String(i.value) === String(value) }"
                            @click="choose(i)" x-text="i.label"></button>
                </template>
                <div class="combobox-empty" x-show="filtered.length === 0">No matches</div>
                <button type="button" class="combobox-item combobox-clear" x-show="value" @click="clear()">Clear selection</button>
            </div>
        @endunless
    </div>
    @error($model) <div class="form-error">{{ $message }}</div> @enderror
</div>
