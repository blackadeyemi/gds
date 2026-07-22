@php
    // Breadcrumb from the current path (e.g. admin/roles -> "Admin / Roles")
    $segments = collect(explode('/', trim(request()->path(), '/')))->filter();
    $crumb = $segments->isEmpty() ? collect(['Dashboard']) : $segments->map(fn ($s) => \Illuminate\Support\Str::headline($s));
    $path = request()->path();
    $is = fn ($p) => request()->is($p) ? 'active' : '';
    $onAdmin = request()->is('admin/*');
    $onSettings = request()->is('settings/*');
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="light" data-font="small">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $title ?? 'Admin' }} &middot; Consumer Tissue Data System</title>
    <link rel="icon" href="{{ asset('images/bilicon.ico') }}" />
    <script>
        (function () {
            try {
                var f = localStorage.getItem('gds_font') || 'small';
                var mode = localStorage.getItem('gds_theme') || 'system';
                var r = document.documentElement;
                r.setAttribute('data-font', f);
                r.setAttribute('data-theme-mode', mode);
                var dark = mode === 'system' ? window.matchMedia('(prefers-color-scheme: dark)').matches : mode === 'dark';
                r.setAttribute('data-theme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}" />
    @livewireStyles
</head>
<body>
<div class="app"
     x-data="{
        collapsed: JSON.parse(localStorage.getItem('gds_sidebar_collapsed') ?? 'true'),
        mobileOpen: false,
        adminOpen: {{ $onAdmin ? 'true' : 'false' }},
        settingsOpen: {{ $onSettings ? 'true' : 'false' }},
        toggleSidebar() {
            if (window.innerWidth <= 900) { this.mobileOpen = !this.mobileOpen; return; }
            this.collapsed = !this.collapsed;
            localStorage.setItem('gds_sidebar_collapsed', JSON.stringify(this.collapsed));
            if (this.collapsed) { this.adminOpen = false; this.settingsOpen = false; }
        },
        openGroup(group) {
            if (this.collapsed) {
                this.collapsed = false;
                localStorage.setItem('gds_sidebar_collapsed', 'false');
                this[group] = true;
            } else {
                this[group] = !this[group];
            }
        }
     }"
     :class="{ collapsed: collapsed, 'mobile-open': mobileOpen }">
    <div class="sidebar-backdrop" @click="mobileOpen = false"></div>

    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="{{ asset('images/GDS-1.png') }}" alt="GDS" />
            <div class="brand-text">
                <strong>GDS</strong>
                <span>Consumer Tissue Data System</span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="{{ url('/') }}" class="nav-link {{ $is('/') }}" title="Dashboard">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                <span class="label">Dashboard</span>
            </a>

            @canany(['view-user', 'view-role', 'view-permission', 'view-department', 'view-company'])
            <div class="nav-group" :class="{ open: adminOpen }">
                <button type="button" class="nav-link" :class="{ active: {{ $onAdmin ? 'true' : 'false' }} && collapsed }" @click="openGroup('adminOpen')" title="Admin">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l7 4v6c0 5-3.5 8-7 10-3.5-2-7-5-7-10V6l7-4z"/><circle cx="12" cy="10" r="2.2"/><path d="M8.5 16a3.5 3.5 0 0 1 7 0"/></svg>
                    <span class="label">Admin</span>
                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <div class="nav-sub" x-show="adminOpen">
                    @can('view-user')
                    <a href="{{ url('/admin/users') }}" class="nav-link {{ $is('admin/users*') }}" title="Users">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span class="label">Users</span>
                    </a>
                    @endcan
                    @can('view-role')
                    <a href="{{ url('/admin/roles') }}" class="nav-link {{ $is('admin/roles*') }}" title="Role">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span class="label">Role</span>
                    </a>
                    @endcan
                    @can('view-permission')
                    <a href="{{ url('/admin/permissions') }}" class="nav-link {{ $is('admin/permissions*') }}" title="Permissions">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span class="label">Permissions</span>
                    </a>
                    @endcan
                    @can('view-department')
                    <a href="{{ url('/admin/departments') }}" class="nav-link {{ $is('admin/departments*') }}" title="Department">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="1"/><path d="M9 22v-4h6v4M9 6h.01M15 6h.01M9 10h.01M15 10h.01M9 14h.01M15 14h.01"/></svg>
                        <span class="label">Department</span>
                    </a>
                    @endcan
                    @can('view-company')
                    <a href="{{ url('/admin/companies') }}" class="nav-link {{ $is('admin/companies*') }}" title="Company">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4M9 9h.01M9 13h.01M9 17h.01"/></svg>
                        <span class="label">Company</span>
                    </a>
                    @endcan
                </div>
            </div>
            @endcanany

            <div class="nav-group" :class="{ open: settingsOpen }">
                <button type="button" class="nav-link" :class="{ active: {{ $onSettings ? 'true' : 'false' }} && collapsed }" @click="openGroup('settingsOpen')" title="Settings">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span class="label">Settings</span>
                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <div class="nav-sub" x-show="settingsOpen">
                    <a href="{{ url('/settings/appearance') }}" class="nav-link {{ $is('settings/appearance*') }}" title="Appearance">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r="2.5"/><circle cx="19" cy="13" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="10" cy="18" r="2.5"/><path d="M12 2a10 10 0 1 0 0 20c1 0 1.5-.8 1.5-1.6 0-1.2-1-1.9-1-3 0-.8.7-1.4 1.5-1.4H16a4 4 0 0 0 4-4c0-4.4-3.6-8-8-8z" opacity=".35"/></svg>
                        <span class="label">Appearance</span>
                    </a>
                    @can('view-module')
                    <a href="{{ url('/settings/data-views') }}" class="nav-link {{ $is('settings/data-views*') }}" title="Data Views">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                        <span class="label">Data Views</span>
                    </a>
                    @endcan
                </div>
            </div>
        </nav>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="menu-toggle" @click="toggleSidebar()" aria-label="Toggle menu">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
            </button>
            <div>
                <div class="page-title">{{ $title ?? $crumb->last() }}</div>
                <div class="breadcrumb">{{ $crumb->implode(' / ') }}</div>
            </div>

            <div class="ml-auto flex items-center gap-3">
                <span class="clock" x-data="{ now: '' }" x-init="now = new Date().toLocaleString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' }); setInterval(() => now = new Date().toLocaleString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' }), 30000)" x-text="now"></span>

                <button class="icon-btn" title="Notifications">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </button>

                {{-- User menu --}}
                <div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
                    <button class="icon-btn" @click="open = !open" title="Account">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </button>
                    <div class="dropdown-menu" x-show="open" x-cloak x-transition>
                        <div class="dropdown-item" style="cursor:default;">
                            <strong>{{ auth()->user()->username ?? '' }}</strong>
                        </div>
                        <div class="dropdown-sep"></div>
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                                Logout
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">
            {{ $slot }}
        </main>
    </div>
</div>

@livewireScripts
<script src="{{ asset('js/settings.js') }}"></script>
</body>
</html>
