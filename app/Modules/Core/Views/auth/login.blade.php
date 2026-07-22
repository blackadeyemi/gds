<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Login &middot; Consumer Tissue Data System</title>
    <link type="image/x-icon" rel="icon" href="{{ asset('images/bilicon.ico') }}" />

    {{-- Apply saved theme + font size before paint to avoid a flash --}}
    <script>
        (function () {
            try {
                var f = localStorage.getItem('gds_font') || 'small';
                var mode = localStorage.getItem('gds_theme') || 'system';
                var root = document.documentElement;
                root.setAttribute('data-font', f);
                root.setAttribute('data-theme-mode', mode);
                var dark = mode === 'system'
                    ? window.matchMedia('(prefers-color-scheme: dark)').matches
                    : mode === 'dark';
                root.setAttribute('data-theme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>

    <link type="text/css" rel="stylesheet" href="{{ asset('css/gds-login.css') }}" />
</head>

<body>
    {{-- Font size + theme controls --}}
    <div class="settings-bar">
        <div class="seg" role="group" aria-label="Text size">
            <button type="button" class="fs-s" data-font-opt="small"  title="Small text">A</button>
            <button type="button" class="fs-m" data-font-opt="medium" title="Medium text">A</button>
            <button type="button" class="fs-l" data-font-opt="large"  title="Large text">A</button>
        </div>
        <div class="seg" role="group" aria-label="Theme">
            <button type="button" data-theme-opt="light" title="Light">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/></svg>
            </button>
            <button type="button" data-theme-opt="dark" title="Dark">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </button>
            <button type="button" data-theme-opt="system" title="System">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
            </button>
        </div>
    </div>

    <div class="login-shell">
        {{-- Two companies side by side --}}
        <div class="company-row">
            <img src="{{ asset('images/belimpex_brands logo.png') }}" alt="Belimpex" />
            <img src="{{ asset('images/belpapyrus_companies logo.png') }}" alt="Belpapyrus" />
        </div>
        {{-- Shared system brand, centred below --}}
        <div class="gds-row">
            <img src="{{ asset('images/GDS-1.png') }}" alt="Global Data System" />
        </div>

        <div class="card">
            <h1>Sign in</h1>
            <p class="subtitle">Consumer Tissue Data System</p>

            @if (session('login_error'))
                <div class="alert-error">{{ session('login_error') }}</div>
            @endif

            <form method="post" action="{{ route('login') }}">
                @csrf
                <div class="field">
                    <label for="username">Username</label>
                    <input name="username" id="username" type="text"
                           value="{{ old('username') }}" placeholder="Enter your username"
                           required autofocus autocomplete="username">
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input name="password" id="password" type="password"
                           placeholder="Enter your password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn-login">Login</button>
            </form>
        </div>

        <div class="footer">
            <strong>Consumer Tissue Data System</strong><br />
            Tissue Manufacturing Solution and Access Control<br />
            <span class="dev">developed by</span> I.T. Department, Software Development Team
            <div class="links">
                <a href="http://www.belimpex.ng">www.belimpex.ng</a>
                <a href="http://www.belpapyrus.ng">www.belpapyrus.ng</a>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var root = document.documentElement;
            var mq = window.matchMedia('(prefers-color-scheme: dark)');

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
            function sync(selector, attr, value) {
                document.querySelectorAll(selector).forEach(function (b) {
                    b.classList.toggle('active', b.getAttribute(attr) === value);
                });
            }

            document.querySelectorAll('[data-font-opt]').forEach(function (b) {
                b.addEventListener('click', function () { applyFont(b.getAttribute('data-font-opt')); });
            });
            document.querySelectorAll('[data-theme-opt]').forEach(function (b) {
                b.addEventListener('click', function () { applyTheme(b.getAttribute('data-theme-opt')); });
            });
            mq.addEventListener('change', function () {
                if ((root.getAttribute('data-theme-mode') || 'system') === 'system') applyTheme('system');
            });

            // Reflect current state on the controls
            sync('[data-font-opt]', 'data-font-opt', root.getAttribute('data-font') || 'small');
            sync('[data-theme-opt]', 'data-theme-opt', root.getAttribute('data-theme-mode') || 'system');
        })();
    </script>
    <script type="text/javascript" src="{{ asset('js/show_hide_password.js') }}"></script>
</body>
</html>
