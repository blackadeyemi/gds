<?php

/*
| Activity log + notifications. See Modules\Core\Support\Activity\ActivityRecorder.
|
| Every write a signed-in user makes through a gds page is recorded — no page
| has to call anything. The settings here only shape what is recorded and how
| it reads.
*/

return [

    /*
    | Days kept. `gds:prune-activity` (scheduled daily) removes older rows.
    |
    | This is the DEFAULT: an Admin can override it at Settings → Activity Log
    | (stored in app_settings under this same key — see Support\Settings), and
    | "Use default" there puts it back to this value.
    |
    | The bounds are what the settings page accepts. The floor is deliberately
    | not 1: the log is the audit trail, and a one-day setting would let anyone
    | with the setting erase it the next night.
    */
    'retention_days' => (int) env('ACTIVITY_RETENTION_DAYS', 730),
    'retention_days_min' => 30,
    'retention_days_max' => 3650,

    /* Items stored per activity. A batch of 600 pallets is still ONE activity
       with item_count = 600; only the first this-many carry their details. */
    'max_items' => 200,

    /*
    | Tables never recorded, as "database.table" or a bare table name.
    |
    | Framework plumbing, the log itself, and the stock CACHES: those are
    | derived from the movements (which ARE recorded) and rewritten as a side
    | effect of every movement, so listing them would only bury what the user
    | actually did.
    */
    'ignore_tables' => [
        'activities', 'activity_items', 'activity_notifications', 'activity_mutes',
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'migrations',
        'raw_materials_warehouse_stock', 'finished_goods_warehouse_stock',
        'rawmaterials_stock', 'bpl_stock',
    ],

    /* Columns that are never shown, only that they changed. */
    'masked_columns' => ['password', 'remember_token', 'api_token', 'token', 'secret'],

    /* Columns left out of a diff entirely — they change on every save. */
    'ignored_columns' => ['updated_at', 'created_at', 'timestamp', 'modified'],

    /*
    | The column that names a record to a person, first match wins. Used for
    | the "ref" shown beside each change and searched by the Activity page —
    | so a barcode or order number finds the activity that touched it.
    */
    'ref_columns' => [
        'barcode', 'orderno', 'orderid', 'delivery_barcode', 'loadnumber',
        'waybill', 'invoiceno', 'proforma_no', 'sub_barcode', 'reel_barcode',
        'code', 'username', 'productname', 'customername', 'name', 'title', 'label', 'key',
    ],

    /*
    | Columns that say who entered a legacy row. When a record was created
    | before the activity log existed, its creator comes from here, so "your
    | entry was changed" still reaches them.
    */
    'creator_columns' => ['created_by', 'user', 'username', 'user_name', 'createdby'],

    /* Nicer names for tables, "database.table" => singular label. */
    'subjects' => [
        'bil.rawmaterials_supplier_deliveries' => 'supplier delivery',
        'bil.rawmaterials_warehouse_entry' => 'raw material in store',
        'bil.rawmaterials_warehouse_exit' => 'raw material exit',
        'bil.factory_entrance_rawmaterials' => 'factory entrance',
        'bil.factory_usage_rawmaterials' => 'consumption',
        'bil.return_approval' => 'factory return',
        'bil.damagedgoods_rawmaterial' => 'damaged-goods report',
        'bil.rawmaterials_transfer' => 'stock transfer',
        'bil.rawmaterials_products' => 'raw material product',
        'bil.rawmaterials_supplier' => 'supplier',
        'bil.factory_conversion' => 'conversion pallet',
        'bil.factory_exit' => 'factory exit',
        'bil.finished_goods_warehouse_receipts' => 'warehouse receipt',
        'bil.sales_loading' => 'loading line',
        'bil.products' => 'product',
        'bpl.bpl_sales' => 'sales order',
        'bpl.bpl_delivery' => 'delivery',
        'bpl.bpl_delivery_details' => 'delivered roll',
        'bpl.bpl_delivery_barcode' => 'waybill',
        'core.user' => 'user',
        'core.roles' => 'role',
        'core.role_has_permissions' => 'role permission',
        'core.model_has_roles' => 'user role',
        'core.pages' => 'page',
        'core.app_settings' => 'setting',
        'core.shift_windows' => 'shift window',
    ],

    /*
    | What a component method is called in the feed. A method not listed reads
    | as what its changes did ("Created", "Updated", …) or its own name.
    */
    'verbs' => [
        'save' => 'Saved', 'store' => 'Saved', 'submit' => 'Submitted',
        'create' => 'Created', 'update' => 'Updated',
        'delete' => 'Deleted', 'deleteConfirmed' => 'Deleted', 'destroy' => 'Deleted',
        'approve' => 'Approved', 'reject' => 'Rejected',
        'receive' => 'Received', 'cancel' => 'Cancelled', 'confirm' => 'Confirmed',
        'generate' => 'Generated', 'restore' => 'Restored',
    ],

    /*
    | Notifications.
    |
    | Admins get ADMIN notifications only: a change made on an Admin or
    | Settings page by someone else, and a run of failed sign-ins.
    |
    | Everyone else gets two kinds, and only for pages they can open:
    |   approval     — something was submitted that they can approve;
    |   your_record  — someone else changed or deleted a record they entered.
    */
    'admin_modules' => ['Admin', 'Settings'],

    'failed_logins' => ['threshold' => 5, 'minutes' => 15],

    /*
    | page + method that SUBMITS something for approval, and the ability that
    | approves it (on the same page unless `notify_page` says otherwise).
    */
    'approvals' => [
        ['page' => 'bil.raw_materials.factory_returns', 'methods' => ['save'],
         'ability' => 'approve', 'label' => 'Factory return to approve'],
        ['page' => 'bil.raw_materials.damaged_goods', 'methods' => ['save'],
         'ability' => 'approve', 'label' => 'Damaged goods to approve'],
        ['page' => 'bil.finished_goods.stock_transfer_receive', 'methods' => ['receive'],
         'ability' => 'approve', 'label' => 'Received transfer to approve'],
    ],
];
