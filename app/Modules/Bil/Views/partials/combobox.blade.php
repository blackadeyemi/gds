{{--
    Type-ahead single-select. Client-side filtered (lists are a few hundred
    rows). Writes the chosen value straight to the Livewire property via $wire.

    Params: $model (wire property name), $labelText, $placeholder,
            $items (iterable of ['value' => ..., 'label' => ...]).
--}}
<div class="form-group" wire:key="combobox-{{ $model }}"
     x-data="{
        open: false,
        query: '',
        label: '',
        value: null,
        items: @js(collect($items)->values()),
        init() {
            let v = $wire.get('{{ $model }}');
            if (v !== null && v !== '' && v !== undefined) {
                let hit = this.items.find(i => String(i.value) === String(v));
                if (hit) { this.value = hit.value; this.label = hit.label; this.query = hit.label; }
            }
            this.$watch('open', o => { if (o) this.query = ''; });
        },
        get filtered() {
            let q = this.query.trim().toLowerCase();
            let list = q === '' ? this.items : this.items.filter(i => i.label.toLowerCase().includes(q));
            return list.slice(0, 60);
        },
        choose(i) {
            this.value = i.value; this.label = i.label; this.query = i.label; this.open = false;
            $wire.set('{{ $model }}', i.value);
        },
        restore() { this.open = false; this.query = this.label; }
     }"
     @click.outside="restore()"
     @keydown.escape="restore()">
    <label class="form-label">{{ $labelText }}</label>
    <div class="combobox">
        <input type="text" class="form-control"
               :value="open ? query : label"
               @input="query = $event.target.value; open = true"
               @focus="open = true" @click="open = true"
               placeholder="{{ $placeholder }}" autocomplete="off" spellcheck="false">
        <div class="combobox-panel" x-show="open" x-cloak x-transition.opacity.duration.100ms>
            <template x-for="i in filtered" :key="i.value">
                <button type="button" class="combobox-item"
                        :class="{ 'is-selected': String(i.value) === String(value) }"
                        @click="choose(i)" x-text="i.label"></button>
            </template>
            <div class="combobox-empty" x-show="filtered.length === 0">No matches</div>
        </div>
    </div>
    @error($model) <div class="form-error">{{ $message }}</div> @enderror
</div>
