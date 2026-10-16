<?php

/*
| BHN (Abuja) legacy-data refresh — a PROFILE of gds:refresh-legacy
| (run: php artisan gds:refresh-legacy --profile=legacy_refresh_bhn).
|
| BHN runs a clone of the BIL legacy codebase in Abuja, so its legacy schema is
| BIL-shaped. gds mirrors BIL's modern structure into the `bhn` schema, and this
| profile loads BHN's own operational + reference data into it — same contract as
| the bilfg refresh: single dump -> staging -> TRUNCATE target -> INSERT (common
| columns). Column-intersection is drift-proof; BEFORE INSERT triggers re-derive
| the factory-hierarchy ids.
|
| DIFFERENCES from the bilfg profile (config/legacy_refresh.php):
|   - source = single_dump: BHN exports ONE bhn.sql (phpMyAdmin), not per-table
|     files. The whole dump is loaded into staging once per run.
|   - staging_conn / target = bhn: everything is the bhn schema (homed to the
|     Abuja server in production; local for dev).
|   - BHN is a NEW company with no prior gds migration, so this also loads the
|     REFERENCE tables (customers, suppliers, products, designations, groups…)
|     that the bilfg profile omits — for BIL those were migrated during the split.
|
| EXCLUDED (handled elsewhere, not by this schema refresh):
|   - user / userlevels  -> core auth (migrate BHN users into core.users).
|   - machines / machine_lines / sublines -> core machine hierarchy (company 4).
|   - company            -> core.companies (BHN already seeded, id 4).
|   - fg_inter_* , logs, stock, bpl_grades, bpl_products -> core/bpl (shims).
|   - *_backup, rawmaterials_copy, importdata -> dead/scratch.
*/

