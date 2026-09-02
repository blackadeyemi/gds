@php
    // Cache-buster for the hand-maintained stylesheets: the file's own mtime,
    // so editing one invalidates it and nothing else. Falls back to the app
    // version if the file is missing rather than emitting "?v=".
    $cssVersion = function (string $file) {
        static $seen = [];

        return $seen[$file] ??= (is_file($p = public_path("css/$file")) ? filemtime($p) : '1');
    };

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
    $onBilJumboRolls = request()->is('bil/jumbo-rolls/*');
    $onBilJrReports = request()->is('bil/jumbo-rolls/reports/*');
    $onFinishedGoods = request()->is('bil/finished-goods/*');
    $onSales = request()->is('bil/sales/*');
    $onMachines = request()->is('bil/machines/*');
    $onFgReports = request()->is('bil/finished-goods/reports/*');
    $onMachineReports = request()->is('bil/machines/reports/*');
    $onBpl = request()->is('bpl/*');
    $onJumboRolls = request()->is('bpl/jumbo-rolls/*');
    $onBplSales = request()->is('bpl/jumbo-rolls/sales/*');

    // Global "View Entries" link: on any BIL entry page whose route
    // (bil.<module>.<slug>) has a matching report route
    // (bil.<module>.reports.<slug>), link to it. Module-agnostic, so every
    // entry page — Raw Materials, Finished Goods, … — gets it with no per-page
    // markup. The report's page key is the report route with hyphens→underscores.
    $viewEntriesUrl = null;
    $viewEntriesPage = null;
    $routeName = request()->route()?->getName();
    if ($routeName && \Illuminate\Support\Str::startsWith($routeName, 'bil.') && ! \Illuminate\Support\Str::contains($routeName, '.reports.')) {
        $reportRoute = \Illuminate\Support\Str::beforeLast($routeName, '.') . '.reports.' . \Illuminate\Support\Str::afterLast($routeName, '.');
        if (\Illuminate\Support\Facades\Route::has($reportRoute)) {
            $viewEntriesUrl = route($reportRoute);
            $viewEntriesPage = str_replace('-', '_', $reportRoute);
        }
    }
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
    {{-- Stamped with the file's own mtime. There is no build step, so a CSS
         change would otherwise sit behind the browser cache until every user
         happened to hard-refresh — and "reload with Ctrl+F5" is not a deploy
         step anyone should have to remember. flatpickr's is vendor and never
         changes, so it is left plain and stays cached. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ $cssVersion('app.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ $cssVersion('admin.css') }}" />
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
        bilJumboRollsOpen: {{ $onBilJumboRolls ? 'true' : 'false' }},
        bilJrReportsOpen: {{ $onBilJrReports ? 'true' : 'false' }},
        finishedGoodsOpen: {{ $onFinishedGoods ? 'true' : 'false' }},
        fgReportsOpen: {{ $onFgReports ? 'true' : 'false' }},
        salesOpen: {{ $onSales ? 'true' : 'false' }},
        machinesOpen: {{ $onMachines ? 'true' : 'false' }},
        machineReportsOpen: {{ $onMachineReports ? 'true' : 'false' }},
        bplOpen: {{ $onBpl ? 'true' : 'false' }},
        jumboRollsOpen: {{ $onJumboRolls ? 'true' : 'false' }},
        bplSalesOpen: {{ $onBplSales ? 'true' : 'false' }},
        toggleSidebar() {
            if (window.innerWidth <= 900) { this.mobileOpen = !this.mobileOpen; return; }
            this.collapsed = !this.collapsed;
            localStorage.setItem('gds_sidebar_collapsed', JSON.stringify(this.collapsed));
            if (this.collapsed) { this.adminOpen = false; this.settingsOpen = false; this.bilOpen = false; this.rawMaterialsOpen = false; this.rmReportsOpen = false; this.bilJumboRollsOpen = false; this.bilJrReportsOpen = false; this.finishedGoodsOpen = false; this.fgReportsOpen = false; this.salesOpen = false; this.machinesOpen = false; this.machineReportsOpen = false; this.bplOpen = false; this.jumboRollsOpen = false; this.bplSalesOpen = false; }
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

            @canPrefix('bil.')
            <div class="nav-group" :class="{ open: bilOpen }">
                <button type="button" class="nav-link" :class="{ active: {{ $onBil ? 'true' : 'false' }} && collapsed }" @click="openGroup('bilOpen')" title="BIL">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M6 21V8l6-4 6 4v13M10 21v-5h4v5"/><path d="M9 11h.01M15 11h.01"/></svg>
                    <span class="label">BIL</span>
                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <div class="nav-sub" x-show="bilOpen">
                    @canPrefix('bil.raw_materials.')
                    <div class="nav-group" :class="{ open: rawMaterialsOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onRawMaterials ? 'true' : 'false' }} && collapsed }" @click="openGroup('rawMaterialsOpen')" title="Raw Materials">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>
                            <span class="label">Raw Materials</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="rawMaterialsOpen">
                            @canPage('bil.raw_materials.statistics')
                            <a href="{{ route('bil.raw-materials.statistics') }}" class="nav-link {{ $is('bil/raw-materials/statistics*') }}" title="Statistics">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/></svg>
                                <span class="label">Statistics</span>
                            </a>
                            @endcanPage
                            @canPage('bil.raw_materials.products')
                            <a href="{{ route('bil.raw-materials.products') }}" class="nav-link {{ $is('bil/raw-materials/products*') }}" title="Products">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7l-8-4-8 4 8 4 8-4zM4 7v10l8 4 8-4V7M12 11v10"/></svg>
                                <span class="label">Products</span>
                            </a>
                            @endcanPage
                            @canPage('bil.raw_materials.suppliers')
                            <a href="{{ route('bil.raw-materials.suppliers') }}" class="nav-link {{ $is('bil/raw-materials/suppliers*') }}" title="Suppliers">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <span class="label">Suppliers</span>
                            </a>
                            @endcanPage
                            @canPage('bil.raw_materials.supplier_deliveries')
                            <a href="{{ route('bil.raw-materials.supplier-deliveries') }}" class="nav-link {{ $is('bil/raw-materials/supplier-deliveries*') }}" title="Supplier Deliveries">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7V8zM5.5 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM18.5 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>
                                <span class="label">Supplier Deliveries</span>
                            </a>
                            @endcanPage
                            @canPage('bil.raw_materials.warehouse_entry')
                            <a href="{{ route('bil.raw-materials.warehouse-entry') }}" class="nav-link {{ $is('bil/raw-materials/warehouse-entry*') }}" title="Warehouse Entry">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></svg>
                                <span class="label">Warehouse Entry</span>
                            </a>
                            @endcanPage
                            @canPage('bil.raw_materials.warehouse_exit')
                            <a href="{{ route('bil.raw-materials.warehouse-exit') }}" class="nav-link {{ $is('bil/raw-materials/warehouse-exit*') }}" title="Warehouse Exit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3M7 8l5-5 5 5M4 21h16"/></svg>
                                <span class="label">Warehouse Exit</span>
                            </a>
                            @endcanPage
                            @canPage('bil.raw_materials.stock_transfer')
                            <a href="{{ route('bil.raw-materials.stock-transfer') }}" class="nav-link {{ $is('bil/raw-materials/stock-transfer*') }}" title="Stock Transfer">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h13l-3-3M20 17H7l3 3"/></svg>
                                <span class="label">Stock Transfer</span>
                            </a>
                            @endcanPage
                            @canPage('bil.raw_materials.factory_entrance')
                            <a href="{{ route('bil.raw-materials.factory-entrance') }}" class="nav-link {{ $is('bil/raw-materials/factory-entrance*') }}" title="Factory Entrance">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M4 21V10l8-6 8 6v11M9 21v-6h6v6"/></svg>
                                <span class="label">Factory Entrance</span>
                            </a>
                            @endcanPage
                            @canPage('bil.raw_materials.consumption')
                            <a href="{{ route('bil.raw-materials.consumption') }}" class="nav-link {{ $is('bil/raw-materials/consumption*') }}" title="Consumption">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12M6 2v6l4 4-4 4v6M18 2v6l-4 4 4 4v6M6 22h12"/></svg>
                                <span class="label">Consumption</span>
                            </a>
                            @endcanPage
                            @canPage('bil.raw_materials.factory_returns')
                            <a href="{{ route('bil.raw-materials.factory-returns') }}" class="nav-link {{ $is('bil/raw-materials/factory-returns*') }}" title="Factory Returns">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6M3 13a9 9 0 1 0 3-7.7L3 8"/></svg>
                                <span class="label">Factory Returns</span>
                            </a>
                            @endcanPage
                            @canPage('bil.raw_materials.damaged_goods')
                            <a href="{{ route('bil.raw-materials.damaged-goods') }}" class="nav-link {{ $is('bil/raw-materials/damaged-goods*') }}" title="Damaged Goods">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                                <span class="label">Damaged Goods</span>
                            </a>
                            @endcanPage

                            @canPrefix('bil.raw_materials.reports.')
                            <div class="nav-group" :class="{ open: rmReportsOpen }">
                                <button type="button" class="nav-link" :class="{ active: {{ $onRmReports ? 'true' : 'false' }} && collapsed }" @click="openGroup('rmReportsOpen')" title="Reports">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
                                    <span class="label">Reports</span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </button>
                                <div class="nav-sub" x-show="rmReportsOpen">
                                    @canPage('bil.raw_materials.reports.supplier_deliveries')<a href="{{ route('bil.raw-materials.reports.supplier-deliveries') }}" class="nav-link {{ $is('bil/raw-materials/reports/supplier-deliveries*') }}" title="Supplier Deliveries"><span class="label">Supplier Deliveries</span></a>@endcanPage
                                    @canPage('bil.raw_materials.reports.warehouse_entry')<a href="{{ route('bil.raw-materials.reports.warehouse-entry') }}" class="nav-link {{ $is('bil/raw-materials/reports/warehouse-entry*') }}" title="Warehouse Entry"><span class="label">Warehouse Entry</span></a>@endcanPage
                                    @canPage('bil.raw_materials.reports.warehouse_exit')<a href="{{ route('bil.raw-materials.reports.warehouse-exit') }}" class="nav-link {{ $is('bil/raw-materials/reports/warehouse-exit*') }}" title="Warehouse Exit"><span class="label">Warehouse Exit</span></a>@endcanPage
                                    @canPage('bil.raw_materials.reports.factory_entrance')<a href="{{ route('bil.raw-materials.reports.factory-entrance') }}" class="nav-link {{ $is('bil/raw-materials/reports/factory-entrance*') }}" title="Factory Entrance"><span class="label">Factory Entrance</span></a>@endcanPage
                                    @canPage('bil.raw_materials.reports.consumption')<a href="{{ route('bil.raw-materials.reports.consumption') }}" class="nav-link {{ $is('bil/raw-materials/reports/consumption*') }}" title="Consumption"><span class="label">Consumption</span></a>@endcanPage
                                    @canPage('bil.raw_materials.reports.warehouse_stock')<a href="{{ route('bil.raw-materials.reports.warehouse-stock') }}" class="nav-link {{ $is('bil/raw-materials/reports/warehouse-stock*') }}" title="Warehouse Stock"><span class="label">Warehouse Stock</span></a>@endcanPage
                                    @canPage('bil.raw_materials.reports.factory_floor_stock')<a href="{{ route('bil.raw-materials.reports.factory-floor-stock') }}" class="nav-link {{ $is('bil/raw-materials/reports/factory-floor-stock*') }}" title="Factory Floor Stock"><span class="label">Factory Floor Stock</span></a>@endcanPage
                                    @canPage('bil.raw_materials.reports.factory_returns')<a href="{{ route('bil.raw-materials.reports.factory-returns') }}" class="nav-link {{ $is('bil/raw-materials/reports/factory-returns*') }}" title="Factory Returns"><span class="label">Factory Returns</span></a>@endcanPage
                                    @canPage('bil.raw_materials.reports.damaged_goods')<a href="{{ route('bil.raw-materials.reports.damaged-goods') }}" class="nav-link {{ $is('bil/raw-materials/reports/damaged-goods*') }}" title="Damaged Goods"><span class="label">Damaged Goods</span></a>@endcanPage
                                </div>
                            </div>
                            @endcanPrefix
                        </div>
                    </div>
                    @endcanPrefix

                    @canPrefix('bil.jumbo_rolls.')
                    <div class="nav-group" :class="{ open: bilJumboRollsOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onBilJumboRolls ? 'true' : 'false' }} && collapsed }" @click="openGroup('bilJumboRollsOpen')" title="Jumbo Rolls">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="6" rx="7" ry="3"/><ellipse cx="12" cy="6" rx="2.2" ry="1"/><path d="M5 6v8a7 3 0 0 0 14 0V6"/><path d="M9.5 15.5v4l1.25-1 1.25 1 1.25-1 1.25 1v-4"/></svg>
                            <span class="label">Jumbo Rolls</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="bilJumboRollsOpen">
                            @canPage('bil.jumbo_rolls.statistics')
                            <a href="{{ route('bil.jumbo-rolls.statistics') }}" class="nav-link {{ $is('bil/jumbo-rolls/statistics*') }}" title="Statistics">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
                                <span class="label">Statistics</span>
                            </a>
                            @endcanPage
                            @canPage('bil.jumbo_rolls.factory_entrance')
                            <a href="{{ route('bil.jumbo-rolls.factory-entrance') }}" class="nav-link {{ $is('bil/jumbo-rolls/factory-entrance*') }}" title="Factory Entrance">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M4 21V10l8-6 8 6v11M9 21v-6h6v6"/></svg>
                                <span class="label">Factory Entrance</span>
                            </a>
                            @endcanPage
                            @canPage('bil.jumbo_rolls.consumption')
                            <a href="{{ route('bil.jumbo-rolls.consumption') }}" class="nav-link {{ $is('bil/jumbo-rolls/consumption*') }}" title="Consumption">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12M6 2v6l4 4-4 4v6M18 2v6l-4 4 4 4v6M6 22h12"/></svg>
                                <span class="label">Consumption</span>
                            </a>
                            @endcanPage
                            @canPage('bil.jumbo_rolls.returns')
                            <a href="{{ route('bil.jumbo-rolls.returns') }}" class="nav-link {{ $is('bil/jumbo-rolls/returns*') }}" title="Returns">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6M3 13a9 9 0 1 0 3-7.7L3 8"/></svg>
                                <span class="label">Returns</span>
                            </a>
                            @endcanPage
                            @canPage('bil.jumbo_rolls.stock')
                            <a href="{{ route('bil.jumbo-rolls.stock') }}" class="nav-link {{ $is('bil/jumbo-rolls/stock*') }}" title="Stock">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V9l7-5 7 5v12"/><path d="M9 21v-5h6v5M9 12h6"/></svg>
                                <span class="label">Stock</span>
                            </a>
                            @endcanPage

                            @canPrefix('bil.jumbo_rolls.reports.')
                            <div class="nav-group" :class="{ open: bilJrReportsOpen }">
                                <button type="button" class="nav-link" :class="{ active: {{ $onBilJrReports ? 'true' : 'false' }} && collapsed }" @click="openGroup('bilJrReportsOpen')" title="Reports">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
                                    <span class="label">Reports</span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </button>
                                <div class="nav-sub" x-show="bilJrReportsOpen">
                                    @canPage('bil.jumbo_rolls.reports.factory_entrance')<a href="{{ route('bil.jumbo-rolls.reports.factory-entrance') }}" class="nav-link {{ $is('bil/jumbo-rolls/reports/factory-entrance*') }}" title="Factory Entrance"><span class="label">Factory Entrance</span></a>@endcanPage
                                    @canPage('bil.jumbo_rolls.reports.consumption')<a href="{{ route('bil.jumbo-rolls.reports.consumption') }}" class="nav-link {{ $is('bil/jumbo-rolls/reports/consumption*') }}" title="Consumption"><span class="label">Consumption</span></a>@endcanPage
                                    @canPage('bil.jumbo_rolls.reports.returns')<a href="{{ route('bil.jumbo-rolls.reports.returns') }}" class="nav-link {{ $is('bil/jumbo-rolls/reports/returns*') }}" title="Returns"><span class="label">Returns</span></a>@endcanPage
                                </div>
                            </div>
                            @endcanPrefix
                        </div>
                    </div>
                    @endcanPrefix

                    @canPrefix('bil.finished_goods.')
                    <div class="nav-group" :class="{ open: finishedGoodsOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onFinishedGoods ? 'true' : 'false' }} && collapsed }" @click="openGroup('finishedGoodsOpen')" title="Finished Goods">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="13" rx="1"/><path d="M3 8l2-5h14l2 5M12 8v13M8 12h2M14 12h2"/></svg>
                            <span class="label">Finished Goods</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="finishedGoodsOpen">
                            @canPage('bil.finished_goods.statistics')
                            <a href="{{ route('bil.finished-goods.statistics') }}" class="nav-link {{ $is('bil/finished-goods/statistics*') }}" title="Statistics">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/></svg>
                                <span class="label">Statistics</span>
                            </a>
                            @endcanPage
                            @canPage('bil.finished_goods.products')
                            <a href="{{ route('bil.finished-goods.products') }}" class="nav-link {{ $is('bil/finished-goods/products*') }}" title="Products">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7l-8-4-8 4 8 4 8-4zM4 7v10l8 4 8-4V7M12 11v10"/></svg>
                                <span class="label">Products</span>
                            </a>
                            @endcanPage
                            @canPage('bil.finished_goods.conversion_output')
                            <a href="{{ route('bil.finished-goods.conversion-output') }}" class="nav-link {{ $is('bil/finished-goods/conversion-output*') }}" title="Conversion Output">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="6" rx="1"/><rect x="6" y="14" width="5" height="6" rx="1"/><rect x="13" y="14" width="5" height="6" rx="1"/><path d="M12 10v4"/></svg>
                                <span class="label">Conversion Output</span>
                            </a>
                            @endcanPage
                            @canPage('bil.finished_goods.conversion_waste')
                            <a href="{{ route('bil.finished-goods.conversion-waste') }}" class="nav-link {{ $is('bil/finished-goods/conversion-waste*') }}" title="Conversion Waste">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>
                                <span class="label">Conversion Waste</span>
                            </a>
                            @endcanPage
                            @canPage('bil.finished_goods.stock_transfer')
                            <a href="{{ route('bil.finished-goods.stock-transfer') }}" class="nav-link {{ $is('bil/finished-goods/stock-transfer') }}" title="Stock Transfer">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8h13l-3-3M21 16H8l3 3"/></svg>
                                <span class="label">Stock Transfer</span>
                            </a>
                            @endcanPage
                            @canPage('bil.finished_goods.stock_transfer_receive')
                            <a href="{{ route('bil.finished-goods.stock-transfer.receive') }}" class="nav-link {{ $is('bil/finished-goods/stock-transfer/receive*') }}" title="Receive Transfer">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7"/><path d="M12 3v12m0 0l-4-4m4 4l4-4"/></svg>
                                <span class="label">Receive Transfer</span>
                            </a>
                            @endcanPage
                            @canPage('bil.finished_goods.factory_exit')
                            <a href="{{ route('bil.finished-goods.factory-exit') }}" class="nav-link {{ $is('bil/finished-goods/factory-exit*') }}" title="Factory Exit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
                                <span class="label">Factory Exit</span>
                            </a>
                            @endcanPage
                            @canPage('bil.finished_goods.warehouse_stock')
                            <a href="{{ route('bil.finished-goods.warehouse-stock') }}" class="nav-link {{ $is('bil/finished-goods/warehouse-stock*') }}" title="Warehouse Stock">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4M3 17l9 4 9-4"/></svg>
                                <span class="label">Warehouse Stock</span>
                            </a>
                            @endcanPage
                            @canPage('bil.finished_goods.warehouse_entrance')
                            <a href="{{ route('bil.finished-goods.warehouse-entrance') }}" class="nav-link {{ $is('bil/finished-goods/warehouse-entrance*') }}" title="Warehouse Entrance">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M8 17l-5-5 5-5M3 12h12"/></svg>
                                <span class="label">Warehouse Entrance</span>
                            </a>
                            @endcanPage
                            @canPrefix('bil.finished_goods.reports.')
                            <div class="nav-group" :class="{ open: fgReportsOpen }">
                                <button type="button" class="nav-link" :class="{ active: {{ $onFgReports ? 'true' : 'false' }} && collapsed }" @click="openGroup('fgReportsOpen')" title="Reports">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
                                    <span class="label">Reports</span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </button>
                                <div class="nav-sub" x-show="fgReportsOpen">
                                    @canPage('bil.finished_goods.reports.conversion_output')
                                    <a href="{{ route('bil.finished-goods.reports.conversion-output') }}" class="nav-link {{ $is('bil/finished-goods/reports/conversion-output*') }}" title="Conversion Output">
                                        <span class="label">Conversion Output</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bil.finished_goods.reports.conversion_waste')
                                    <a href="{{ route('bil.finished-goods.reports.conversion-waste') }}" class="nav-link {{ $is('bil/finished-goods/reports/conversion-waste*') }}" title="Conversion Waste">
                                        <span class="label">Conversion Waste</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bil.finished_goods.reports.stock_transfer')
                                    <a href="{{ route('bil.finished-goods.reports.stock-transfer') }}" class="nav-link {{ $is('bil/finished-goods/reports/stock-transfer*') }}" title="Stock Transfer">
                                        <span class="label">Stock Transfer</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bil.finished_goods.reports.factory_exit')
                                    <a href="{{ route('bil.finished-goods.reports.factory-exit') }}" class="nav-link {{ $is('bil/finished-goods/reports/factory-exit*') }}" title="Factory Exit">
                                        <span class="label">Factory Exit</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bil.finished_goods.reports.factory_floor_stock')
                                    <a href="{{ route('bil.finished-goods.reports.factory-floor-stock') }}" class="nav-link {{ $is('bil/finished-goods/reports/factory-floor-stock*') }}" title="Factory Floor Stock">
                                        <span class="label">Factory Floor Stock</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bil.finished_goods.reports.warehouse_entrance')
                                    <a href="{{ route('bil.finished-goods.reports.warehouse-entrance') }}" class="nav-link {{ $is('bil/finished-goods/reports/warehouse-entrance*') }}" title="Warehouse Entrance">
                                        <span class="label">Warehouse Entrance</span>
                                    </a>
                                    @endcanPage
                                </div>
                            </div>
                            @endcanPrefix
                        </div>
                    </div>
                    @endcanPrefix

                    @canPrefix('bil.sales.')
                    <div class="nav-group" :class="{ open: salesOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onSales ? 'true' : 'false' }} && collapsed }" @click="openGroup('salesOpen')" title="Sales">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
                            <span class="label">Sales</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="salesOpen">
                            @canPage('bil.sales.customers')
                            <a href="{{ route('bil.sales.customers') }}" class="nav-link {{ $is('bil/sales/customers*') }}" title="Customers">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <span class="label">Customers</span>
                            </a>
                            @endcanPage
                            @canPage('bil.sales.transporters')
                            <a href="{{ route('bil.sales.transporters') }}" class="nav-link {{ $is('bil/sales/transporters*') }}" title="Transporters">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                <span class="label">Transporters</span>
                            </a>
                            @endcanPage
                            @canPage('bil.sales.orders')
                            <a href="{{ route('bil.sales.orders') }}" class="nav-link {{ $is('bil/sales/orders*') }}" title="Orders">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h4"/></svg>
                                <span class="label">Orders</span>
                            </a>
                            @endcanPage
                            @canPage('bil.sales.loading')
                            <a href="{{ route('bil.sales.loading') }}" class="nav-link {{ $is('bil/sales/loading*') }}" title="Loading">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                <span class="label">Loading</span>
                            </a>
                            @endcanPage
                            @canPage('bil.sales.delivery')
                            <a href="{{ route('bil.sales.delivery') }}" class="nav-link {{ $is('bil/sales/delivery*') }}" title="Delivery">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h13v13H3z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="7.5" cy="19.5" r="2"/><circle cx="18.5" cy="19.5" r="2"/><path d="m7 9 2.5 2.5L14 7"/></svg>
                                <span class="label">Delivery</span>
                            </a>
                            @endcanPage
                        </div>
                    </div>
                    @endcanPrefix

                    @canPrefix('bil.machines.')
                    <div class="nav-group" :class="{ open: machinesOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onMachines ? 'true' : 'false' }} && collapsed }" @click="openGroup('machinesOpen')" title="Machines">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M19.1 4.9l-2.8 2.8M7.7 16.3l-2.8 2.8"/></svg>
                            <span class="label">Machines</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="machinesOpen">
                            @canPage('bil.machines.statistics')
                            <a href="{{ route('bil.machines.statistics') }}" class="nav-link {{ $is('bil/machines/statistics*') }}" title="Statistics">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/></svg>
                                <span class="label">Statistics</span>
                            </a>
                            @endcanPage
                            @canPage('bil.machines.lines')
                            <a href="{{ route('bil.machines.lines') }}" class="nav-link {{ $is('bil/machines/lines*') }}" title="Lines">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16M4 12h10M4 19h6"/><circle cx="19" cy="12" r="1.6"/><circle cx="15" cy="19" r="1.6"/></svg>
                                <span class="label">Lines</span>
                            </a>
                            @endcanPage
                            @canPage('bil.machines.projects')
                            <a href="{{ route('bil.machines.projects') }}" class="nav-link {{ $is('bil/machines/projects*') }}" title="Projects">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><path d="M6.5 10v5a2 2 0 0 0 2 2H14"/></svg>
                                <span class="label">Projects</span>
                            </a>
                            @endcanPage
                            @canPage('bil.machines.conversion_setup')
                            <a href="{{ route('bil.machines.conversion-setup') }}" class="nav-link {{ $is('bil/machines/conversion-setup*') }}" title="Conversion Setup">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11l-3-3M21 17H10l3 3"/><rect x="16" y="4" width="5" height="6" rx="1"/><rect x="3" y="14" width="5" height="6" rx="1"/></svg>
                                <span class="label">Conversion Setup</span>
                            </a>
                            @endcanPage
                            @canPage('bil.machines.services')
                            <a href="{{ route('bil.machines.services') }}" class="nav-link {{ $is('bil/machines/services*') }}" title="Services">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 0 5.4-5.4l-2.3 2.3-2-2 2.3-2.3z"/></svg>
                                <span class="label">Services</span>
                            </a>
                            @endcanPage
                            @canPrefix('bil.machines.reports.')
                            <div class="nav-group" :class="{ open: machineReportsOpen }">
                                <button type="button" class="nav-link" :class="{ active: {{ $onMachineReports ? 'true' : 'false' }} && collapsed }" @click="openGroup('machineReportsOpen')" title="Reports">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
                                    <span class="label">Reports</span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </button>
                                <div class="nav-sub" x-show="machineReportsOpen">
                                    @canPage('bil.machines.reports.services')
                                    <a href="{{ route('bil.machines.reports.services') }}" class="nav-link {{ $is('bil/machines/reports/services*') }}" title="Services">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 0 5.4-5.4l-2.3 2.3-2-2 2.3-2.3z"/></svg>
                                        <span class="label">Services</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bil.machines.reports.conversion_history')
                                    <a href="{{ route('bil.machines.reports.conversion-history') }}" class="nav-link {{ $is('bil/machines/reports/conversion-history*') }}" title="Conversion History">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>
                                        <span class="label">Conversion History</span>
                                    </a>
                                    @endcanPage
                                </div>
                            </div>
                            @endcanPrefix
                        </div>
                    </div>
                    @endcanPrefix
                </div>
            </div>
            @endcanPrefix

            @canPrefix('bpl.')
            <div class="nav-group" :class="{ open: bplOpen }">
                <button type="button" class="nav-link" :class="{ active: {{ $onBpl ? 'true' : 'false' }} && collapsed }" @click="openGroup('bplOpen')" title="BPL">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M6 21V8l6-4 6 4v13M10 21v-5h4v5"/><path d="M9 11h.01M15 11h.01"/></svg>
                    <span class="label">BPL</span>
                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <div class="nav-sub" x-show="bplOpen">
                    <div class="nav-group" :class="{ open: jumboRollsOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onJumboRolls ? 'true' : 'false' }} && collapsed }" @click="openGroup('jumboRollsOpen')" title="Jumbo Rolls">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="6" rx="7" ry="3"/><ellipse cx="12" cy="6" rx="2.2" ry="1"/><path d="M5 6v8a7 3 0 0 0 14 0V6"/><path d="M9.5 15.5v4l1.25-1 1.25 1 1.25-1 1.25 1v-4"/></svg>
                            <span class="label">Jumbo Rolls</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="jumboRollsOpen">
                            @canPage('bpl.jumbo_rolls.grades')
                            <a href="{{ route('bpl.jumbo-rolls.grades') }}" class="nav-link {{ $is('bpl/jumbo-rolls/grades*') }}" title="Grades">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                <span class="label">Grades</span>
                            </a>
                            @endcanPage
                            @canPage('bpl.jumbo_rolls.products.hardroll')
                            <a href="{{ route('bpl.jumbo-rolls.products.hardroll') }}" class="nav-link {{ $is('bpl/jumbo-rolls/products*') }}" title="Products">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7l-8-4-8 4 8 4 8-4zM4 7v10l8 4 8-4V7M12 11v10"/></svg>
                                <span class="label">Products</span>
                            </a>
                            @endcanPage

                            @canPrefix('bpl.jumbo_rolls.sales.')
                            <div class="nav-group" :class="{ open: bplSalesOpen }">
                                <button type="button" class="nav-link" :class="{ active: {{ $onBplSales ? 'true' : 'false' }} && collapsed }" @click="openGroup('bplSalesOpen')" title="Sales">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                    <span class="label">Sales</span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </button>
                                <div class="nav-sub" x-show="bplSalesOpen">
                                    @canPage('bpl.jumbo_rolls.sales.customers')
                                    <a href="{{ route('bpl.jumbo-rolls.sales.customers') }}" class="nav-link {{ $is('bpl/jumbo-rolls/sales/customers*') }}" title="Customers">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                        <span class="label">Customers</span>
                                    </a>
                                    @endcanPage
                                </div>
                            </div>
                            @endcanPrefix
                        </div>
                    </div>
                </div>
            </div>
            @endcanPrefix

            @canPrefix('admin.')
            <div class="nav-group" :class="{ open: adminOpen }">
                <button type="button" class="nav-link" :class="{ active: {{ $onAdmin ? 'true' : 'false' }} && collapsed }" @click="openGroup('adminOpen')" title="Admin">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l7 4v6c0 5-3.5 8-7 10-3.5-2-7-5-7-10V6l7-4z"/><circle cx="12" cy="10" r="2.2"/><path d="M8.5 16a3.5 3.5 0 0 1 7 0"/></svg>
                    <span class="label">Admin</span>
                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <div class="nav-sub" x-show="adminOpen">
                    @canPage('admin.users')
                    <a href="{{ url('/admin/users') }}" class="nav-link {{ $is('admin/users*') }}" title="Users">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span class="label">Users</span>
                    </a>
                    @endcanPage
                    @canPage('admin.roles')
                    <a href="{{ url('/admin/roles') }}" class="nav-link {{ $is('admin/roles*') }}" title="Role">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span class="label">Role</span>
                    </a>
                    @endcanPage
                    @canPage('admin.factories')
                    <a href="{{ url('/admin/factories') }}" class="nav-link {{ $is('admin/factories*') }}" title="Factories">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M3 21V10l5 3V10l5 3V10l5 3v8"/><path d="M18 13V5h3v16"/><path d="M7 17h.01M12 17h.01M17 17h.01"/></svg>
                        <span class="label">Factories</span>
                    </a>
                    @endcanPage
                    @canPage('admin.factory_gates')
                    <a href="{{ url('/admin/factory-gates') }}" class="nav-link {{ $is('admin/factory-gates*') }}" title="Factory Gates">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
                        <span class="label">Factory Gates</span>
                    </a>
                    @endcanPage
                    @canPage('admin.warehouses')
                    <a href="{{ url('/admin/warehouses') }}" class="nav-link {{ $is('admin/warehouses*') }}" title="Warehouses">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V9l9-5 9 5v12"/><path d="M3 21h18"/><rect x="9" y="13" width="6" height="8"/></svg>
                        <span class="label">Warehouses</span>
                    </a>
                    @endcanPage
                    @canPage('admin.warehouse_gates')
                    <a href="{{ url('/admin/warehouse-gates') }}" class="nav-link {{ $is('admin/warehouse-gates*') }}" title="Warehouse Gates">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M8 17l-5-5 5-5M3 12h12"/></svg>
                        <span class="label">Warehouse Gates</span>
                    </a>
                    @endcanPage
                    @canPage('admin.staff')
                    <a href="{{ url('/admin/staff') }}" class="nav-link {{ $is('admin/staff*') }}" title="Staff">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span class="label">Staff</span>
                    </a>
                    @endcanPage
                    @canPage('admin.divisions')
                    <a href="{{ url('/admin/divisions') }}" class="nav-link {{ $is('admin/divisions*') }}" title="Division">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="5" rx="1"/><rect x="2" y="16" width="6" height="5" rx="1"/><rect x="16" y="16" width="6" height="5" rx="1"/><path d="M12 7v4M5 16v-2h14v2"/></svg>
                        <span class="label">Division</span>
                    </a>
                    @endcanPage
                    @canPage('admin.departments')
                    <a href="{{ url('/admin/departments') }}" class="nav-link {{ $is('admin/departments*') }}" title="Department">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="1"/><path d="M9 22v-4h6v4M9 6h.01M15 6h.01M9 10h.01M15 10h.01M9 14h.01M15 14h.01"/></svg>
                        <span class="label">Department</span>
                    </a>
                    @endcanPage
                    @canPage('admin.companies')
                    <a href="{{ url('/admin/companies') }}" class="nav-link {{ $is('admin/companies*') }}" title="Company">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4M9 9h.01M9 13h.01M9 17h.01"/></svg>
                        <span class="label">Company</span>
                    </a>
                    @endcanPage
                </div>
            </div>
            @endcanPrefix

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
                    @canPage('settings.pages')
                    <a href="{{ url('/settings/pages') }}" class="nav-link {{ $is('settings/pages*') }}" title="Pages">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 9v11"/></svg>
                        <span class="label">Pages</span>
                    </a>
                    @endcanPage
                    @canPage('settings.data_views')
                    <a href="{{ url('/settings/data-views') }}" class="nav-link {{ $is('settings/data-views*') }}" title="Data Views">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                        <span class="label">Data Views</span>
                    </a>
                    @endcanPage
                    @canPage('settings.service_types')
                    <a href="{{ url('/settings/service-types') }}" class="nav-link {{ $is('settings/service-types*') }}" title="Service Types">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/><circle cx="18" cy="18" r="2.5"/></svg>
                        <span class="label">Service Types</span>
                    </a>
                    @endcanPage
                    @canPage('settings.shifts')
                    <a href="{{ url('/settings/shifts') }}" class="nav-link {{ $is('settings/shifts*') }}" title="Shift Settings">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        <span class="label">Shift Settings</span>
                    </a>
                    @endcanPage
                    @canPage('settings.waste')
                    <a href="{{ url('/settings/waste') }}" class="nav-link {{ $is('settings/waste*') }}" title="Waste Settings">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>
                        <span class="label">Waste Settings</span>
                    </a>
                    @endcanPage
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
                @if ($viewEntriesUrl)
                    @canPage($viewEntriesPage)
                        <a href="{{ $viewEntriesUrl }}" class="btn btn-ghost btn-sm" title="View the entries recorded on this page">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
                            View Entries
                        </a>
                    @endcanPage
                @endif

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
<script src="{{ asset('js/searchable-select.js') }}"></script>
<script src="{{ asset('js/settings.js') }}"></script>
@stack('scripts')
</body>
</html>
