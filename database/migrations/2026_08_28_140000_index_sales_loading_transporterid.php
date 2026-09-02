<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index `sales_loading.transporterid`.
 *
 * Same hole as `sales_order.customerid` was: `sales_loading` indexes `sod_id`,
 * `loadnumber` and `dateofloading`, but not the transporter — even though "has
 * this transporter ever carried anything?" is exactly what the Transporters
 * screen must ask before it will let one be deleted. Without it, counting
 * loadings per transporter scans all 642k rows once per row on the page.
 *
 * Legacy gains too: `report_sales_loading_transporter.php` groups on this
 * column.
 */
return new class extends Migration
{
    protected const INDEX = 'sl_transporterid_idx';

    public function up(): void
    {
        if (! $this->hasIndex()) {
            DB::connection('bil')->statement(
                'ALTER TABLE `sales_loading` ADD INDEX `' . self::INDEX . '` (`transporterid`)'
            );
        }
    }

    public function down(): void
    {
        if ($this->hasIndex()) {
            DB::connection('bil')->statement('ALTER TABLE `sales_loading` DROP INDEX `' . self::INDEX . '`');
        }
    }

    protected function hasIndex(): bool
    {
        return DB::connection('bil')
            ->select('SHOW INDEX FROM `sales_loading` WHERE Key_name = ?', [self::INDEX]) !== [];
    }
};
