<?php

use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;
use Modules\Core\Providers\MachineMapsServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    MachineMapsServiceProvider::class,
];
