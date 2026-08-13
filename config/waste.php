<?php

/*
| Conversion waste.
|
| CONFIRMATION START is the only setting that really matters. Runs are derived
| from production, and there are 1.2M historic pallets with no waste recorded
| against them — so a rule of "the previous run must be confirmed" applied to
| all of history would block every line on day one and never let go.
|
| Waste confirmation therefore applies only to production from this date
| onwards. Anything earlier is history: visible in the reports, never a blocker.
| Set WASTE_CONFIRMATION_START in .env per environment (the day the feature goes
| live there); the fallback is deliberately a fixed date rather than now(), so
| the boundary cannot quietly move every time the app boots.
*/

return [

    'confirmation_start' => env('WASTE_CONFIRMATION_START', '2026-08-13'),

    /*
    | Shifts a run can belong to, in production order. A production day runs
    | 07:00 to 06:59 the next morning, so the small hours belong to the previous
    | date's night — mirroring the legacy functions/production_date.php, which
    | the Conversion Output screen still follows.
    */
    'shifts' => [
        'day' => ['label' => 'Day', 'start' => '07:00', 'end' => '19:00'],
        'night' => ['label' => 'Night', 'start' => '19:00', 'end' => '07:00'],
    ],

    /*
    | How many open runs the entry screen offers at once. A backlog longer than
    | this is a process problem, not a paging problem — the list is ordered
    | oldest-first so the one that must be cleared is always at the top.
    */
    'open_run_limit' => 100,
];
