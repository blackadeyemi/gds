/* Shared theme (light/dark/system) + text-size (small/medium/large) controls.
   The pre-paint snippet in the layout <head> applies the saved values before
   render; this file wires the interactive controls and keeps them in sync.
   Persisted in localStorage (gds_theme / gds_font) so the choice follows the
   user across login and the admin until we sync it to their profile. */
(function () {
    var root = document.documentElement;
    var mq = window.matchMedia('(prefers-color-scheme: dark)');

    function sync(selector, attr, value) {
        document.querySelectorAll(selector).forEach(function (b) {
            b.classList.toggle('active', b.getAttribute(attr) === value);
        });
    }
    function applyFont(f) {
        root.setAttribute('data-font', f);
        try { localStorage.setItem('gds_font', f); } catch (e) {}
        sync('[data-font-opt]', 'data-font-opt', f);
    }
    function applyTheme(mode) {
        root.setAttribute('data-theme-mode', mode);
        try { localStorage.setItem('gds_theme', mode); } catch (e) {}
        var dark = mode === 'system' ? mq.matches : mode === 'dark';
        root.setAttribute('data-theme', dark ? 'dark' : 'light');
        sync('[data-theme-opt]', 'data-theme-opt', mode);
    }

    function wire() {
        document.querySelectorAll('[data-font-opt]').forEach(function (b) {
            b.addEventListener('click', function () { applyFont(b.getAttribute('data-font-opt')); });
        });
        document.querySelectorAll('[data-theme-opt]').forEach(function (b) {
            b.addEventListener('click', function () { applyTheme(b.getAttribute('data-theme-opt')); });
        });
        sync('[data-font-opt]', 'data-font-opt', root.getAttribute('data-font') || 'small');
        sync('[data-theme-opt]', 'data-theme-opt', root.getAttribute('data-theme-mode') || 'system');
    }

    mq.addEventListener('change', function () {
        if ((root.getAttribute('data-theme-mode') || 'system') === 'system') applyTheme('system');
    });

    if (document.readyState !== 'loading') wire();
    else document.addEventListener('DOMContentLoaded', wire);

    // Re-sync after Livewire DOM updates so controls keep their active state.
    document.addEventListener('livewire:navigated', wire);
})();
