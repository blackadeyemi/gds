/**
 * DD/MM/YYYY date entry, app-wide. An Alpine component (`dateField`) that turns a
 * plain text input into a flatpickr calendar: the visible field shows/accepts
 * `d/m/Y` (e.g. 29/07/2026) while the form still works in ISO `Y-m-d` — flatpickr's
 * altInput. The value is pushed to the Livewire property via $wire.set (live for
 * the report's date range, deferred for the operation pages' single date).
 *
 * The wrapper carries wire:ignore so Livewire re-renders don't wipe flatpickr's
 * injected input; the value is read from $wire on init, and flows back out on change.
 */
(function () {
    document.addEventListener('alpine:init', () => {
        if (!window.Alpine) {
            return;
        }
        window.Alpine.data('dateField', (opts) => ({
            fp: null,
            init() {
                if (!window.flatpickr) {
                    return;
                }
                const o = opts || {};
                const model = o.model;
                const current = this.$wire ? this.$wire.get(model) : null;

                this.fp = window.flatpickr(this.$refs.input, {
                    dateFormat: 'Y-m-d',           // what the app stores / submits
                    altInput: true,
                    altFormat: 'd/m/Y',            // what the user sees / types
                    altInputClass: 'form-control',
                    allowInput: !o.disabled,
                    clickOpens: !o.disabled,
                    maxDate: o.max ? 'today' : null,
                    defaultDate: current || null,
                    disableMobile: true,
                    onChange: (dates, str) => {
                        if (this.$wire) {
                            this.$wire.set(model, str, !!o.live);
                        }
                    },
                });

                if (o.disabled && this.fp.altInput) {
                    this.fp.altInput.setAttribute('disabled', 'disabled');
                }
            },
            destroy() {
                if (this.fp) {
                    this.fp.destroy();
                    this.fp = null;
                }
            },
        }));
    });
})();
