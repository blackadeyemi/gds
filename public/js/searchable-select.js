/**
 * Alpine component behind the `core::partials.searchable-select` partial: a
 * type-ahead single-select bound to a Livewire property.
 *
 * Two modes. By default it holds every option and filters them in the browser.
 * Given `search` (the name of a Livewire property), it instead binds the search
 * box to the server, the option list is rendered by Blade from whatever the
 * server matched, and this component only opens, closes and selects. See the
 * partial's own note for why: the client mode cannot carry thousands of rows.
 *
 * Registered globally (loaded by the admin layout) rather than pushed from the
 * partial itself. The partial used `@once @push('scripts')`, which only emits
 * when an instance actually renders — so a page whose only searchable-selects
 * live inside a modal, or behind a condition, loaded WITHOUT the registration
 * and every one of those controls threw "searchableSelect is not defined" the
 * moment Livewire rendered it in.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('searchableSelect', (config) => ({
        field: config.field,
        options: config.options || [],
        live: config.live || false,
        // The Livewire property the search box writes to, in server mode.
        searchProp: config.search || null,
        remote: !! config.search,
        open: false,
        search: '',
        current() {
            // Reactive read, so the trigger label updates when the bound value
            // changes. $wire.get() (not $wire[prop]) because the field may be a
            // nested path such as 'gradeTypes.0' — bracket access on the $wire
            // proxy falls through to its action-calling fallback for those and
            // returns a function instead of the value.
            return this.$wire.get(this.field);
        },
        selectedLabel() {
            const v = this.current();
            if (v === null || v === undefined || v === '') return '';
            const o = this.options.find((o) => String(o.value) === String(v));
            return o ? o.label : '';
        },
        isSelected(v) {
            return String(this.current() ?? '') === String(v);
        },
        filtered() {
            // In server mode the list is already the answer, and Blade — not
            // x-for — draws it. Nothing to filter here.
            if (this.remote) return this.options;
            const s = this.search.trim().toLowerCase();
            if (!s) return this.options;
            return this.options.filter((o) => o.label.toLowerCase().includes(s));
        },
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => this.$refs.q && this.$refs.q.focus());
            }
        },
        close() {
            this.open = false;
            this.search = '';
        },
        choose(v) {
            this.$wire.set(this.field, v, this.live);
            // Leave the box clean for next time. Deferred, so it rides along
            // with the set above rather than costing a second round trip.
            if (this.remote && this.searchProp) this.$wire.set(this.searchProp, '', false);
            this.close();
        },
        chooseFirst() {
            // Server mode reads the rendered list rather than this.options:
            // x-data is evaluated once, so the array it was built with goes
            // stale the moment Livewire morphs in a new set of matches.
            if (this.remote) {
                const first = this.$el.querySelector('.ss-option');
                if (first) first.click();
                return;
            }
            const f = this.filtered();
            if (f.length) this.choose(f[0].value);
        },
    }));
});
