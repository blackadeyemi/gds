<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tier-1 legacy teardown: drop the dead 2018-2019 jumbo-REEL store/exit route
 * and two other long-dead legacy tables, plus the bil/bpl compat VIEWS that
 * passed through to them. All verified: no foreign keys reference them, and no
 * runtime code queries them (the new BIL Jumbo Rolls module uses the LIVE
 * `jumboreel_stock` / `bpl_storeentrance`, which are deliberately NOT touched
 * here). Last real writes were 2019-2020.
 *
 * Reversible via the pre-drop archives (structure + data) written to
 * database/backups/tier1_*_<timestamp>.sql on the machine where this ran.
 * Before running on another environment, take the same archive first.
 */
return new class extends Migration
{
    /**
     * connection => ['views' => [...], 'tables' => [...]].
     * Views are dropped before the core base tables they reference.
     */
    private array $plan = [
        'bil' => [
            'views' => ['jumboreel_storeentrance', 'jumboreel_factoryexit', 'jumboreel_customers'],
            'tables' => [
                'jumboreel_storeexit',
                'jumboreel_storeexit_details',
                'jumboreel_storeentrance_details',
                'jumboreel_factoryexit_details',
                'jumboreel_products',
                'jumboreel_production_forecast',
                'jumboreel_production_forecast_history',
                'sales_delivery_daily',
                'rawmaterials_transfer',
            ],
        ],
        'bpl' => [
            'views' => ['jumboreel_storeentrance', 'jumboreel_factoryexit', 'jumboreel_customers'],
            'tables' => [],
        ],
        'core' => [
            'views' => [],
            'tables' => ['jumboreel_storeentrance', 'jumboreel_factoryexit', 'jumboreel_customers'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->plan as $connection => $sets) {
            $db = DB::connection($connection);
            foreach ($sets['views'] as $view) {
                $db->statement("DROP VIEW IF EXISTS `{$view}`");
            }
            foreach ($sets['tables'] as $table) {
                $db->statement("DROP TABLE IF EXISTS `{$table}`");
            }
        }
    }

    /**
     * One-way legacy cleanup — these tables held dead 2018-2020 data. To restore,
     * re-import the archives from database/backups/tier1_*.sql (structure + data,
     * taken immediately before the drop). No automated down() so a rollback can't
     * silently recreate empty shells and mask the loss.
     */
    public function down(): void
    {
        // Intentionally empty. See database/backups/tier1_*.sql to restore.
    }
};
