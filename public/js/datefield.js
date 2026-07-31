/**
 * Date entry, app-wide. An Alpine component (`dateField`) that turns a plain
 * text input into a flatpickr calendar. The form always works in ISO `Y-m-d`
 * (flatpickr's real value), while the visible altInput DISPLAYS the user's
 * chosen format (Appearance → Date format; shared with server-rendered reports
 * via the gds_date_format cookie). The value is pushed to the Livewire property
 * via $wire.set (live for the report's date range, deferred for the operation
 * pages' single date).
 *
 * Typing is format-agnostic: a custom parseDate accepts numeric (d/m/Y or
 * m/d/Y per the selected format), ISO (Y-m-d), and month-name input, so a date
 * can always be typed even when the display format uses a month name — the
 * calendar covers everything else. Only INPUT parsing is customised; flatpickr's
 * own formatDate still renders the altInput in the chosen (month-name) format.
 *
 * The wrapper carries wire:ignore so Livewire re-renders don't wipe flatpickr's
 * injected input; the value is read from $wire on init, and flows back out on change.
 */
(function () {
    var DATE_FORMATS = ['d/M/Y', 'd/m/Y', 'Y-m-d', 'm/d/Y', 'd M Y', 'M j, Y'];

    // Chosen display format; falls back to the reports' default (d/M/Y) so entry
    // and reports stay unified. Typing still works for any format (see parseDate).
    function displayFormat() {
        var m = document.cookie.match(/(?:^|;\s*)gds_date_format=([^;]+)/);
        var v = m ? decodeURIComponent(m[1]) : '';
        return DATE_FORMATS.indexOf(v) !== -1 ? v : 'd/M/Y';
    }

    var MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    function monthIndex(word) {
        return MONTHS.indexOf(word.slice(0, 3).toLowerCase()); // 0–11, or -1
    }

    // Build a Date, rejecting impossible/overflowing values (e.g. 31 April).
    function makeDate(y, mon, day) {
        if (y < 100) { y += 2000; }
        if (mon < 1 || mon > 12 || day < 1 || day > 31) { return undefined; }
        var d = new Date(y, mon - 1, day);
        if (d.getFullYear() !== y || d.getMonth() !== mon - 1 || d.getDate() !== day) {
            return undefined;
        }
        return d;
    }

    /**
     * Permissive string→Date parser. flatpickr only calls this for string input
     * ('today', Date and numeric inputs are handled before us), and passes the
     * format in play (altFormat when the user edits the visible field), which we
     * use only to disambiguate numeric day/month order.
     */
    function parseDatePermissive(str, format) {
        str = String(str == null ? '' : str).trim();
        if (!str) { return undefined; }
        if (str.toLowerCase() === 'today') { var t = new Date(); return new Date(t.getFullYear(), t.getMonth(), t.getDate()); }

        // 1) Year-first / ISO: 2026-07-03 (also how flatpickr feeds back the value).
        var m = str.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/);
        if (m) { return makeDate(+m[1], +m[2], +m[3]); }

        // 2) A month name anywhere: "03 Jul 2026", "Jul 3, 2026", "3/Jul/2026".
        var words = str.match(/[A-Za-z]{3,}/g);
        if (words) {
            var mi = -1;
            for (var i = 0; i < words.length && mi === -1; i++) { mi = monthIndex(words[i]); }
            if (mi !== -1) {
                var nums = str.match(/\d+/g) || [];
                var year = null, day = null;
                nums.forEach(function (n) {
                    if (n.length === 4 && year === null) { year = +n; }
                    else if (day === null) { day = +n; }
                });
                if (year !== null && day !== null) { return makeDate(year, mi + 1, day); }
            }
            return undefined; // has letters but not a usable month-name date
        }

        // 3) All-numeric day/month/year — order per the selected format.
        m = str.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})$/);
        if (m) {
            var monthFirst = !!format
                && format.indexOf('m') !== -1
                && format.indexOf('d') !== -1
                && format.indexOf('m') < format.indexOf('d');
            var day = monthFirst ? +m[2] : +m[1];
            var mon = monthFirst ? +m[1] : +m[2];
            return makeDate(+m[3], mon, day);
        }

        return undefined;
    }

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
                    altFormat: displayFormat(),    // what the user sees
                    altInputClass: 'form-control',
                    allowInput: !o.disabled,
                    clickOpens: !o.disabled,
                    maxDate: o.max ? 'today' : null,
                    defaultDate: current || null,
                    disableMobile: true,
                    // Accept numeric / ISO / month-name typing regardless of altFormat.
                    parseDate: (str, format) => parseDatePermissive(str, format),
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
