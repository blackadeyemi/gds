{{-- BHN (Abuja) sidebar section. Mirrors the BIL block in layouts/admin.blade.php
     — the same screens, with bhn.* routes/keys and bhn* Alpine state (the Bil
     module is reused on the bhn connection). Keep in sync when the BIL nav
     changes: it is the BIL section with bil.->bhn. / bil/->bhn/ and the Alpine
     *Open vars prefixed bhn. The $onBhn* active-state vars + bhn* x-data state
     live in layouts/admin.blade.php. --}}
            @canPrefix('bhn.')
            <div class="nav-group" :class="{ open: bhnOpen }">
                <button type="button" class="nav-link" :class="{ active: {{ $onBhn ? 'true' : 'false' }} && collapsed }" @click="openGroup('bhnOpen')" title="BHN (Abuja)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M6 21V8l6-4 6 4v13M10 21v-5h4v5"/><path d="M9 11h.01M15 11h.01"/></svg>
                    <span class="label">BHN (Abuja)</span>
                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <div class="nav-sub" x-show="bhnOpen">
                    @canPrefix('bhn.raw_materials.')
                    <div class="nav-group" :class="{ open: bhnRawMaterialsOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onBhnRawMaterials ? 'true' : 'false' }} && collapsed }" @click="openGroup('bhnRawMaterialsOpen')" title="Raw Materials">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>
                            <span class="label">Raw Materials</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="bhnRawMaterialsOpen">
                            @canPage('bhn.raw_materials.statistics')
                            <a href="{{ route('bhn.raw-materials.statistics') }}" class="nav-link {{ $is('bhn/raw-materials/statistics*') }}" title="Statistics">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/></svg>
                                <span class="label">Statistics</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.raw_materials.products')
                            <a href="{{ route('bhn.raw-materials.products') }}" class="nav-link {{ $is('bhn/raw-materials/products*') }}" title="Products">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7l-8-4-8 4 8 4 8-4zM4 7v10l8 4 8-4V7M12 11v10"/></svg>
                                <span class="label">Products</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.raw_materials.suppliers')
                            <a href="{{ route('bhn.raw-materials.suppliers') }}" class="nav-link {{ $is('bhn/raw-materials/suppliers*') }}" title="Suppliers">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <span class="label">Suppliers</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.raw_materials.supplier_deliveries')
                            <a href="{{ route('bhn.raw-materials.supplier-deliveries') }}" class="nav-link {{ $is('bhn/raw-materials/supplier-deliveries*') }}" title="Supplier Deliveries">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7V8zM5.5 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM18.5 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>
                                <span class="label">Supplier Deliveries</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.raw_materials.warehouse_entry')
                            <a href="{{ route('bhn.raw-materials.warehouse-entry') }}" class="nav-link {{ $is('bhn/raw-materials/warehouse-entry*') }}" title="Warehouse Entry">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></svg>
                                <span class="label">Warehouse Entry</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.raw_materials.warehouse_exit')
                            <a href="{{ route('bhn.raw-materials.warehouse-exit') }}" class="nav-link {{ $is('bhn/raw-materials/warehouse-exit*') }}" title="Warehouse Exit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3M7 8l5-5 5 5M4 21h16"/></svg>
                                <span class="label">Warehouse Exit</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.raw_materials.stock_transfer')
                            <a href="{{ route('bhn.raw-materials.stock-transfer') }}" class="nav-link {{ $is('bhn/raw-materials/stock-transfer*') }}" title="Stock Transfer">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h13l-3-3M20 17H7l3 3"/></svg>
                                <span class="label">Stock Transfer</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.raw_materials.factory_entrance')
                            <a href="{{ route('bhn.raw-materials.factory-entrance') }}" class="nav-link {{ $is('bhn/raw-materials/factory-entrance*') }}" title="Factory Entrance">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M4 21V10l8-6 8 6v11M9 21v-6h6v6"/></svg>
                                <span class="label">Factory Entrance</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.raw_materials.consumption')
                            <a href="{{ route('bhn.raw-materials.consumption') }}" class="nav-link {{ $is('bhn/raw-materials/consumption*') }}" title="Consumption">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12M6 2v6l4 4-4 4v6M18 2v6l-4 4 4 4v6M6 22h12"/></svg>
                                <span class="label">Consumption</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.raw_materials.factory_returns')
                            <a href="{{ route('bhn.raw-materials.factory-returns') }}" class="nav-link {{ $is('bhn/raw-materials/factory-returns*') }}" title="Factory Returns">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6M3 13a9 9 0 1 0 3-7.7L3 8"/></svg>
                                <span class="label">Factory Returns</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.raw_materials.damaged_goods')
                            <a href="{{ route('bhn.raw-materials.damaged-goods') }}" class="nav-link {{ $is('bhn/raw-materials/damaged-goods*') }}" title="Damaged Goods">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                                <span class="label">Damaged Goods</span>
                            </a>
                            @endcanPage

                            @canPrefix('bhn.raw_materials.reports.')
                            <div class="nav-group" :class="{ open: bhnRmReportsOpen }">
                                <button type="button" class="nav-link" :class="{ active: {{ $onBhnRmReports ? 'true' : 'false' }} && collapsed }" @click="openGroup('bhnRmReportsOpen')" title="Reports">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
                                    <span class="label">Reports</span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </button>
                                <div class="nav-sub" x-show="bhnRmReportsOpen">
                                    @canPage('bhn.raw_materials.reports.supplier_deliveries')<a href="{{ route('bhn.raw-materials.reports.supplier-deliveries') }}" class="nav-link {{ $is('bhn/raw-materials/reports/supplier-deliveries*') }}" title="Supplier Deliveries"><span class="label">Supplier Deliveries</span></a>@endcanPage
                                    @canPage('bhn.raw_materials.reports.warehouse_entry')<a href="{{ route('bhn.raw-materials.reports.warehouse-entry') }}" class="nav-link {{ $is('bhn/raw-materials/reports/warehouse-entry*') }}" title="Warehouse Entry"><span class="label">Warehouse Entry</span></a>@endcanPage
                                    @canPage('bhn.raw_materials.reports.warehouse_exit')<a href="{{ route('bhn.raw-materials.reports.warehouse-exit') }}" class="nav-link {{ $is('bhn/raw-materials/reports/warehouse-exit*') }}" title="Warehouse Exit"><span class="label">Warehouse Exit</span></a>@endcanPage
                                    @canPage('bhn.raw_materials.reports.factory_entrance')<a href="{{ route('bhn.raw-materials.reports.factory-entrance') }}" class="nav-link {{ $is('bhn/raw-materials/reports/factory-entrance*') }}" title="Factory Entrance"><span class="label">Factory Entrance</span></a>@endcanPage
                                    @canPage('bhn.raw_materials.reports.consumption')<a href="{{ route('bhn.raw-materials.reports.consumption') }}" class="nav-link {{ $is('bhn/raw-materials/reports/consumption*') }}" title="Consumption"><span class="label">Consumption</span></a>@endcanPage
                                    @canPage('bhn.raw_materials.reports.warehouse_stock')<a href="{{ route('bhn.raw-materials.reports.warehouse-stock') }}" class="nav-link {{ $is('bhn/raw-materials/reports/warehouse-stock*') }}" title="Warehouse Stock"><span class="label">Warehouse Stock</span></a>@endcanPage
                                    @canPage('bhn.raw_materials.reports.factory_floor_stock')<a href="{{ route('bhn.raw-materials.reports.factory-floor-stock') }}" class="nav-link {{ $is('bhn/raw-materials/reports/factory-floor-stock*') }}" title="Factory Floor Stock"><span class="label">Factory Floor Stock</span></a>@endcanPage
                                    @canPage('bhn.raw_materials.reports.factory_returns')<a href="{{ route('bhn.raw-materials.reports.factory-returns') }}" class="nav-link {{ $is('bhn/raw-materials/reports/factory-returns*') }}" title="Factory Returns"><span class="label">Factory Returns</span></a>@endcanPage
                                    @canPage('bhn.raw_materials.reports.damaged_goods')<a href="{{ route('bhn.raw-materials.reports.damaged-goods') }}" class="nav-link {{ $is('bhn/raw-materials/reports/damaged-goods*') }}" title="Damaged Goods"><span class="label">Damaged Goods</span></a>@endcanPage
                                </div>
                            </div>
                            @endcanPrefix
                        </div>
                    </div>
                    @endcanPrefix

                    @canPrefix('bhn.jumbo_rolls.')
                    <div class="nav-group" :class="{ open: bhnJumboRollsOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onBhnJumboRolls ? 'true' : 'false' }} && collapsed }" @click="openGroup('bhnJumboRollsOpen')" title="Jumbo Rolls">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="6" rx="7" ry="3"/><ellipse cx="12" cy="6" rx="2.2" ry="1"/><path d="M5 6v8a7 3 0 0 0 14 0V6"/><path d="M9.5 15.5v4l1.25-1 1.25 1 1.25-1 1.25 1v-4"/></svg>
                            <span class="label">Jumbo Rolls</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="bhnJumboRollsOpen">
                            @canPage('bhn.jumbo_rolls.statistics')
                            <a href="{{ route('bhn.jumbo-rolls.statistics') }}" class="nav-link {{ $is('bhn/jumbo-rolls/statistics*') }}" title="Statistics">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
                                <span class="label">Statistics</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.jumbo_rolls.factory_entrance')
                            <a href="{{ route('bhn.jumbo-rolls.factory-entrance') }}" class="nav-link {{ $is('bhn/jumbo-rolls/factory-entrance*') }}" title="Factory Entrance">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M4 21V10l8-6 8 6v11M9 21v-6h6v6"/></svg>
                                <span class="label">Factory Entrance</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.jumbo_rolls.consumption')
                            <a href="{{ route('bhn.jumbo-rolls.consumption') }}" class="nav-link {{ $is('bhn/jumbo-rolls/consumption*') }}" title="Consumption">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12M6 2v6l4 4-4 4v6M18 2v6l-4 4 4 4v6M6 22h12"/></svg>
                                <span class="label">Consumption</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.jumbo_rolls.returns')
                            <a href="{{ route('bhn.jumbo-rolls.returns') }}" class="nav-link {{ $is('bhn/jumbo-rolls/returns*') }}" title="Returns">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6M3 13a9 9 0 1 0 3-7.7L3 8"/></svg>
                                <span class="label">Returns</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.jumbo_rolls.stock')
                            <a href="{{ route('bhn.jumbo-rolls.stock') }}" class="nav-link {{ $is('bhn/jumbo-rolls/stock*') }}" title="Stock">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V9l7-5 7 5v12"/><path d="M9 21v-5h6v5M9 12h6"/></svg>
                                <span class="label">Stock</span>
                            </a>
                            @endcanPage

                            @canPrefix('bhn.jumbo_rolls.reports.')
                            <div class="nav-group" :class="{ open: bhnJrReportsOpen }">
                                <button type="button" class="nav-link" :class="{ active: {{ $onBhnJrReports ? 'true' : 'false' }} && collapsed }" @click="openGroup('bhnJrReportsOpen')" title="Reports">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
                                    <span class="label">Reports</span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </button>
                                <div class="nav-sub" x-show="bhnJrReportsOpen">
                                    @canPage('bhn.jumbo_rolls.reports.factory_entrance')<a href="{{ route('bhn.jumbo-rolls.reports.factory-entrance') }}" class="nav-link {{ $is('bhn/jumbo-rolls/reports/factory-entrance*') }}" title="Factory Entrance"><span class="label">Factory Entrance</span></a>@endcanPage
                                    @canPage('bhn.jumbo_rolls.reports.consumption')<a href="{{ route('bhn.jumbo-rolls.reports.consumption') }}" class="nav-link {{ $is('bhn/jumbo-rolls/reports/consumption*') }}" title="Consumption"><span class="label">Consumption</span></a>@endcanPage
                                    @canPage('bhn.jumbo_rolls.reports.returns')<a href="{{ route('bhn.jumbo-rolls.reports.returns') }}" class="nav-link {{ $is('bhn/jumbo-rolls/reports/returns*') }}" title="Returns"><span class="label">Returns</span></a>@endcanPage
                                </div>
                            </div>
                            @endcanPrefix
                        </div>
                    </div>
                    @endcanPrefix

                    @canPrefix('bhn.finished_goods.')
                    <div class="nav-group" :class="{ open: bhnFinishedGoodsOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onBhnFinishedGoods ? 'true' : 'false' }} && collapsed }" @click="openGroup('bhnFinishedGoodsOpen')" title="Finished Goods">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="13" rx="1"/><path d="M3 8l2-5h14l2 5M12 8v13M8 12h2M14 12h2"/></svg>
                            <span class="label">Finished Goods</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="bhnFinishedGoodsOpen">
                            @canPage('bhn.finished_goods.statistics')
                            <a href="{{ route('bhn.finished-goods.statistics') }}" class="nav-link {{ $is('bhn/finished-goods/statistics*') }}" title="Statistics">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/></svg>
                                <span class="label">Statistics</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.finished_goods.products')
                            <a href="{{ route('bhn.finished-goods.products') }}" class="nav-link {{ $is('bhn/finished-goods/products*') }}" title="Products">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7l-8-4-8 4 8 4 8-4zM4 7v10l8 4 8-4V7M12 11v10"/></svg>
                                <span class="label">Products</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.finished_goods.conversion_output')
                            <a href="{{ route('bhn.finished-goods.conversion-output') }}" class="nav-link {{ $is('bhn/finished-goods/conversion-output*') }}" title="Conversion Output">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="6" rx="1"/><rect x="6" y="14" width="5" height="6" rx="1"/><rect x="13" y="14" width="5" height="6" rx="1"/><path d="M12 10v4"/></svg>
                                <span class="label">Conversion Output</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.finished_goods.conversion_waste')
                            <a href="{{ route('bhn.finished-goods.conversion-waste') }}" class="nav-link {{ $is('bhn/finished-goods/conversion-waste*') }}" title="Conversion Waste">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>
                                <span class="label">Conversion Waste</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.finished_goods.stock_transfer')
                            <a href="{{ route('bhn.finished-goods.stock-transfer') }}" class="nav-link {{ $is('bhn/finished-goods/stock-transfer') }}" title="Stock Transfer">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8h13l-3-3M21 16H8l3 3"/></svg>
                                <span class="label">Stock Transfer</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.finished_goods.stock_transfer_receive')
                            <a href="{{ route('bhn.finished-goods.stock-transfer.receive') }}" class="nav-link {{ $is('bhn/finished-goods/stock-transfer/receive*') }}" title="Receive Transfer">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7"/><path d="M12 3v12m0 0l-4-4m4 4l4-4"/></svg>
                                <span class="label">Receive Transfer</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.finished_goods.factory_exit')
                            <a href="{{ route('bhn.finished-goods.factory-exit') }}" class="nav-link {{ $is('bhn/finished-goods/factory-exit*') }}" title="Factory Exit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
                                <span class="label">Factory Exit</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.finished_goods.warehouse_stock')
                            <a href="{{ route('bhn.finished-goods.warehouse-stock') }}" class="nav-link {{ $is('bhn/finished-goods/warehouse-stock*') }}" title="Warehouse Stock">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4M3 17l9 4 9-4"/></svg>
                                <span class="label">Warehouse Stock</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.finished_goods.warehouse_entrance')
                            <a href="{{ route('bhn.finished-goods.warehouse-entrance') }}" class="nav-link {{ $is('bhn/finished-goods/warehouse-entrance*') }}" title="Warehouse Entrance">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M8 17l-5-5 5-5M3 12h12"/></svg>
                                <span class="label">Warehouse Entrance</span>
                            </a>
                            @endcanPage
                            @canPrefix('bhn.finished_goods.reports.')
                            <div class="nav-group" :class="{ open: bhnFgReportsOpen }">
                                <button type="button" class="nav-link" :class="{ active: {{ $onBhnFgReports ? 'true' : 'false' }} && collapsed }" @click="openGroup('bhnFgReportsOpen')" title="Reports">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
                                    <span class="label">Reports</span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </button>
                                <div class="nav-sub" x-show="bhnFgReportsOpen">
                                    @canPage('bhn.finished_goods.reports.conversion_output')
                                    <a href="{{ route('bhn.finished-goods.reports.conversion-output') }}" class="nav-link {{ $is('bhn/finished-goods/reports/conversion-output*') }}" title="Conversion Output">
                                        <span class="label">Conversion Output</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.finished_goods.reports.conversion_waste')
                                    <a href="{{ route('bhn.finished-goods.reports.conversion-waste') }}" class="nav-link {{ $is('bhn/finished-goods/reports/conversion-waste*') }}" title="Conversion Waste">
                                        <span class="label">Conversion Waste</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.finished_goods.reports.stock_transfer')
                                    <a href="{{ route('bhn.finished-goods.reports.stock-transfer') }}" class="nav-link {{ $is('bhn/finished-goods/reports/stock-transfer*') }}" title="Stock Transfer">
                                        <span class="label">Stock Transfer</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.finished_goods.reports.factory_exit')
                                    <a href="{{ route('bhn.finished-goods.reports.factory-exit') }}" class="nav-link {{ $is('bhn/finished-goods/reports/factory-exit*') }}" title="Factory Exit">
                                        <span class="label">Factory Exit</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.finished_goods.reports.factory_floor_stock')
                                    <a href="{{ route('bhn.finished-goods.reports.factory-floor-stock') }}" class="nav-link {{ $is('bhn/finished-goods/reports/factory-floor-stock*') }}" title="Factory Floor Stock">
                                        <span class="label">Factory Floor Stock</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.finished_goods.reports.warehouse_entrance')
                                    <a href="{{ route('bhn.finished-goods.reports.warehouse-entrance') }}" class="nav-link {{ $is('bhn/finished-goods/reports/warehouse-entrance*') }}" title="Warehouse Entrance">
                                        <span class="label">Warehouse Entrance</span>
                                    </a>
                                    @endcanPage
                                </div>
                            </div>
                            @endcanPrefix
                        </div>
                    </div>
                    @endcanPrefix

                    @canPrefix('bhn.sales.')
                    <div class="nav-group" :class="{ open: bhnSalesOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onBhnSales ? 'true' : 'false' }} && collapsed }" @click="openGroup('bhnSalesOpen')" title="Sales">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
                            <span class="label">Sales</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="bhnSalesOpen">
                            @canPage('bhn.sales.statistics')
                            <a href="{{ route('bhn.sales.statistics') }}" class="nav-link {{ $is('bhn/sales/statistics*') }}" title="Statistics">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
                                <span class="label">Statistics</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.sales.customers')
                            <a href="{{ route('bhn.sales.customers') }}" class="nav-link {{ $is('bhn/sales/customers*') }}" title="Customers">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <span class="label">Customers</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.sales.transporters')
                            <a href="{{ route('bhn.sales.transporters') }}" class="nav-link {{ $is('bhn/sales/transporters*') }}" title="Transporters">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                <span class="label">Transporters</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.sales.orders')
                            <a href="{{ route('bhn.sales.orders') }}" class="nav-link {{ $is('bhn/sales/orders*') }}" title="Orders">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h4"/></svg>
                                <span class="label">Orders</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.sales.loading')
                            <a href="{{ route('bhn.sales.loading') }}" class="nav-link {{ $is('bhn/sales/loading*') }}" title="Loading">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                <span class="label">Loading</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.sales.delivery')
                            <a href="{{ route('bhn.sales.delivery') }}" class="nav-link {{ $is('bhn/sales/delivery*') }}" title="Delivery">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h13v13H3z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="7.5" cy="19.5" r="2"/><circle cx="18.5" cy="19.5" r="2"/><path d="m7 9 2.5 2.5L14 7"/></svg>
                                <span class="label">Delivery</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.sales.returns')
                            <a href="{{ route('bhn.sales.returns') }}" class="nav-link {{ $is('bhn/sales/returns*') }}" title="Returns">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M3.5 13a9 9 0 1 0 2.1-9.4L3 7"/></svg>
                                <span class="label">Returns</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.sales.waybill')
                            <a href="{{ route('bhn.sales.waybill') }}" class="nav-link {{ $is('bhn/sales/waybill*') }}" title="Waybill">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h3"/></svg>
                                <span class="label">Waybill</span>
                            </a>
                            @endcanPage
                            @canPrefix('bhn.sales.reports.')
                            <div class="nav-group" :class="{ open: bhnSalesReportsOpen }">
                                <button type="button" class="nav-link" :class="{ active: {{ $onBhnSalesReports ? 'true' : 'false' }} && collapsed }" @click="openGroup('bhnSalesReportsOpen')" title="Reports">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
                                    <span class="label">Reports</span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </button>
                                <div class="nav-sub" x-show="bhnSalesReportsOpen">
                                    @canPage('bhn.sales.reports.order_trail')
                                    <a href="{{ route('bhn.sales.reports.order-trail') }}" class="nav-link {{ $is('bhn/sales/reports/order-trail*') }}" title="Order Trail">
                                        <span class="label">Order Trail</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.sales.reports.orders')
                                    <a href="{{ route('bhn.sales.reports.orders') }}" class="nav-link {{ $is('bhn/sales/reports/orders*') }}" title="Orders">
                                        <span class="label">Orders</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.sales.reports.loading')
                                    <a href="{{ route('bhn.sales.reports.loading') }}" class="nav-link {{ $is('bhn/sales/reports/loading*') }}" title="Loading">
                                        <span class="label">Loading</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.sales.reports.delivery')
                                    <a href="{{ route('bhn.sales.reports.delivery') }}" class="nav-link {{ $is('bhn/sales/reports/delivery*') }}" title="Delivery">
                                        <span class="label">Delivery</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.sales.reports.returns')
                                    <a href="{{ route('bhn.sales.reports.returns') }}" class="nav-link {{ $is('bhn/sales/reports/returns*') }}" title="Returns">
                                        <span class="label">Returns</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.sales.reports.waybill')
                                    <a href="{{ route('bhn.sales.reports.waybill') }}" class="nav-link {{ $is('bhn/sales/reports/waybill*') }}" title="Waybill">
                                        <span class="label">Waybill</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.sales.reports.damaged_goods')
                                    <a href="{{ route('bhn.sales.reports.damaged-goods') }}" class="nav-link {{ $is('bhn/sales/reports/damaged-goods*') }}" title="Damaged Goods">
                                        <span class="label">Damaged Goods</span>
                                    </a>
                                    @endcanPage
                                </div>
                            </div>
                            @endcanPrefix
                        </div>
                    </div>
                    @endcanPrefix

                    @canPrefix('bhn.machines.')
                    <div class="nav-group" :class="{ open: bhnMachinesOpen }">
                        <button type="button" class="nav-link" :class="{ active: {{ $onBhnMachines ? 'true' : 'false' }} && collapsed }" @click="openGroup('bhnMachinesOpen')" title="Machines">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M19.1 4.9l-2.8 2.8M7.7 16.3l-2.8 2.8"/></svg>
                            <span class="label">Machines</span>
                            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="nav-sub" x-show="bhnMachinesOpen">
                            @canPage('bhn.machines.statistics')
                            <a href="{{ route('bhn.machines.statistics') }}" class="nav-link {{ $is('bhn/machines/statistics*') }}" title="Statistics">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/></svg>
                                <span class="label">Statistics</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.machines.lines')
                            <a href="{{ route('bhn.machines.lines') }}" class="nav-link {{ $is('bhn/machines/lines*') }}" title="Lines">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16M4 12h10M4 19h6"/><circle cx="19" cy="12" r="1.6"/><circle cx="15" cy="19" r="1.6"/></svg>
                                <span class="label">Lines</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.machines.projects')
                            <a href="{{ route('bhn.machines.projects') }}" class="nav-link {{ $is('bhn/machines/projects*') }}" title="Projects">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><path d="M6.5 10v5a2 2 0 0 0 2 2H14"/></svg>
                                <span class="label">Projects</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.machines.conversion_setup')
                            <a href="{{ route('bhn.machines.conversion-setup') }}" class="nav-link {{ $is('bhn/machines/conversion-setup*') }}" title="Conversion Setup">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11l-3-3M21 17H10l3 3"/><rect x="16" y="4" width="5" height="6" rx="1"/><rect x="3" y="14" width="5" height="6" rx="1"/></svg>
                                <span class="label">Conversion Setup</span>
                            </a>
                            @endcanPage
                            @canPage('bhn.machines.services')
                            <a href="{{ route('bhn.machines.services') }}" class="nav-link {{ $is('bhn/machines/services*') }}" title="Services">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 0 5.4-5.4l-2.3 2.3-2-2 2.3-2.3z"/></svg>
                                <span class="label">Services</span>
                            </a>
                            @endcanPage
                            @canPrefix('bhn.machines.reports.')
                            <div class="nav-group" :class="{ open: bhnMachineReportsOpen }">
                                <button type="button" class="nav-link" :class="{ active: {{ $onBhnMachineReports ? 'true' : 'false' }} && collapsed }" @click="openGroup('bhnMachineReportsOpen')" title="Reports">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
                                    <span class="label">Reports</span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                </button>
                                <div class="nav-sub" x-show="bhnMachineReportsOpen">
                                    @canPage('bhn.machines.reports.services')
                                    <a href="{{ route('bhn.machines.reports.services') }}" class="nav-link {{ $is('bhn/machines/reports/services*') }}" title="Services">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 0 5.4-5.4l-2.3 2.3-2-2 2.3-2.3z"/></svg>
                                        <span class="label">Services</span>
                                    </a>
                                    @endcanPage
                                    @canPage('bhn.machines.reports.conversion_history')
                                    <a href="{{ route('bhn.machines.reports.conversion-history') }}" class="nav-link {{ $is('bhn/machines/reports/conversion-history*') }}" title="Conversion History">
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