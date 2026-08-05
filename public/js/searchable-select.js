/**
 * Alpine component behind the `core::partials.searchable-select` partial: a
 * type-ahead single-select bound to a Livewire property.
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
            this.close();
        },
        chooseFirst() {
            const f = this.filtered();
            if (f.length) this.choose(f[0].value);
        },
    }));
});
