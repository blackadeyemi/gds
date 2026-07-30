@php
    // Breadcrumb from the current path (e.g. admin/roles -> "Admin / Roles")
    $segments = collect(explode('/', trim(request()->path(), '/')))->filter();
    $crumb = $segments->isEmpty() ? collect(['Dashboard']) : $segments->map(fn ($s) => \Illuminate\Support\Str::headline($s));
    $path = request()->path();
    $is = fn ($p) => request()->is($p) ? 'active' : '';
    $onAdmin = request()->is('admin/*');
    $onSettings = request()->is('settings/*');
    $onBil = request()->is('bil/*');
    $onRawMaterials = request()->is('bil/raw-materials/*');
    $onRmReports = request()->is('bil/raw-materials/reports/*');
    $onBpl = request()->is('bpl/*');
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
            function applyAppearance() {
                try {
                    var f = localStorage.getItem('gds_font') || 'small';
                    var mode = localStorage.getItem('gds_theme') || 'system';
                    var r = document.documentElement;
                    r.setAttribute('data-font', f);
                    r.setAttribute('data-theme-mode', mode);
                    var dark = mode === 'system' ? window.matchMedia('(prefers-color-scheme: dark)').matches : mode === 'dark';
                    r.setAttribute('data-theme', dark ? 'dark' : 'light');
                } catch (e) {}
            }
            applyAppearance();
            // Re-apply after Livewire SPA navigation (wire:navigate), which otherwise
            // reverts <html> to the layout's default data-font/data-theme.
            document.addEventListener('livewire:navigated', applyAppearance);
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/flatpickr.min.css') }}" />
    @livewireStyles
