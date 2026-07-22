<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Livewire\Dashboard;

/*
| Root routes. Auth + module routes are registered by ModuleServiceProvider
| (routes/core.php, routes/bil.php, routes/bpl.php). This file holds only
| the authenticated landing page for now.
*/

Route::get('/', Dashboard::class)->middleware('auth')->name('dashboard');
