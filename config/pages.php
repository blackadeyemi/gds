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
|
| 'gates' marks a page whose dropdown is filled from warehouse or factory gates.
| The user editor reads it to decide whether to offer that gate checklist at all:
| a role that cannot open any gate page has no use for one. It is a hint for the
| editor, NOT access control — GateAccess decides what a user may pick, and the
| page middleware decides who may open the screen.
*/

$crud = ['view', 'create', 'edit', 'delete', 'export'];
$access = ['view'];
// Entry forms: access IS the create action, so no separate create/edit/delete —
// just view plus the special abilities that page supports.
$entry = ['view', 'backdate'];
$report = ['view', 'edit', 'delete', 'export'];
// Entry form with no backdate concept: the dates ARE the record (a service job's
// start and end), so there is nothing to lock to today.
$entryPlain = ['view'];
// Read-only reports / dashboards: viewable and exportable, nothing to edit.
$snapshot = ['view', 'export'];

return [
    'pages' => [
        // BIL — Machines (Company > Factory > Line > Project)
        ['key' => 'bil.machines.statistics', 'label' => 'Statistics', 'module' => 'BIL / Machines', 'route' => 'bil.machines.statistics', 'abilities' => $snapshot],
        ['key' => 'bil.machines.lines',    'label' => 'Lines',    'module' => 'BIL / Machines', 'route' => 'bil.machines.lines',    'abilities' => $crud],
        ['key' => 'bil.machines.projects', 'label' => 'Projects', 'module' => 'BIL / Machines', 'route' => 'bil.machines.projects', 'abilities' => $crud],
        ['key' => 'bil.machines.conversion_setup', 'label' => 'Conversion Setup', 'module' => 'BIL / Machines', 'route' => 'bil.machines.conversion-setup', 'abilities' => $crud],
        ['key' => 'bil.machines.services', 'label' => 'Services', 'module' => 'BIL / Machines', 'route' => 'bil.machines.services', 'abilities' => $entryPlain],

        // BIL — Machines Reports
        ['key' => 'bil.machines.reports.services', 'label' => 'Services', 'module' => 'BIL / Machines Reports', 'route' => 'bil.machines.reports.services', 'abilities' => $report],
        // The setup page owns the lifecycle; the log is a read-out.
        ['key' => 'bil.machines.reports.conversion_history', 'label' => 'Conversion History', 'module' => 'BIL / Machines Reports', 'route' => 'bil.machines.reports.conversion-history', 'abilities' => $snapshot],

        // BIL — Finished Goods
        ['key' => 'bil.finished_goods.statistics', 'label' => 'Statistics', 'module' => 'BIL / Finished Goods', 'route' => 'bil.finished-goods.statistics', 'abilities' => $snapshot],
        ['key' => 'bil.finished_goods.products', 'label' => 'Products', 'module' => 'BIL / Finished Goods', 'route' => 'bil.finished-goods.products', 'abilities' => $crud],
        ['key' => 'bil.finished_goods.conversion_output', 'label' => 'Conversion Output', 'module' => 'BIL / Finished Goods', 'route' => 'bil.finished-goods.conversion-output', 'abilities' => ['view', 'backdate', 'bypass-shift']],
        // Entry plus the two supervisory acts that close and re-open a run, and
        // the bypass that lets production continue when a run cannot be closed.
        ['key' => 'bil.finished_goods.stock_transfer', 'label' => 'Stock Transfer', 'module' => 'BIL / Finished Goods', 'route' => 'bil.finished-goods.stock-transfer', 'abilities' => ['view', 'backdate']],
        ['key' => 'bil.finished_goods.stock_transfer_receive', 'label' => 'Receive Transfer', 'module' => 'BIL / Finished Goods', 'route' => 'bil.finished-goods.stock-transfer.receive', 'abilities' => ['view', 'approve', 'cancel']],
        ['key' => 'bil.finished_goods.conversion_waste', 'label' => 'Conversion Waste', 'module' => 'BIL / Finished Goods', 'route' => 'bil.finished-goods.conversion-waste', 'abilities' => ['view', 'confirm', 'reopen', 'bypass-waste-lock', 'bypass-shift']],
        ['key' => 'bil.finished_goods.factory_exit', 'label' => 'Factory Exit', 'module' => 'BIL / Finished Goods', 'route' => 'bil.finished-goods.factory-exit', 'abilities' => $entry, 'gates' => 'factory'],
        // Stock lines are created by movement and never deleted, so no create
        // or delete ability; `edit` records an adjustment.
        ['key' => 'bil.finished_goods.warehouse_stock', 'label' => 'Warehouse Stock', 'module' => 'BIL / Finished Goods', 'route' => 'bil.finished-goods.warehouse-stock', 'abilities' => ['view', 'edit', 'export']],
        // Pallets made but not yet sent on — read-only, since it is a view
        // over conversion output rather than a table of its own.
        ['key' => 'bil.finished_goods.reports.factory_floor_stock', 'label' => 'Factory Floor Stock', 'module' => 'BIL / Finished Goods Reports', 'route' => 'bil.finished-goods.reports.factory-floor-stock', 'abilities' => ['view', 'export']],
        ['key' => 'bil.finished_goods.reports.stock_transfer', 'label' => 'Stock Transfer', 'module' => 'BIL / Finished Goods Reports', 'route' => 'bil.finished-goods.reports.stock-transfer', 'abilities' => $snapshot],
        ['key' => 'bil.finished_goods.reports.conversion_waste', 'label' => 'Conversion Waste', 'module' => 'BIL / Finished Goods Reports', 'route' => 'bil.finished-goods.reports.conversion-waste', 'abilities' => $snapshot],
        ['key' => 'bil.finished_goods.warehouse_entrance', 'label' => 'Warehouse Entrance', 'module' => 'BIL / Finished Goods', 'route' => 'bil.finished-goods.warehouse-entrance', 'abilities' => $entry, 'gates' => 'warehouse'],

        // BIL — Finished Goods Reports. No `edit`: a pallet's weights come from
        // the product spec, and an exit row is a scan event — a wrong one is
        // deleted and re-made rather than corrected in place.
        ['key' => 'bil.finished_goods.reports.conversion_output', 'label' => 'Conversion Output', 'module' => 'BIL / Finished Goods Reports', 'route' => 'bil.finished-goods.reports.conversion-output', 'abilities' => ['view', 'delete', 'export']],
        ['key' => 'bil.finished_goods.reports.factory_exit', 'label' => 'Factory Exit', 'module' => 'BIL / Finished Goods Reports', 'route' => 'bil.finished-goods.reports.factory-exit', 'abilities' => ['view', 'delete', 'export']],
        // Deleting a receipt also takes its bundles back out of the warehouse
        // stock, so `delete` here is a stock permission, not a tidy-up one.
        ['key' => 'bil.finished_goods.reports.warehouse_entrance', 'label' => 'Warehouse Entrance', 'module' => 'BIL / Finished Goods Reports', 'route' => 'bil.finished-goods.reports.warehouse-entrance', 'abilities' => ['view', 'delete', 'export']],

        // BIL — Sales. An order is placed, edited and withdrawn on the one
        // screen, so there is no separate `create`: `view` is placing an order
        // and editing one, and `delete` is the withdrawal — which is refused
        // outright once anything has been loaded against the order.
        ['key' => 'bil.sales.customers', 'label' => 'Customers', 'module' => 'BIL / Sales', 'route' => 'bil.sales.customers', 'abilities' => $crud],
        ['key' => 'bil.sales.transporters', 'label' => 'Transporters', 'module' => 'BIL / Sales', 'route' => 'bil.sales.transporters', 'abilities' => $crud],
        ['key' => 'bil.sales.orders', 'label' => 'Orders', 'module' => 'BIL / Sales', 'route' => 'bil.sales.orders', 'abilities' => ['view', 'delete', 'backdate']],
        ['key' => 'bil.sales.loading', 'label' => 'Loading', 'module' => 'BIL / Sales', 'route' => 'bil.sales.loading', 'abilities' => ['view', 'create', 'modify', 'return', 'backdate'], 'gates' => 'warehouse'],
        ['key' => 'bil.sales.delivery', 'label' => 'Delivery', 'module' => 'BIL / Sales', 'route' => 'bil.sales.delivery', 'abilities' => ['view', 'confirm', 'delete'], 'gates' => 'warehouse'],
        ['key' => 'bil.sales.returns', 'label' => 'Returns', 'module' => 'BIL / Sales', 'route' => 'bil.sales.returns', 'abilities' => ['view', 'create', 'modify', 'delete']],

        // BIL — Raw Materials
        ['key' => 'bil.raw_materials.statistics',          'label' => 'Statistics',          'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.statistics',          'abilities' => $snapshot],
        ['key' => 'bil.raw_materials.products',            'label' => 'Products',            'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.products',            'abilities' => $crud],
        ['key' => 'bil.raw_materials.suppliers',           'label' => 'Suppliers',           'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.suppliers',           'abilities' => $crud],
        ['key' => 'bil.raw_materials.supplier_deliveries', 'label' => 'Supplier Deliveries', 'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.supplier-deliveries', 'abilities' => $entry],
        ['key' => 'bil.raw_materials.warehouse_entry',     'label' => 'Warehouse Entry',     'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.warehouse-entry',     'abilities' => $entry, 'gates' => 'warehouse'],
        ['key' => 'bil.raw_materials.warehouse_exit',      'label' => 'Warehouse Exit',      'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.warehouse-exit',      'abilities' => $entry, 'gates' => 'warehouse'],
        ['key' => 'bil.raw_materials.stock_transfer',      'label' => 'Stock Transfer',      'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.stock-transfer',      'abilities' => $entry],
        ['key' => 'bil.raw_materials.factory_entrance',    'label' => 'Factory Entrance',    'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.factory-entrance',    'abilities' => ['view', 'backdate', 'bypass-shift'], 'gates' => 'factory'],
        ['key' => 'bil.raw_materials.consumption',         'label' => 'Consumption',         'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.consumption',         'abilities' => ['view', 'backdate', 'bypass-shift']],
        ['key' => 'bil.raw_materials.factory_returns',     'label' => 'Factory Returns',     'module' => 'BIL / Raw Materials', 'route' => 'bil.raw-materials.factory-returns',     'abilities' => ['view', 'backdate', 'approve'], 'gates' => 'warehouse'],
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

        // BIL — Jumbo Rolls (the reels BPL makes for BIL, from the gate inwards)
        ['key' => 'bil.jumbo_rolls.statistics',      'label' => 'Statistics',       'module' => 'BIL / Jumbo Rolls', 'route' => 'bil.jumbo-rolls.statistics',      'abilities' => $snapshot],
        ['key' => 'bil.jumbo_rolls.factory_entrance', 'label' => 'Factory Entrance', 'module' => 'BIL / Jumbo Rolls', 'route' => 'bil.jumbo-rolls.factory-entrance', 'abilities' => ['view', 'backdate', 'bypass-shift'], 'gates' => 'factory'],
        // Consumption picks a machine, not a gate, so no gate checklist.
        ['key' => 'bil.jumbo_rolls.consumption',      'label' => 'Consumption',      'module' => 'BIL / Jumbo Rolls', 'route' => 'bil.jumbo-rolls.consumption',      'abilities' => ['view', 'backdate', 'bypass-shift']],
        // Sending a reel back to BPL. Carries its own date of return, so the
        // day the truck left can be recorded rather than the day it was typed.
        ['key' => 'bil.jumbo_rolls.returns',         'label' => 'Returns',          'module' => 'BIL / Jumbo Rolls', 'route' => 'bil.jumbo-rolls.returns',         'abilities' => ['view', 'backdate', 'bypass-shift']],
        // A live snapshot derived from the movement tables — nothing to edit here.
        ['key' => 'bil.jumbo_rolls.stock',           'label' => 'Stock',            'module' => 'BIL / Jumbo Rolls', 'route' => 'bil.jumbo-rolls.stock',           'abilities' => $snapshot],

        // BIL — Jumbo Rolls Reports. Read-outs: the screens that write these
        // rows own the corrections, so no edit/delete here.
        ['key' => 'bil.jumbo_rolls.reports.factory_entrance', 'label' => 'Factory Entrance', 'module' => 'BIL / Jumbo Rolls Reports', 'route' => 'bil.jumbo-rolls.reports.factory-entrance', 'abilities' => $snapshot],
        ['key' => 'bil.jumbo_rolls.reports.consumption',      'label' => 'Consumption',      'module' => 'BIL / Jumbo Rolls Reports', 'route' => 'bil.jumbo-rolls.reports.consumption',      'abilities' => $snapshot],
        ['key' => 'bil.jumbo_rolls.reports.returns',          'label' => 'Returns',          'module' => 'BIL / Jumbo Rolls Reports', 'route' => 'bil.jumbo-rolls.reports.returns',          'abilities' => $snapshot],

        // BPL — Jumbo Rolls
        ['key' => 'bpl.jumbo_rolls.grades',            'label' => 'Grades',              'module' => 'BPL / Jumbo Rolls', 'route' => 'bpl.jumbo-rolls.grades',            'abilities' => $crud],
        ['key' => 'bpl.jumbo_rolls.products.hardroll', 'label' => 'Products (Hardroll)', 'module' => 'BPL / Jumbo Rolls', 'route' => 'bpl.jumbo-rolls.products.hardroll', 'abilities' => $crud],
        ['key' => 'bpl.jumbo_rolls.products.softroll', 'label' => 'Products (Softroll)', 'module' => 'BPL / Jumbo Rolls', 'route' => 'bpl.jumbo-rolls.products.softroll', 'abilities' => $crud],

        // BPL — Jumbo Rolls / Sales
        ['key' => 'bpl.jumbo_rolls.sales.customers', 'label' => 'Customers', 'module' => 'BPL / Jumbo Rolls / Sales', 'route' => 'bpl.jumbo-rolls.sales.customers', 'abilities' => $crud],

        /*
        | Admin and Settings LAST, deliberately.
        |
        | `sort_order` is this array's index, and both the Pages screen and
        | the Role matrix group by it — so this order is the order those
        | screens read in. Structure and configuration are set up once and
        | rarely touched again, while the BIL and BPL pages are what a role
        | is actually built out of, so the day-to-day work comes first and
        | these sit at the bottom.
        */
        // Admin
        ['key' => 'admin.users',       'label' => 'Users',       'module' => 'Admin', 'route' => 'admin.users',       'abilities' => $crud],
        ['key' => 'admin.roles',       'label' => 'Roles',       'module' => 'Admin', 'route' => 'admin.roles',       'abilities' => $crud],
        ['key' => 'admin.departments', 'label' => 'Departments', 'module' => 'Admin', 'route' => 'admin.departments', 'abilities' => $crud],
        ['key' => 'admin.companies',   'label' => 'Companies',   'module' => 'Admin', 'route' => 'admin.companies',   'abilities' => $crud],
        ['key' => 'admin.factories',   'label' => 'Factories',   'module' => 'Admin', 'route' => 'admin.factories',   'abilities' => $crud],
        // Warehouses and gates: the storage side of the same structure.
        ['key' => 'admin.warehouses',  'label' => 'Warehouses',  'module' => 'Admin', 'route' => 'admin.warehouses',  'abilities' => $crud],
        ['key' => 'admin.warehouse_gates', 'label' => 'Warehouse Gates', 'module' => 'Admin', 'route' => 'admin.warehouse_gates', 'abilities' => $crud],
        ['key' => 'admin.factory_gates', 'label' => 'Factory Gates', 'module' => 'Admin', 'route' => 'admin.factory_gates', 'abilities' => $crud],
        ['key' => 'admin.divisions',   'label' => 'Divisions',   'module' => 'Admin', 'route' => 'admin.divisions',   'abilities' => $crud],
        ['key' => 'admin.staff',       'label' => 'Staff',       'module' => 'Admin', 'route' => 'admin.staff',       'abilities' => $crud],

        // Settings
        ['key' => 'settings.pages',      'label' => 'Pages',          'module' => 'Settings', 'route' => 'settings.pages',      'abilities' => $access],
        ['key' => 'settings.data_views', 'label' => 'Data Views',     'module' => 'Settings', 'route' => 'settings.data-views', 'abilities' => $access],
        ['key' => 'settings.shifts',     'label' => 'Shift Settings', 'module' => 'Settings', 'route' => 'settings.shifts',     'abilities' => $access],
        ['key' => 'settings.service_types', 'label' => 'Service Types', 'module' => 'Settings', 'route' => 'settings.service-types', 'abilities' => $crud],
        // One page holding two lists (causes + origins), edited inline — so
        // 'edit' is the whole write ability; there is no separate create/delete.
        ['key' => 'settings.waste',      'label' => 'Waste Settings', 'module' => 'Settings', 'route' => 'settings.waste',      'abilities' => ['view', 'edit']],
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
        'cancel' => 'Cancel',
        'modify' => 'Modify',
        'return' => 'Return',
        'confirm' => 'Confirm',
        'reopen' => 'Re-open',
        'bypass-waste-lock' => 'Bypass waste lock',
    ],
];
