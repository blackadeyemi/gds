<?php

/*
| BHN module settings. BHN (Abuja) runs the SAME screens as BIL (the Bil module
| is reused, with the `bil` connection rebound to `bhn` per request — see
| Modules\Core\Http\Middleware\ResolveCompanyModule), so this mirrors config/bil.php
| with BHN's own identity.
*/

return [
    // The company this module's data belongs to (companies.code). Scopes the
    // factory hierarchy to BHN's factory/lines.
    'company_code' => 'BHN',

    // BHN buys its jumbo rolls from BPL as customer "BHN" — bpl_customers id 123
    // (BIL is 17). Decides which reel barcodes the Jumbo Rolls scanner accepts.
    'jumbo_roll_customer_id' => env('BHN_JUMBO_ROLL_CUSTOMER_ID', 123),

    // QC product photos for BHN (its own folder; mirrors config/bil.php).
    'qc_pics_path' => env('BHN_QC_PICS_PATH') ?: storage_path('app/qc-pics-bhn'),
];
