<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Quarantine" becomes the WAITING AREA.
 *
 * The two tables record rolls that have left the factory but have not been
 * received into a store yet, and are parked somewhere in the meantime. Nothing
 * about that is a quarantine — the rolls are not held back for a quality
 * reason, they are simply waiting — and the screens are being built under the
 * name the floor uses.
 *
 *   `bpl_quarantine`            -> `bpl_waiting_area_entry`
 *   `bpl_quarantine_storeexit`  -> `bpl_waiting_area_exit`
 *
 * The old names stay as `SELECT *` views on both `bpl` and `bil`, the way the
 * store → warehouse rename did it (2026_09_16_130000), so the legacy
 * `bpl_quarantine.php` screens keep reading and writing the same rows.
 *
 * The places stay the stores themselves (`bpl_stock_locations` type 1), as the
 * legacy had it: a roll in the waiting area has come back off a truck, and its
 * weight goes back onto that store's stock until it is released again.
 *
 * Only ever one row was written — barcode 21-07-01-M3-039, entered and deleted
 * the same day in October 2025 — so nothing here is at risk.
 */
return new class extends Migration
{
    /** new name => old name */
    private const RENAMES = [
        'bpl_waiting_area_entry' => 'bpl_quarantine',
        'bpl_waiting_area_exit' => 'bpl_quarantine_storeexit',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $new => $old) {
            if (Schema::connection('bpl')->hasTable($new)) {
                continue;
            }

            DB::connection('bpl')->statement("RENAME TABLE `{$old}` TO `{$new}`");
            $this->compatView('bpl', $old, $new);
            $this->compatView('bil', $old, $new);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $new => $old) {
            if (! Schema::connection('bpl')->hasTable($new)) {
                continue;
            }

            DB::connection('bpl')->statement("DROP VIEW IF EXISTS `bpl`.`{$old}`");
            DB::connection('bpl')->statement("DROP VIEW IF EXISTS `bil`.`{$old}`");
            DB::connection('bpl')->statement("RENAME TABLE `{$new}` TO `{$old}`");
            $this->compatView('bil', $old, $old);
        }
    }

    /** `{$schema}`.`{$name}` = SELECT * FROM `bpl`.`{$base}`. */
    private function compatView(string $schema, string $name, string $base): void
    {
        DB::connection('bpl')->statement("DROP VIEW IF EXISTS `{$schema}`.`{$name}`");
        DB::connection('bpl')->statement(
            "CREATE VIEW `{$schema}`.`{$name}` AS SELECT * FROM `bpl`.`{$base}`"
        );
    }
};
