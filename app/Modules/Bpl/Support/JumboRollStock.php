<?php

namespace Modules\Bpl\Support;

use Illuminate\Support\Facades\DB;

/**
 * Stock held in the BPL warehouses: `bpl_hardroll_stock` for hardrolls,
 * `bpl_softroll_stock` for softrolls.
 *
 * ⚠️ **These are MAINTAINED aggregates, not derived ones.** Nothing recomputes
 * them from the store-entrance rows, so a movement that is missed — or a delete
 * that does not reverse — is permanent, undetectable drift. Every change goes
 * through apply() inside the caller's transaction, both directions.
 *
 * They stay the legacy aggregates rather than becoming a gds table of their own
 * because the flat PHP app still reads them (its BPL Stock and Stock Remaining
 * reports) and its own store exit still subtracts from them — it reaches them
 * through the `bpl_stock` / `softroll_stock` compat views. A second copy would
 * diverge the moment anybody used the old screen. Same call as `jumboreel_stock`
 * on the BIL side — see [[bil-jumbo-rolls]].
 *
 * **The two tables are keyed differently**, and that is not an oversight worth
 * "fixing" here:
 *
 *   bpl_hardroll_stock  UNIQUE (location_id, product_id)   — a hardroll product
 *   bpl_softroll_stock  UNIQUE (location_id, grade_id)     — a softroll GRADE
 *
 * Softroll stock predates `bpl_products_softroll`, so it aggregates by grade.
 * Re-keying it would rewrite the legacy reports too; leave it until they go.
 *
 * The legacy `Bpl\Stock::update()` did SELECT-then-UPDATE-or-INSERT, which
 * races two scanners into a duplicate-key error or a lost increment. This uses
 * one INSERT … ON DUPLICATE KEY UPDATE against the unique key instead.
 */
class JumboRollStock
{
    /**
     * Move one roll into or out of a warehouse.
     *
     * `$signedWeight` is positive receiving, negative reversing; the quantity
     * moves by one in the same direction, which is what the legacy did and what
     * its reports count.
     *
     * Call inside the caller's transaction: the movement row and this total
     * have to commit or fail together.
     *
     * @param  array  $roll  a Modules\Bpl\Support\RollResolver candidate
     */
    public static function apply(int $locationId, array $roll, float $signedWeight): void
    {
        $isSoft = ($roll['stream'] ?? null) === RollResolver::SOFTROLL;

        $key = $isSoft ? ($roll['grade_id'] ?? null) : ($roll['product_id'] ?? null);

        if ($key === null) {
            // A roll with no product (or no grade) has nothing to file the
            // stock under. Refusing is right: silently skipping would leave the
            // movement recorded and the total short, which is the exact drift
            // this class exists to prevent.
            throw new \RuntimeException(
                'Roll ' . ($roll['barcode'] ?? '?') . ' has no '
                . ($isSoft ? 'grade' : 'product') . ' — its stock cannot be filed.'
            );
        }

        $quantityStep = $signedWeight < 0 ? -1 : 1;

        $table = $isSoft ? 'bpl_softroll_stock' : 'bpl_hardroll_stock';
        $keyColumn = $isSoft ? 'grade_id' : 'product_id';

        // `bpl_hardroll_stock.quantity` is INT UNSIGNED and the server runs
        // STRICT_TRANS_TABLES, so taking a roll off a row already at zero
        // raises "Out of range value" and rolls the whole batch back. That
        // would block a legitimate release because of a discrepancy someone
        // else created: on 2026-09-16, 2 of the 2,629 rolls in store sat on a
        // row at quantity 0, against 2,626 that agreed.
        //
        // So the COUNT is floored at zero and the WEIGHT is not. Weight is a
        // signed double, so it goes negative and keeps the discrepancy visible
        // — clamping both would silently absorb it, which is how an aggregate
        // nothing recomputes quietly stops meaning anything.
        $quantityExpr = $quantityStep < 0
            ? 'GREATEST(CAST(`quantity` AS SIGNED) + ?, 0)'
            : '`quantity` + ?';

        DB::connection('bpl')->statement(
            "INSERT INTO `{$table}` (`location_id`, `{$keyColumn}`, `quantity`, `weight`) VALUES (?, ?, ?, ?) "
            . "ON DUPLICATE KEY UPDATE `quantity` = {$quantityExpr}, `weight` = `weight` + ?",
            [$locationId, $key, max($quantityStep, 0), $signedWeight, $quantityStep, $signedWeight]
        );
    }

    /** The current figures for one warehouse + key, or null when nothing is held. */
    public static function held(int $locationId, array $roll): ?object
    {
        $isSoft = ($roll['stream'] ?? null) === RollResolver::SOFTROLL;

        return DB::connection('bpl')
            ->table($isSoft ? 'bpl_softroll_stock' : 'bpl_hardroll_stock')
            ->where('location_id', $locationId)
            ->where($isSoft ? 'grade_id' : 'product_id', $isSoft ? $roll['grade_id'] : $roll['product_id'])
            ->first(['quantity', 'weight']);
    }
}
