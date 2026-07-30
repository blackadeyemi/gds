<?php

/*
| Registry of shift contexts — the shift-gated areas of the app. Each page
| that enforces shifts declares its key via shiftKey(); this registry provides
| the label/module and the DEFAULT windows used only when a context is first
| created. `gds:sync-shift-contexts` upserts these rows and preserves any
| admin edits (is_active, window times/enabled) on contexts that already exist.
|
| A context is ungated (open anytime) until an admin flips is_active on in the
| Shift Settings UI. Windows may wrap midnight (start after end).
*/

$dayNight = [
    ['name' => 'Day', 'start' => '07:00', 'end' => '19:00'],
    ['name' => 'Night', 'start' => '19:00', 'end' => '07:00'],
];

return [
    'contexts' => [
        // BIL
        ['key' => 'bil.factory_entrance', 'label' => 'Factory Entrance', 'module' => 'BIL', 'windows' => $dayNight],
        ['key' => 'bil.production',       'label' => 'Factory Production', 'module' => 'BIL', 'windows' => $dayNight],
        ['key' => 'bil.consumption',      'label' => 'Consumption',       'module' => 'BIL', 'windows' => $dayNight],

        // BPL
        ['key' => 'bpl.production',       'label' => 'BPL Production',    'module' => 'BPL', 'windows' => $dayNight],
        ['key' => 'bpl.store_exit',       'label' => 'BPL Store Exit',    'module' => 'BPL', 'windows' => $dayNight],
    ],
];
