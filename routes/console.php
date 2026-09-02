<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Finished-goods stock: refresh the cached totals from the movements.
|
| `finished_goods_warehouse_stock` is a CACHE. Receipts, adjustments and
| transfers move it as they happen, but goods LEAVING are derived rather than
| mirrored — FinishedGoodsStock::loadedSinceCutover() reads `sales_loading`
| directly, because the legacy loading screen writes that table too and
| mirroring from gds alone would miss half of it.
|
| Nothing therefore takes a loading off the cached total until this runs. Left
| unscheduled it drifted by exactly the day's dispatch, and the Warehouse Stock
| page showed goods that had already gone out.
|
| --fix writes each row to what the movements say; it is idempotent and does
| not create adjustments, so running it twice does nothing the second time.
|
| ⚠️ Needs `php artisan schedule:run` every minute from cron / Task Scheduler.
| Without that entry on the server this file does nothing at all.
*/
Schedule::command('bil:reconcile-fg-stock --fix')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();