</head>
<body>
<div class="app"
     x-data="{
        collapsed: JSON.parse(localStorage.getItem('gds_sidebar_collapsed') ?? 'true'),
        mobileOpen: false,
        adminOpen: {{ $onAdmin ? 'true' : 'false' }},
        settingsOpen: {{ $onSettings ? 'true' : 'false' }},
        bilOpen: {{ $onBil ? 'true' : 'false' }},
        rawMaterialsOpen: {{ $onRawMaterials ? 'true' : 'false' }},
        rmReportsOpen: {{ $onRmReports ? 'true' : 'false' }},
        bplOpen: {{ $onBpl ? 'true' : 'false' }},
        toggleSidebar() {
            if (window.innerWidth <= 900) { this.mobileOpen = !this.mobileOpen; return; }
            this.collapsed = !this.collapsed;
            localStorage.setItem('gds_sidebar_collapsed', JSON.stringify(this.collapsed));
            if (this.collapsed) { this.adminOpen = false; this.settingsOpen = false; this.bilOpen = false; this.rawMaterialsOpen = false; this.rmReportsOpen = false; this.bplOpen = false; }
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

            @can('view-raw-materials')
            <div class="nav-group" :class="{ open: bilOpen }">
                <button type="button" class="nav-link" :class="{ active: {{ $onBil ? 'true' : 'false' }} && collapsed }" @click="openGroup('bilOpen')" title="BIL">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M6 21V8l6-4 6 4v13M10 21v-5h4v5"/><path d="M9 11h.01M15 11h.01"/></svg>
                    <span class="label">BIL</span>
                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <div class="nav-sub" x-show="bilOpen">
                    <div class="nav-group" :class="{ open: rawMaterialsOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onRawMaterials ? 'true' : 'false' }} && collapsed }" @click="openGroup('rawMaterialsOpen')" title="Raw Materials">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>
                            <span class="label">Raw Materials</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="rawMaterialsOpen">
                            <a href="{{ route('bil.raw-materials.statistics') }}" class="nav-link {{ $is('bil/raw-materials/statistics*') }}" title="Statistics">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/></svg>
                                <span class="label">Statistics</span>
                            </a>
                            <a href="{{ route('bil.raw-materials.products') }}" class="nav-link {{ $is('bil/raw-materials/products*') }}" title="Products">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7l-8-4-8 4 8 4 8-4zM4 7v10l8 4 8-4V7M12 11v10"/></svg>
                                <span class="label">Products</span>
                            </a>
                            <a href="{{ route('bil.raw-materials.suppliers') }}" class="nav-link {{ $is('bil/raw-materials/suppliers*') }}" title="Suppliers">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <span class="label">Suppliers</span>
                            </a>
                            <a href="{{ route('bil.raw-materials.supplier-deliveries') }}" class="nav-link {{ $is('bil/raw-materials/supplier-deliveries*') }}" title="Supplier Deliveries">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7V8zM5.5 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM18.5 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>
                                <span class="label">Supplier Deliveries</span>
                            </a>
                            <a href="{{ route('bil.raw-materials.warehouse-entry') }}" class="nav-link {{ $is('bil/raw-materials/warehouse-entry*') }}" title="Warehouse Entry">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></svg>
                                <span class="label">Warehouse Entry</span>
                            </a>
                            <a href="{{ route('bil.raw-materials.warehouse-exit') }}" class="nav-link {{ $is('bil/raw-materials/warehouse-exit*') }}" title="Warehouse Exit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3M7 8l5-5 5 5M4 21h16"/></svg>
                                <span class="label">Warehouse Exit</span>
                            </a>
                            <a href="{{ route('bil.raw-materials.stock-transfer') }}" class="nav-link {{ $is('bil/raw-materials/stock-transfer*') }}" title="Stock Transfer">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h13l-3-3M20 17H7l3 3"/></svg>
                                <span class="label">Stock Transfer</span>
                            </a>
                            <a href="{{ route('bil.raw-materials.factory-entrance') }}" class="nav-link {{ $is('bil/raw-materials/factory-entrance*') }}" title="Factory Entrance">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M4 21V10l8-6 8 6v11M9 21v-6h6v6"/></svg>
                                <span class="label">Factory Entrance</span>
                            </a>
                            <a href="{{ route('bil.raw-materials.consumption') }}" class="nav-link {{ $is('bil/raw-materials/consumption*') }}" title="Consumption">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12M6 2v6l4 4-4 4v6M18 2v6l-4 4 4 4v6M6 22h12"/></svg>
                                <span class="label">Consumption</span>
                            </a>
                            <a href="{{ route('bil.raw-materials.factory-returns') }}" class="nav-link {{ $is('bil/raw-materials/factory-returns*') }}" title="Factory Returns">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6M3 13a9 9 0 1 0 3-7.7L3 8"/></svg>
                                <span class="label">Factory Returns</span>
                            </a>
                            <a href="{{ route('bil.raw-materials.damaged-goods') }}" class="nav-link {{ $is('bil/raw-materials/damaged-goods*') }}" title="Damaged Goods">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                                <span class="label">Damaged Goods</span>
                            </a>

                            <div class="nav-group" :class="{ open: rmReportsOpen }">
                                <button type="button" class="nav-link" :class="{ active: {{ $onRmReports ? 'true' : 'false' }} && collapsed }" @click="openGroup('rmReportsOpen')" title="Reports">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
                                    <span class="label">Reports</span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </button>
                                <div class="nav-sub" x-show="rmReportsOpen">
                                    <a href="{{ route('bil.raw-materials.reports.supplier-deliveries') }}" class="nav-link {{ $is('bil/raw-materials/reports/supplier-deliveries*') }}" title="Supplier Deliveries"><span class="label">Supplier Deliveries</span></a>
                                    <a href="{{ route('bil.raw-materials.reports.warehouse-entry') }}" class="nav-link {{ $is('bil/raw-materials/reports/warehouse-entry*') }}" title="Warehouse Entry"><span class="label">Warehouse Entry</span></a>
                                    <a href="{{ route('bil.raw-materials.reports.warehouse-exit') }}" class="nav-link {{ $is('bil/raw-materials/reports/warehouse-exit*') }}" title="Warehouse Exit"><span class="label">Warehouse Exit</span></a>
                                    <a href="{{ route('bil.raw-materials.reports.factory-entrance') }}" class="nav-link {{ $is('bil/raw-materials/reports/factory-entrance*') }}" title="Factory Entrance"><span class="label">Factory Entrance</span></a>
                                    <a href="{{ route('bil.raw-materials.reports.consumption') }}" class="nav-link {{ $is('bil/raw-materials/reports/consumption*') }}" title="Consumption"><span class="label">Consumption</span></a>
                                    <a href="{{ route('bil.raw-materials.reports.warehouse-stock') }}" class="nav-link {{ $is('bil/raw-materials/reports/warehouse-stock*') }}" title="Warehouse Stock"><span class="label">Warehouse Stock</span></a>
                                    <a href="{{ route('bil.raw-materials.reports.factory-floor-stock') }}" class="nav-link {{ $is('bil/raw-materials/reports/factory-floor-stock*') }}" title="Factory Floor Stock"><span class="label">Factory Floor Stock</span></a>
                                    <a href="{{ route('bil.raw-materials.reports.factory-returns') }}" class="nav-link {{ $is('bil/raw-materials/reports/factory-returns*') }}" title="Factory Returns"><span class="label">Factory Returns</span></a>
                                    <a href="{{ route('bil.raw-materials.reports.damaged-goods') }}" class="nav-link {{ $is('bil/raw-materials/reports/damaged-goods*') }}" title="Damaged Goods"><span class="label">Damaged Goods</span></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endcan

            @can('view-bpl')
            <div class="nav-group" :class="{ open: bplOpen }">
                <button type="button" class="nav-link" :class="{ active: {{ $onBpl ? 'true' : 'false' }} && collapsed }" @click="openGroup('bplOpen')" title="BPL">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M6 21V8l6-4 6 4v13M10 21v-5h4v5"/><path d="M9 11h.01M15 11h.01"/></svg>
                    <span class="label">BPL</span>
                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <div class="nav-sub" x-show="bplOpen">
                    <a href="{{ route('bpl.grades') }}" class="nav-link {{ $is('bpl/grades*') }}" title="Grades">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                        <span class="label">Grades</span>
                    </a>
                    <a href="{{ route('bpl.products.hardroll') }}" class="nav-link {{ $is('bpl/products*') }}" title="Products">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7l-8-4-8 4 8 4 8-4zM4 7v10l8 4 8-4V7M12 11v10"/></svg>
                        <span class="label">Products</span>
                    </a>
                </div>
            </div>
            @endcan

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
                    @can('manage-shift-settings')
                    <a href="{{ url('/settings/shifts') }}" class="nav-link {{ $is('settings/shifts*') }}" title="Shift Settings">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        <span class="label">Shift Settings</span>
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
<script src="{{ asset('js/flatpickr.min.js') }}"></script>
<script src="{{ asset('js/datefield.js') }}"></script>
<script src="{{ asset('js/settings.js') }}"></script>
</body>
</html>
