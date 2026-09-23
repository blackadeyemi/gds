<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store the BPL sales order number instead of recomputing it.
 *
 * Every legacy BPL screen that shows an order — proforma, packing list,
 * invoice metrics, the sales metrics — builds its number on the fly:
 *
 *     REPLACE(CONCAT('BPL/', countries.iso, '/', customerlabel, '/', ref), ' ', '')
 *
 * e.g. `BPL/GH/ACIPAC/063`. Recomputed from the CUSTOMER's current label and
 * country, so renaming a customer's label, or correcting its country,
 * silently renumbers every order it has ever placed — including ones whose
 * proforma was printed and sent under the old number.
 *
 * gds now generates the number once, when the order is placed, and keeps it in
 * `orderno`. Backfilled here with exactly the value the legacy formula gives
 * today, so nothing that is already printed disagrees with the stored number.
 * The legacy screens keep computing theirs and will match until somebody edits
 * a customer label — from then on, the stored one is the one that is right.
 *
 * UNIQUE: checked before adding — no two of the 411 existing orders compute to
 * the same number. One order (id 464, ref 001) names customer 165, which does
 * not exist, so it has no label or country and stays NULL; NULLs do not
 * collide under a unique index.
 *
 * Also indexes `bpl_sales_items.order_id`, which had none: every order's
 * lines were being found by a scan.
 *
 * `bil.bpl_sales` is a compat view with an explicit column list, frozen at the
 * old shape, so it is recreated to expose `orderno`.
 */
return new class extends Migration
{
    private const UNIQUE = 'bs_orderno_unq';
    private const ITEMS_INDEX = 'bsi_order_idx';

    private const COLUMNS = ['id', 'ref', 'orderno', 'username', 'customerid', 'company', 'date',
        'created_at', 'updated_at', 'deleted_at'];

    public function up(): void
    {
        if (! Schema::connection('bpl')->hasColumn('bpl_sales', 'orderno')) {
            DB::connection('bpl')->statement('ALTER TABLE `bpl_sales` ADD COLUMN `orderno` VARCHAR(50) NULL AFTER `ref`');
        }

        DB::connection('bpl')->statement(
            "UPDATE `bpl_sales` s
               JOIN `bpl_customers` c ON c.`id` = s.`customerid`
               JOIN `countries` co ON co.`name` = c.`customercountry`
                SET s.`orderno` = REPLACE(CONCAT('BPL/', co.`iso`, '/', c.`customerlabel`, '/', s.`ref`), ' ', '')
              WHERE s.`orderno` IS NULL
                AND c.`customerlabel` IS NOT NULL AND c.`customerlabel` <> ''"
        );

        if (! $this->hasIndex('bpl_sales', self::UNIQUE)) {
            DB::connection('bpl')->statement('ALTER TABLE `bpl_sales` ADD UNIQUE INDEX `' . self::UNIQUE . '` (`orderno`)');
        }

        if (! $this->hasIndex('bpl_sales_items', self::ITEMS_INDEX)) {
            DB::connection('bpl')->statement('ALTER TABLE `bpl_sales_items` ADD INDEX `' . self::ITEMS_INDEX . '` (`order_id`)');
        }

        $this->bilView(self::COLUMNS);
    }

    public function down(): void
    {
        $this->bilView(array_values(array_diff(self::COLUMNS, ['orderno'])));

        foreach ([['bpl_sales', self::UNIQUE], ['bpl_sales_items', self::ITEMS_INDEX]] as [$table, $index]) {
            if ($this->hasIndex($table, $index)) {
                DB::connection('bpl')->statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
            }
        }

        if (Schema::connection('bpl')->hasColumn('bpl_sales', 'orderno')) {
            DB::connection('bpl')->statement('ALTER TABLE `bpl_sales` DROP COLUMN `orderno`');
        }
    }

    private function bilView(array $columns): void
    {
        $select = implode(', ', array_map(fn ($c) => "`bpl`.`bpl_sales`.`{$c}` AS `{$c}`", $columns));

        DB::connection('bil')->statement(
            "CREATE OR REPLACE VIEW `bil`.`bpl_sales` AS SELECT {$select} FROM `bpl`.`bpl_sales`"
        );
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::connection('bpl')->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]) !== [];
    }
};
