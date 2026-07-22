<?php

/*
| Registry of DataGrid pages. `gds:sync-data-views` reads each class's
| declared views() and upserts data_pages / data_views (preserving admin
| overrides). Add each concrete DataGrid component here as it is built.
*/

return [
    'grids' => [
        \Modules\Core\Livewire\Admin\Companies::class,
        \Modules\Core\Livewire\Admin\Departments::class,
        \Modules\Core\Livewire\Admin\Permissions::class,
        \Modules\Core\Livewire\Admin\Roles::class,
        \Modules\Core\Livewire\Admin\Users::class,
    ],
];
