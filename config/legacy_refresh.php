<?php

/*
| Monthly legacy-data refresh (gds:refresh-legacy).
|
| The legacy production server exports a per-table dump of the monolithic
| `bilfg` DB (files: table-<name>.sql, CREATE IF NOT EXISTS + REPLACE INTO).
| gds has since split that DB into core/bil/bpl and reshaped some tables, so we
| DON'T restore the dump — we refresh only the OPERATIONAL data tables, in a
| controlled way:
|
|   dump file -> staging DB -> TRUNCATE gds target -> INSERT (common columns).
|
| The column-intersection is drift-proof (ignores columns either side added) and
| preserves gds-added columns. BEFORE INSERT triggers on the target re-derive the
| factory-hierarchy ids automatically. See gds-page-access / the refresh command
| for the full contract.
|
| SCOPE = operational/transactional only. Reference lookups, gds-native (RBAC,
| companies, hierarchy, shifts), the bpl_products catalog + its hardroll/softroll
| split, and all dead/dropped tables are intentionally excluded.
*/

return [
    // Extracted dump directory holding the table-*.sql files.
    'dump_path' => env('LEGACY_DUMP_PATH', 'C:/laragon/www/bil/sql/sql_09_26/bilfg'),

    // Throwaway staging schema the dump files are loaded into each run.
    'staging_db' => env('LEGACY_STAGING_DB', 'bilfg_stage'),

    // mysql / mysqldump client (the split DBs live on one local server).
    'mysql_bin' => env('LEGACY_MYSQL_BIN', 'C:/laragon/bin/mysql/mysql-8.4.6-winx64/bin/mysql.exe'),
    'mysqldump_bin' => env('LEGACY_MYSQLDUMP_BIN', 'C:/laragon/bin/mysql/mysql-8.4.6-winx64/bin/mysqldump.exe'),

    /*
    | The allowlist. legacy_table => "connection.target_table".
    | Most map to the same name; the five below were renamed by the conversion
    | split and are now writable compat views over these base tables — we load
    | the BASE table (triggers live there), and the view reflects it.
    */
    'map' => [
        // --- BIL: renamed base tables (legacy name is now a compat view) ---
        'factory_production'          => 'bil.factory_conversion',
        'factory_preproduction'       => 'bil.conversion_setup',
        'factoryentrance_rawmaterial' => 'bil.factory_entrance_rawmaterials',
        'rawmaterials'                => 'bil.rawmaterials_warehouse_entry',
        'rawmaterials_store_exit'     => 'bil.rawmaterials_warehouse_exit',

        // --- BIL: same-name operational tables ---
        'factory_exit'                        => 'bil.factory_exit',
        'store_entrance'                       => 'bil.store_entrance',
        'storebundle'                          => 'bil.storebundle',
        'storebundle_floor'                    => 'bil.storebundle_floor',
        'store_adjustment'                     => 'bil.store_adjustment',
        'stock_transfer'                       => 'bil.stock_transfer',
        'factory_usage_reel'                   => 'bil.factory_usage_reel',
        'factory_entrance_reel'                => 'bil.factory_entrance_reel',
        'factory_usage_rawmaterials'           => 'bil.factory_usage_rawmaterials',
        'factory_machine_maintenance'          => 'bil.factory_machine_maintenance',
        'factory_machine_maintenance_comment'  => 'bil.factory_machine_maintenance_comment',
        'factory_event'                        => 'bil.factory_event',
        'factory_waste'                        => 'bil.factory_waste',
        'factoryentrance_details'              => 'bil.factoryentrance_details',
        'damagedgoods_rawmaterial'             => 'bil.damagedgoods_rawmaterial',
        'jumboreel_stock'                      => 'bil.jumboreel_stock',
        // The sales chain, and it has to be ALL of it. Refreshing the loadings
        // without the deliveries leaves every load since the last delivery in
        // the dump marked delivered on its own row with no delivery note to
        // show for it — 710 of them, and no print-out for any of them.
        'sales_loading'                        => 'bil.sales_loading',
        'sales_loading_return'                 => 'bil.sales_loading_return',
        'sales_order'                          => 'bil.sales_order',
        'sales_order_details'                  => 'bil.sales_order_details',
        'sales_delivery'                       => 'bil.sales_delivery',
        'sales_waybill'                        => 'bil.sales_waybill',
        'sales_return'                         => 'bil.sales_return',

        // --- BPL: same-name operational tables ---
        'bpl_production'                  => 'bpl.bpl_production',
        'bpl_softroll_production'         => 'bpl.bpl_softroll_production',
        'bpl_factoryexit'                 => 'bpl.bpl_factoryexit',
        'bpl_softroll_factoryexit'        => 'bpl.bpl_softroll_factoryexit',
        'bpl_storeentrance'               => 'bpl.bpl_storeentrance',
        'bpl_storeexit'                   => 'bpl.bpl_storeexit',
        'softroll_storeentrance'          => 'bpl.softroll_storeentrance',
        'softroll_storeexit'              => 'bpl.softroll_storeexit',
        'bpl_store_count'                 => 'bpl.bpl_store_count',
        'bpl_storeentrance_trash'         => 'bpl.bpl_storeentrance_trash',
        'bpl_storeentrance_trash_details' => 'bpl.bpl_storeentrance_trash_details',
        'bpl_delivery'                    => 'bpl.bpl_delivery',
        'bpl_delivery_barcode'            => 'bpl.bpl_delivery_barcode',
        'bpl_delivery_details'            => 'bpl.bpl_delivery_details',
        'bpl_stock'                       => 'bpl.bpl_stock',
        'bpl_sales'                       => 'bpl.bpl_sales',
        'bpl_sales_items'                 => 'bpl.bpl_sales_items',
        'bpl_packing_list'                => 'bpl.bpl_packing_list',
        'bpl_bill_of_lading'              => 'bpl.bpl_bill_of_lading',
        'bpl_proforma'                    => 'bpl.bpl_proforma',
        'bpl_proforma_items'              => 'bpl.bpl_proforma_items',
        'bpl_invoice_payments'            => 'bpl.bpl_invoice_payments',
        'bpl_quarantine'                  => 'bpl.bpl_quarantine',
        'bpl_quarantine_storeexit'        => 'bpl.bpl_quarantine_storeexit',
        'softroll_stock'                  => 'bpl.softroll_stock',
    ],

    /*
    | Constants written to gds-added columns for the reloaded rows (the dump has
    | no value for them). Everything else gds-added is either trigger-derived
    | (hierarchy ids) or intentionally left NULL (gate_id, received_at — legacy
    | rows genuinely have no gate / were never received into BIL).
    */
    'defaults' => [
        'bil.rawmaterials_warehouse_entry' => ['source' => 'legacy'],
    ],

    /*
    | Non-trigger gds columns left NULL on reload that you may later choose to
    | backfill. The command lists these in --dry-run so nothing is silent.
    */
    'nullable_review' => [
        'bil.factory_exit'                => ['exit_location_id'],
        'bil.factory_usage_reel'          => ['reel_barcode'],
        'bil.factory_event'               => ['reel_barcode', 'date', 'reason'],
        'bil.factory_machine_maintenance' => ['duration_minutes'],
    ],

    // Tables the dump legitimately has empty — don't warn when they truncate to 0.
    'allow_empty' => [
        'bil.factory_waste',
        'bil.factory_machine_maintenance_comment',
    ],
];
