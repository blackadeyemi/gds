{{--
    Searchable single-select for a report filter. Client-side type-ahead over
    the given options (a few hundred rows), with an "All" entry that clears the
    filter. Writes the chosen value to `filters.<name>` via $wire (live).

    Params: $name (filter key), $label, $options ([value => label]).
--}}
<div class="form-group" style="margin:0;min-width:170px;" wire:key="rfilter-{{ $name }}"
     x-data="{
        open: false,
        query: '',
        label: 'All',
        items: @js(collect($options)->map(fn ($l, $v) => ['value' => (string) $v, 'label' => (string) $l])->values()->prepend(['value' => '', 'label' => 'All'])),
        current() { return String($wire.get('filters.{{ $name }}') ?? ''); },
        init() {
            let hit = this.items.find(i => i.value === this.current());
            this.label = hit ? hit.label : 'All';
            this.$watch('open', o => { if (o) this.query = ''; });
        },
        get filtered() {
            let q = this.query.trim().toLowerCase();
            let list = q === '' ? this.items : this.items.filter(i => i.label.toLowerCase().includes(q));
            return list.slice(0, 80);
        },
        choose(i) {
            this.label = i.label; this.query = i.label; this.open = false;
            $wire.set('filters.{{ $name }}', i.value);
        },
        restore() { this.open = false; this.query = this.label; }
     }"
     @click.outside="restore()" @keydown.escape="restore()">
    <label class="form-label text-sm">{{ $label }}</label>
    <div class="combobox">
        <input type="text" class="form-control" style="min-width:150px;"
               :value="open ? query : label"
               @input="query = $event.target.value; open = true"
               @focus="open = true" @click="open = true"
               placeholder="{{ $label }}" autocomplete="off" spellcheck="false">
        <div class="combobox-panel" x-show="open" x-cloak x-transition.opacity.duration.100ms>
            <template x-for="i in filtered" :key="i.value">
                <button type="button" class="combobox-item"
                        :class="{ 'is-selected': i.value === current() }"
                        @click="choose(i)" x-text="i.label"></button>
            </template>
            <div class="combobox-empty" x-show="filtered.length === 0">No matches</div>
        </div>
    </div>
</div>
