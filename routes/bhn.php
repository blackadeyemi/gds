<?php

use Illuminate\Support\Facades\Route;

/*
| BHN module routes — served under /bhn, route names bhn.*.
|
| BHN (core.companies id 4) is a third company based in Abuja that operates
| like BIL: it converts hardroll into finished goods and receives jumbo rolls
| from BPL. Its schema is modelled from BHN's own legacy dump, and in
| production it is homed to the Abuja server (see the planned db_routing /
| routing page — memory: gds-abuja-multiserver).
|
| Screens are added here as the module is built.
*/

Route::middleware('auth')->group(function () {
    //
});
