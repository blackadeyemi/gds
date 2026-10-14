<?php

/*
| Which operational schemas carry the legacy name->id resolver tables
| (machine_map_*). Each has the BEFORE INSERT/UPDATE triggers that read its OWN
| machine_map_* (bare table name resolves in that schema), so the maps are built
| per-connection from the shared `core` hierarchy. BIL and the BHN clone both
| run the legacy triggers; add future company schemas here.
*/

return [
    'connections' => ['bil', 'bhn'],
];
