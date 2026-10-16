<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bill of lading documents
    |--------------------------------------------------------------------------
    |
    | The scanned bill of lading for each shipment. The legacy app keeps them
    | as loose files in "<STORAGE_PATH>/Bill of Lading/", and the database
    | stores only the filename — so pointing this at that folder lets both
    | apps open the same 279 documents. Left unset, gds keeps its own folder.
    |
    */
    'bl_docs_path' => env('BPL_BL_DOCS_PATH') ?: storage_path('app/bpl-bill-of-lading'),

];
