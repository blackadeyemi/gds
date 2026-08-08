<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The finished-goods stock aggregates, kept in step with warehouse receipts.
 *
 * Receiving a pallet does not just record the receipt — it moves stock. Two
 * running totals have to move with it, both shared live with the legacy app:
 *
 *   storebundle        bundles per product across the whole warehouse ('01'),
 *                      plus a `modifications` JSON audit trail.
 *   storebundle_floor  bundles per product per floor.
 *
 * This is the gds equivalent of the legacy `Bil\Stock\finishGoods` trait, which
 * the store-entrance screen mixed in. It lives in one place because receiving
 * and un-receiving must be exact mirrors: if the two ever disagree the totals
 * drift, and nothing recomputes them from the receipts.
 *
 * Bundles are signed — positive receives, negative reverses. Totals are NOT
 * clamped at zero, matching the legacy behaviour (the live data has a negative
 * floor row, and clamping would hide a real reconciliation problem rather than
 * fix it).
 */
class FinishedGoodsStock
{
    /** The only warehouse code the finished-goods store uses. */
    public const WAREHOUSE = '01';

    /**
     * Which floor a gate feeds.
     *
     * Reproduced verbatim from the legacy trait, hard-coded location string and
     * all. This mapping is a contract shared with the legacy app: both write the
     * same `storebundle_floor` rows, so any difference silently splits a
     * product's floor totals between the two apps. If a gate is ever added,
     * change BOTH apps together.
     */
    public const FLOOR_B_LOCATION = 'FG Store FB Elevator 1';

    /**
     * Apply a signed bundle movement for a product received at a gate.
     *
     * Call inside the caller's transaction — the receipt row and the totals must
     * commit or roll back together.
     */
    public static function apply(
        int $productid,
        int $bundles,
        string $entrancelocation,
        string $username,
        int $timestamp
    ): void {
        if ($productid <= 0 || $bundles === 0) {
            return;
        }

        self::applyToBundle($productid, $bundles, $username, $timestamp);
        self::applyToFloor($productid, $bundles, $entrancelocation);
    }

    /** Warehouse-wide total for the product, with its audit entry. */
    protected static function applyToBundle(int $productid, int $bundles, string $username, int $timestamp): void
    {
        $conn = DB::connection('bil');

        $entry = json_encode([
            'user' => $username,
            'bundles' => $bundles,
            'last_modified' => $timestamp,
        ]);

        $exists = $conn->table('storebundle')
            ->where('warehousecode', self::WAREHOUSE)
            ->where('productid', $productid)
            ->exists();

        if ($exists) {
            $conn->update(
                "UPDATE `storebundle`
                    SET `bundles` = `bundles` + ?,
                        `modifications` = JSON_ARRAY_APPEND(`modifications`, '$', CAST(? AS JSON))
                  WHERE `warehousecode` = ? AND `productid` = ?",
                [$bundles, $entry, self::WAREHOUSE, $productid]
            );

            return;
        }

        // First ever movement for this product. The legacy trait seeded
        // `modifications` with a bare JSON object, which JSON_ARRAY_APPEND then
        // auto-wraps on the next update; seeding a one-element array instead
        // gives the same result from the first write and a consistent shape.
        // Nothing reads the column — it is a write-only audit trail — so this
        // cannot affect the legacy app.
        $conn->insert(
            "INSERT INTO `storebundle` (`warehousecode`, `productid`, `bundles`, `timestamp`, `modifications`)
             VALUES (?, ?, ?, ?, CAST(? AS JSON))
             ON DUPLICATE KEY UPDATE
                 `bundles` = `bundles` + ?,
                 `modifications` = JSON_ARRAY_APPEND(`modifications`, '$', CAST(? AS JSON))",
            [self::WAREHOUSE, $productid, $bundles, (string) $timestamp, '[' . $entry . ']',
                $bundles, $entry]
        );
    }

    /** Per-floor total for the product. */
    protected static function applyToFloor(int $productid, int $bundles, string $entrancelocation): void
    {
        $floorId = self::floorId($entrancelocation);

        // Refuse rather than skip. Nothing recomputes these totals from the
        // receipts, so a receipt saved without its floor movement is permanent
        // silent drift — better to fail the whole save and have someone fix
        // `store_floors`.
        if ($floorId === 0) {
            throw new RuntimeException(
                "No store floor is configured for entrance location \"{$entrancelocation}\"."
            );
        }

        DB::connection('bil')->insert(
            "INSERT INTO `storebundle_floor` (`floor_id`, `product_id`, `bundles`)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `bundles` = `bundles` + ?",
            [$floorId, $productid, $bundles, $bundles]
        );
    }

    /** Floor a gate feeds: the FB elevator is floor b, everything else floor c. */
    public static function floorId(string $entrancelocation): int
    {
        $name = $entrancelocation === self::FLOOR_B_LOCATION ? 'floor b' : 'floor c';

        return (int) DB::connection('bil')->table('store_floors')
            ->where('floor_name', $name)->value('id');
    }
}