return [
    'source' => 'single_dump',

    // The monolithic BHN dump. Monthly: drop a fresh bhn.sql here.
    'dump_file' => env('BHN_DUMP_FILE', 'C:/laragon/www/bil/sql/bhn/bhn.sql'),

    // Throwaway staging schema the dump is loaded into each run.
    'staging_db' => env('BHN_STAGING_DB', 'bhn_stage'),

    // Preprocess staging before the refresh reads it: prune to company 2 (BHN).
    'post_stage_sql' => 'database/bhn/prune_stage_company2.sql',

    // The connection staging + targets live on (Abuja server in prod).
    'staging_conn' => 'bhn',

    // mysql / mysqldump client.
    'mysql_bin' => env('LEGACY_MYSQL_BIN', 'C:/laragon/bin/mysql/mysql-8.4.6-winx64/bin/mysql.exe'),
    'mysqldump_bin' => env('LEGACY_MYSQLDUMP_BIN', 'C:/laragon/bin/mysql/mysql-8.4.6-winx64/bin/mysqldump.exe'),

    /*
    | The allowlist. legacy_table => "bhn.target_table".
    | The six renamed ones are modern base tables the legacy name is now a
    | writable compat view over (identical renames to the BIL split).
    */
    'map' => [
        // --- renamed base tables (legacy name is now a compat view) ---
        'factory_production'           => 'bhn.factory_conversion',
        // factory_preproduction / _history are OMITTED: no company column and the
        // line names are shared across both Abuja companies, so they can't be
        // scoped to BHN. Conversion setup is re-established fresh in gds.
        'factoryentrance_rawmaterial'  => 'bhn.factory_entrance_rawmaterials',
        'rawmaterials'                 => 'bhn.rawmaterials_warehouse_entry',
        'rawmaterials_store_exit'      => 'bhn.rawmaterials_warehouse_exit',

        // --- same-name operational tables ---
        'factory_exit'                 => 'bhn.factory_exit',
        'factory_entrance_reel'        => 'bhn.factory_entrance_reel',
        'factory_usage_reel'           => 'bhn.factory_usage_reel',
        'factory_usage_rawmaterials'   => 'bhn.factory_usage_rawmaterials',
        'factoryentrance_details'      => 'bhn.factoryentrance_details',
        'factoryexit_details'          => 'bhn.factoryexit_details',
        'damagedgoods_rawmaterial'     => 'bhn.damagedgoods_rawmaterial',
        'jumboreel_stock'              => 'bhn.jumboreel_stock',
        'store_entrance'               => 'bhn.store_entrance',
        'storeentrance_details'        => 'bhn.storeentrance_details',
        'storebundle'                  => 'bhn.storebundle',
        'storebundle_floor'            => 'bhn.storebundle_floor',
        'store_adjustment'             => 'bhn.store_adjustment',
        'store_cagerooms'              => 'bhn.store_cagerooms',
        'store_floors'                 => 'bhn.store_floors',
        'stock_transfer'               => 'bhn.stock_transfer',
        'stockhistory'                 => 'bhn.stockhistory',

        // The sales chain — all of it (loadings need their deliveries).
        'sales_order'                  => 'bhn.sales_order',
        'sales_order_details'          => 'bhn.sales_order_details',
        'sales_loading'                => 'bhn.sales_loading',
        'sales_loading_return'         => 'bhn.sales_loading_return',
        'sales_delivery'               => 'bhn.sales_delivery',
        'sales_waybill'                => 'bhn.sales_waybill',
        'sales_return'                 => 'bhn.sales_return',
        'sales_forecast'               => 'bhn.sales_forecast',
        'sales_forecast_history'       => 'bhn.sales_forecast_history',

        // --- reference / lookup tables (BHN's own; no prior gds migration) ---
        'sales_customers'              => 'bhn.sales_customers',
        'sales_transporters'           => 'bhn.sales_transporters',
        'sales_designation'            => 'bhn.sales_designation',
        'sales_warehouse'              => 'bhn.sales_warehouse',
        'products'                     => 'bhn.products',
        'qc_revision'                  => 'bhn.qc_revision',
        'rawmaterials_supplier'        => 'bhn.rawmaterials_supplier',
        'rawmaterials_groups'          => 'bhn.rawmaterials_groups',
        'rawmaterials_subgroups'       => 'bhn.rawmaterials_subgroups',
        'rawmaterials_products'        => 'bhn.rawmaterials_products',
        // rawmaterials_stock is intentionally OMITTED — it's a derived aggregate
        // rebuilt by the stock reconcile (like BIL), not loaded from legacy.
        'rawmaterial_store_location'   => 'bhn.rawmaterial_store_location',
        'rawmaterials_transferlocations' => 'bhn.rawmaterials_transferlocations',
        'redirections'                 => 'bhn.redirections',
        'reprint_approval'             => 'bhn.reprint_approval',
        'return_approval'              => 'bhn.return_approval',
    ],

    // Constants written to gds-added columns for reloaded rows (at INSERT time,
    // so NOT-NULL columns absent from the legacy dump still get a value).
    'defaults' => [
        'bhn.rawmaterials_warehouse_entry' => ['source' => 'legacy'],
        // BHN legacy never tracked an entrance location; the modern column is
        // NOT NULL. Historical rolls get an empty location placeholder.
        'bhn.factory_entrance_reel' => ['location' => ''],
    ],

    /*
    | Per-column SELECT overrides for legacy->modern type/constraint mismatches
    | (the Abuja clone's schema drifted from BIL's). Expressions run against the
    | staging column of the same name.
    */
    'transforms' => [
        // Legacy stores this as a TIMESTAMP datetime; modern is a unix int.
        'bhn.factory_entrance_reel' => [
            'timestamp' => 'UNIX_TIMESTAMP(`timestamp`)',
        ],
        // Modern modification is NOT-NULL json; all 37 legacy rows are null.
        // location is NOT-NULL; coerce any null to empty string.
        'bhn.jumboreel_stock' => [
            'modification' => "COALESCE(NULLIF(`modification`, ''), '{}')",
            'location' => "COALESCE(`location`, '')",
        ],
    ],

    // Non-trigger gds columns left NULL on reload (listed in --dry-run).
    'nullable_review' => [
        'bhn.factory_exit' => ['exit_location_id'],
    ],

    // Tables the dump legitimately has empty — don't warn when they truncate to 0.
    'allow_empty' => [
        'bhn.sales_warehouse',
        'bhn.stockhistory',
        'bhn.sales_return',
        'bhn.damagedgoods_rawmaterial',
        'bhn.store_adjustment',
        'bhn.stock_transfer',
        'bhn.reprint_approval',
    ],
];
