<?php

/*
| Registry of gated pages — the unit of access control. A permission grants
| access to a set of these pages (permission_page pivot); a page is reachable
| if any of the user's permissions include it. `gds:sync-pages` upserts these
| into the `pages` table (preserving nothing but pruning pages dropped here).
|
| `key`    stable identifier (also used by route middleware `page:{key}`)
| `label`  display name in the permission picker / nav
| `module` display grouping in the permission form
| `route`  the named route this page lives at (for reference / nav)
|
| Add a page here when its route is built; keep keys aligned with route names.
*/

return [
    'pages' => [
        // Admin
        ['key' => 'admin.users',       'label' => 'Users',       'module' => 'Admin', 'route' => 'admin.users'],
        ['key' => 'admin.roles',       'label' => 'Roles',       'module' => 'Admin', 'route' => 'admin.roles'],
        ['key' => 'admin.permissions', 'label' => 'Permissions', 'module' => 'Admin', 'route' => 'admin.permissions'],
        ['key' => 'admin.departments', 'label' => 'Departments', 'module' => 'Admin', 'route' => 'admin.departments'],
        ['key' => 'admin.companies',   'label' => 'Companies',   'module' => 'Admin', 'route' => 'admin.companies'],

        // Settings
        ['key' => 'settings.data_views', 'label' => 'Data Views',     'module' => 'Settings', 'route' => 'settings.data-views'],
        ['key' => 'settings.shifts',     'label' => 'Shift Settings', 'module' => 'Settings', 'route' => 'settings.shifts'],

        // BIL — Raw Materials
        ['key' => 'bil.raw_materials.statistics',          'label' => 'Statistics',          'module' => 'Raw Materials', 'route' => 'bil.raw-materials.statistics'],
        ['key' => 'bil.raw_materials.products',            'label' => 'Products',            'module' => 'Raw Materials', 'route' => 'bil.raw-materials.products'],
        ['key' => 'bil.raw_materials.suppliers',           'label' => 'Suppliers',           'module' => 'Raw Materials', 'route' => 'bil.raw-materials.suppliers'],
        ['key' => 'bil.raw_materials.supplier_deliveries', 'label' => 'Supplier Deliveries', 'module' => 'Raw Materials', 'route' => 'bil.raw-materials.supplier-deliveries'],
        ['key' => 'bil.raw_materials.warehouse_entry',     'label' => 'Warehouse Entry',     'module' => 'Raw Materials', 'route' => 'bil.raw-materials.warehouse-entry'],
        ['key' => 'bil.raw_materials.warehouse_exit',      'label' => 'Warehouse Exit',      'module' => 'Raw Materials', 'route' => 'bil.raw-materials.warehouse-exit'],
        ['key' => 'bil.raw_materials.stock_transfer',      'label' => 'Stock Transfer',      'module' => 'Raw Materials', 'route' => 'bil.raw-materials.stock-transfer'],
        ['key' => 'bil.raw_materials.factory_entrance',    'label' => 'Factory Entrance',    'module' => 'Raw Materials', 'route' => 'bil.raw-materials.factory-entrance'],
        ['key' => 'bil.raw_materials.consumption',         'label' => 'Consumption',         'module' => 'Raw Materials', 'route' => 'bil.raw-materials.consumption'],
        ['key' => 'bil.raw_materials.factory_returns',     'label' => 'Factory Returns',     'module' => 'Raw Materials', 'route' => 'bil.raw-materials.factory-returns'],
        ['key' => 'bil.raw_materials.damaged_goods',       'label' => 'Damaged Goods',       'module' => 'Raw Materials', 'route' => 'bil.raw-materials.damaged-goods'],

        // BIL — Raw Materials Reports
        ['key' => 'bil.raw_materials.reports.supplier_deliveries', 'label' => 'Supplier Deliveries', 'module' => 'Raw Materials Reports', 'route' => 'bil.raw-materials.reports.supplier-deliveries'],
        ['key' => 'bil.raw_materials.reports.warehouse_entry',     'label' => 'Warehouse Entry',     'module' => 'Raw Materials Reports', 'route' => 'bil.raw-materials.reports.warehouse-entry'],
        ['key' => 'bil.raw_materials.reports.warehouse_exit',      'label' => 'Warehouse Exit',      'module' => 'Raw Materials Reports', 'route' => 'bil.raw-materials.reports.warehouse-exit'],
        ['key' => 'bil.raw_materials.reports.factory_entrance',    'label' => 'Factory Entrance',    'module' => 'Raw Materials Reports', 'route' => 'bil.raw-materials.reports.factory-entrance'],
        ['key' => 'bil.raw_materials.reports.consumption',         'label' => 'Consumption',         'module' => 'Raw Materials Reports', 'route' => 'bil.raw-materials.reports.consumption'],
        ['key' => 'bil.raw_materials.reports.warehouse_stock',     'label' => 'Warehouse Stock',     'module' => 'Raw Materials Reports', 'route' => 'bil.raw-materials.reports.warehouse-stock'],
        ['key' => 'bil.raw_materials.reports.factory_floor_stock', 'label' => 'Factory Floor Stock', 'module' => 'Raw Materials Reports', 'route' => 'bil.raw-materials.reports.factory-floor-stock'],
        ['key' => 'bil.raw_materials.reports.factory_returns',     'label' => 'Factory Returns',     'module' => 'Raw Materials Reports', 'route' => 'bil.raw-materials.reports.factory-returns'],
        ['key' => 'bil.raw_materials.reports.damaged_goods',       'label' => 'Damaged Goods',       'module' => 'Raw Materials Reports', 'route' => 'bil.raw-materials.reports.damaged-goods'],

        // BPL — Jumbo Rolls
        ['key' => 'bpl.jumbo_rolls.grades',            'label' => 'Grades',              'module' => 'BPL', 'route' => 'bpl.jumbo-rolls.grades'],
        ['key' => 'bpl.jumbo_rolls.products.hardroll', 'label' => 'Products (Hardroll)', 'module' => 'BPL', 'route' => 'bpl.jumbo-rolls.products.hardroll'],
        ['key' => 'bpl.jumbo_rolls.products.softroll', 'label' => 'Products (Softroll)', 'module' => 'BPL', 'route' => 'bpl.jumbo-rolls.products.softroll'],
    ],
];
