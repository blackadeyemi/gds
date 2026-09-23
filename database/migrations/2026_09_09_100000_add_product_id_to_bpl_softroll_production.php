<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give a softroll production row a product, the way a hardroll row already has
 * one.
 *
 * `bpl_softroll_production` stored `grammage` and `diameter` as free text on
 * every roll, so the same physical product was recorded as '17', '17.0',
 * '240 ' and '3676' depending on who typed it. The BPL rebuild already
 * distilled those into a clean 19-row `bpl_products_softroll` catalog; this
 * points the rolls at it so the Production screen picks a product instead of
 * re-typing two loose strings.
 *
 * The legacy text columns are KEPT and still written by both apps — the flat
 * PHP app reads them on its list, its form and its label print-out, and the
 * `bil.bpl_softroll_production` compat view exposes them. This column is
 * additive: nothing is rewritten, so the backfill cannot lose a value.
 *
 * The backfill matches on the same evidence the catalog cleanup used
 * (bil/_migration/bpl_softroll_cleanup_remap.sql):
 *   - grammage/diameter compared NUMERICALLY, so '17.0' and '240 ' land on
 *     '17' and '240';
 *   - a diameter that is neither 240 nor 70 is a mis-key (id 206 recorded the
 *     weight, 3676, as the diameter) and resolves to 240 — except on EBT,
 *     whose one roll is a 10gsm/10d test entry with its own catalog row;
 *   - a blank grammage on PTN resolves to 25, the dominant PTN grammage. A
 *     blank on STN stays blank: 22 vs 25 is genuinely ambiguous there, and the
 *     catalog carries a "STN gsm 240d" row for it rather than a guess.
 * That covers all 1,708 rows. Anything a later import cannot match stays NULL
 * rather than being forced onto a wrong product.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('bpl')->hasColumn('bpl_softroll_production', 'product_id')) {
            DB::connection('bpl')->statement(
                'ALTER TABLE `bpl_softroll_production` ADD COLUMN `product_id` INT NULL'
            );
            DB::connection('bpl')->statement(
                'ALTER TABLE `bpl_softroll_production` ADD INDEX `bpl_softroll_production_product_idx` (`product_id`)'
            );
        }

        DB::connection('bpl')->statement(
            'UPDATE `bpl_softroll_production` s'
            . ' JOIN `bpl_grades` g ON g.`id` = s.`grade_id`'
            . ' JOIN `bpl_products_softroll` p'
            . '   ON p.`grade_id` = s.`grade_id`'
            . '  AND p.`deleted_at` IS NULL'
            . "  AND TRIM(p.`grammage`) + 0 = CASE WHEN g.`type` = 'PTN' AND TRIM(s.`grammage`) = ''"
            . '                                    THEN 25 ELSE TRIM(s.`grammage`) + 0 END'
            . "  AND TRIM(p.`diameter`) + 0 = CASE WHEN g.`type` <> 'EBT' AND TRIM(s.`diameter`) NOT IN ('240', '70')"
            . '                                    THEN 240 ELSE TRIM(s.`diameter`) + 0 END'
            . ' SET s.`product_id` = p.`id`'
            . ' WHERE s.`product_id` IS NULL'
        );

        // The bil-side compat view was created with an explicit column list, so
        // it is frozen at the old shape and would not expose the new column.
        $this->refreshCompatView(true);
    }

    /** Recreate `bil.bpl_softroll_production`, with or without the new column. */
    private function refreshCompatView(bool $withProductId): void
    {
        $columns = ['id', 'username', 'softrollnumber', 'grade_id', 'barcode', 'brightness',
            'weight', 'grammage', 'diameter', 'status', 'dateofmanufacture', 'deleted_at',
            'timestamp', 'papermachine'];

        if ($withProductId) {
            $columns[] = 'product_id';
        }

        $select = implode(', ', array_map(
            fn ($c) => "`bpl`.`bpl_softroll_production`.`{$c}` AS `{$c}`",
            $columns
        ));

        DB::connection('bil')->statement(
            "CREATE OR REPLACE VIEW `bil`.`bpl_softroll_production` AS SELECT {$select} FROM `bpl`.`bpl_softroll_production`"
        );
    }

    public function down(): void
    {
        $this->refreshCompatView(false);

        if (Schema::connection('bpl')->hasColumn('bpl_softroll_production', 'product_id')) {
            DB::connection('bpl')->statement('ALTER TABLE `bpl_softroll_production` DROP INDEX `bpl_softroll_production_product_idx`');
            DB::connection('bpl')->statement('ALTER TABLE `bpl_softroll_production` DROP COLUMN `product_id`');
        }
    }
};
