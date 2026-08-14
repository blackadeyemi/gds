<?php

/*
| BIL module settings that vary per environment.
*/

return [
    /*
    | The company this module's data belongs to, matched on companies.code.
    | Scopes the factory hierarchy so BIL screens never offer another company's
    | factories (PM2/PM3 are Belpapyrus).
    */
    'company_code' => 'BIL',

    /*
    | The BPL customer this company buys its jumbo rolls as — `customer_id` on
    | bpl_production ("Belimpex Limited"). The reels arriving at a BIL factory
    | gate are exactly the ones produced against it, so it decides which
    | barcodes the Jumbo Rolls → Factory Entrance scanner accepts.
    */
    'jumbo_roll_customer_id' => env('BIL_JUMBO_ROLL_CUSTOMER_ID', 17),

    /*
    | Where quality-control product photos live. The legacy Quality Control
    | dashboard writes them to <STORAGE_PATH>/QC/Pics (production STORAGE_PATH
    | is E:/), and products.imagepath holds only the bare filename. Point this
    | at that same folder in production and both apps stay in sync; the local
    | default keeps uploads inside the app's own storage.
    */
    // (?: rather than an env() default so an empty entry in .env still falls
    // back instead of resolving to the app root.)
    'qc_pics_path' => env('BIL_QC_PICS_PATH') ?: storage_path('app/qc-pics'),
];
