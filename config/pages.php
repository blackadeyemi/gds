<?php

/*
| Registry of gated pages and the ABILITIES each supports. A page is the unit
| of access; its abilities are the actions on it. `gds:sync-pages` materializes
| every page+ability as a permission named "{key}:{ability}" and grants are made
| per role (page × ability matrix in the Role editor).
|
| 'view' = access (drives the `page:` route middleware and nav). Special
| abilities (backdate, approve, bypass-shift) live only on the pages that
| support them, so they never appear where they don't apply.
|
| Keep page keys aligned with route names ('-' -> '_').
*/

$crud = ['view', 'create', 'edit', 'delete', 'export'];
$access = ['view'];
// Entry forms: access IS the create action, so no separate create/edit/delete —
// just view plus the special abilities that page supports.
$entry = ['view', 'backdate'];
$report = ['view', 'edit', 'delete', 'export'];
// Read-only reports / dashboards: viewable and exportable, nothing to edit.
$snapshot = ['view', 'export'];

return [
    'pages' => [
        // Admin
        ['key' => 'admin.users',       'label' => 'Users',       'module' => 'Admin', 'route' => 'admin.users',       'abilities' => $crud],
        ['key' => 'admin.roles',       'label' => 'Roles',       'module' => 'Admin', 'route' => 'admin.roles',       'abilities' => $crud],
        ['key' => 'admin.departments', 'label' => 'Departments', 'module' => 'Admin', 'route' => 'admin.departments', 'abilities' => $crud],
        ['key' => 'admin.companies',   'label' => 'Companies',   'module' => 'Admin', 'route' => 'admin.companies',   'abilities' => $crud],

        // Settings
        ['key' => 'settings.pages',      'label' => 'Pages',          'module' => 'Settings', 'route' => 'settings.pages',      'abilities' => $access],
        ['key' => 'settings.data_views', 'label' => 'Data Views',     'module' => 'Settings', 'route' => 'settings.data-views', 'abilities' => $access],
        ['key' => 'settings.shifts',     'label' => 'Shift Settings', 'module' => 'Settings', 'route' => 'settings.shifts',     'abilities' => $access],

        // BIL — Finished Goods
        ['key' => 'bil.finished_goods.products', 'label' => 'Products', 'module' => 'BIL / Finished Goods', 'route' => 'bil.finished-goods.products', 'abilities' => $crud],

        // BIL — Raw Materials
        ['key' => 'bil.raw_materials.statistics',          'label' => 'Statistics',          'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.statistics',          'abilities' => $snapshot],
        ['key' => 'bil.raw_materials.products',            'label' => 'Products',            'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.products',            'abilities' => $crud],
        ['key' => 'bil.raw_materials.suppliers',           'label' => 'Suppliers',           'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.suppliers',           'abilities' => $crud],
        ['key' => 'bil.raw_materials.supplier_deliveries', 'label' => 'Supplier Deliveries', 'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.supplier-deliveries', 'abilities' => $entry],
        ['key' => 'bil.raw_materials.warehouse_entry',     'label' => 'Warehouse Entry',     'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.warehouse-entry',     'abilities' => $entry],
        ['key' => 'bil.raw_materials.warehouse_exit',      'label' => 'Warehouse Exit',      'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.warehouse-exit',      'abilities' => $entry],
        ['key' => 'bil.raw_materials.stock_transfer',      'label' => 'Stock Transfer',      'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.stock-transfer',      'abilities' => $entry],
        ['key' => 'bil.raw_materials.factory_entrance',    'label' => 'Factory Entrance',    'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.factory-entrance',    'abilities' => ['view', 'backdate', 'bypass-shift']],
        ['key' => 'bil.raw_materials.consumption',         'label' => 'Consumption',         'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.consumption',         'abilities' => ['view', 'backdate', 'bypass-shift']],
        ['key' => 'bil.raw_materials.factory_returns',     'label' => 'Factory Returns',     'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.factory-returns',     'abilities' => ['view', 'backdate', 'approve']],
        ['key' => 'bil.raw_materials.damaged_goods',       'label' => 'Damaged Goods',       'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.damaged-goods',       'abilities' => ['view', 'backdate', 'approve']],

        // BIL — Raw Materials Reports
        ['key' => 'bil.raw_materials.reports.supplier_deliveries', 'label' => 'Supplier Deliveries', 'module' => 'BIL / Raw Materials Reports', 'route' => 'bil.raw-materials.reports.supplier-deliveries', 'abilities' => $report],
        ['key' => 'bil.raw_materials.reports.warehouse_entry',     'label' => 'Warehouse Entry',     'module' => 'BIL / Raw Materials Reports', 'route' => 'bil.raw-materials.reports.warehouse-entry',     'abilities' => $report],
        ['key' => 'bil.raw_materials.reports.warehouse_exit',      'label' => 'Warehouse Exit',      'module' => 'BIL / Raw Materials Reports', 'route' => 'bil.raw-materials.reports.warehouse-exit',      'abilities' => $report],
        ['key' => 'bil.raw_materials.reports.factory_entrance',    'label' => 'Factory Entrance',    'module' => 'BIL / Raw Materials Reports', 'route' => 'bil.raw-materials.reports.factory-entrance',    'abilities' => $report],
        ['key' => 'bil.raw_materials.reports.consumption',         'label' => 'Consumption',         'module' => 'BIL / Raw Materials Reports', 'route' => 'bil.raw-materials.reports.consumption',         'abilities' => $report],
        ['key' => 'bil.raw_materials.reports.warehouse_stock',     'label' => 'Warehouse Stock',     'module' => 'BIL / Raw Materials Reports', 'route' => 'bil.raw-materials.reports.warehouse-stock',     'abilities' => $snapshot],
        ['key' => 'bil.raw_materials.reports.factory_floor_stock', 'label' => 'Factory Floor Stock', 'module' => 'BIL / Raw Materials Reports', 'route' => 'bil.raw-materials.reports.factory-floor-stock', 'abilities' => $snapshot],
        ['key' => 'bil.raw_materials.reports.factory_returns',     'label' => 'Factory Returns',     'module' => 'BIL / Raw Materials Reports', 'route' => 'bil.raw-materials.reports.factory-returns',     'abilities' => $report],
        ['key' => 'bil.raw_materials.reports.damaged_goods',       'label' => 'Damaged Goods',       'module' => 'BIL / Raw Materials Reports', 'route' => 'bil.raw-materials.reports.damaged-goods',       'abilities' => $report],

        // BPL — Jumbo Rolls
        ['key' => 'bpl.jumbo_rolls.grades',            'label' => 'Grades',              'module' => 'BPL / Jumbo Rolls', 'route' => 'bpl.jumbo-rolls.grades',            'abilities' => $crud],
        ['key' => 'bpl.jumbo_rolls.products.hardroll', 'label' => 'Products (Hardroll)', 'module' => 'BPL / Jumbo Rolls', 'route' => 'bpl.jumbo-rolls.products.hardroll', 'abilities' => $crud],
        ['key' => 'bpl.jumbo_rolls.products.softroll', 'label' => 'Products (Softroll)', 'module' => 'BPL / Jumbo Rolls', 'route' => 'bpl.jumbo-rolls.products.softroll', 'abilities' => $crud],

        // BPL — Jumbo Rolls / Sales
        ['key' => 'bpl.jumbo_rolls.sales.customers', 'label' => 'Customers', 'module' => 'BPL / Jumbo Rolls / Sales', 'route' => 'bpl.jumbo-rolls.sales.customers', 'abilities' => $crud],
    ],

    // Display labels for ability columns in the Role matrix (order matters).
    'abilities' => [
        'view' => 'View',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'export' => 'Export',
        'backdate' => 'Backdate',
        'approve' => 'Approve',
        'bypass-shift' => 'Bypass shift',
    ],
];
