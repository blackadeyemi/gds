<?php

return [

    /*
    |--------------------------------------------------------------------------
    | "Needs attention" sources
    |--------------------------------------------------------------------------
    |
    | Classes with a static items() method declaring the dashboard's attention
    | cards for their module (see Modules\Core\Support\Attention for the item
    | shape). A user sees an item only with the ability it is gated on.
    |
    */

    'attention' => [
        Modules\Bil\Support\DashboardAttention::class,
        Modules\Bpl\Support\DashboardAttention::class,
    ],

];
