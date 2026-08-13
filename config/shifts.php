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
|
| Add a context here when the page that uses it is built.
*/

$dayNight = [
    ['name' => 'Day', 'start' => '07:00', 'end' => '19:00'],
    ['name' => 'Night', 'start' => '19:00', 'end' => '07:00'],
];

return [
    'contexts' => [
        ['key' => 'bil.raw_materials.factory_entrance', 'label' => 'BIL Raw Materials Factory Entrance', 'module' => 'BIL', 'windows' => $dayNight],
        ['key' => 'bil.raw_materials.consumption',      'label' => 'BIL Raw Materials Consumption',      'module' => 'BIL', 'windows' => $dayNight],

        /*
        | Conversion. These two do more than gate a page: their windows define
        | what "day" and "night" MEAN for a converting line, which is what the
        | production date and the waste run are keyed on. Editing the times here
        | moves the boundary for both screens at once, so they stay in agreement.
        |
        | The window NAMES matter as well as the times — a window is matched to
        | the shift value stored in `factory_conversion.shift` by lowercasing its
        | name, so Day/Night must keep those names. Renaming one falls back to
        | the built-in 07:00/19:00 boundary rather than writing a value the
        | legacy app cannot read. See ConversionWaste::shiftWindows().
        */
        ['key' => 'bil.finished_goods.conversion_output', 'label' => 'BIL Conversion Output', 'module' => 'BIL', 'windows' => $dayNight],
        ['key' => 'bil.finished_goods.conversion_waste',  'label' => 'BIL Conversion Waste',  'module' => 'BIL', 'windows' => $dayNight],
    ],
];
