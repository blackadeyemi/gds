<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `bpl_delivery_details.barcode` was latin1. Every other barcode column in the
 * BPL schema is utf8mb3, so joining a delivery line to the roll it carried —
 *
 *     bpl_delivery_details.barcode = bpl_production.barcode
 *
 * — compared two different character sets. MySQL still answers that correctly,
 * by coercing one side, but a coerced column cannot use its index: the join
 * degrades to a full scan of all 23,885 delivery lines PER ROLL, and the index
 * `bdd_barcode_idx` that exists for exactly this join was never once used.
 *
 * Nobody noticed because every screen that made this join already knew the
 * waybill and so was scanning a handful of rows. The Roll Trail report is the
 * first thing to ask it roll-first, and a quarter of production took 77
 * seconds — 9,000 rolls x a 24,000-row scan.
 *
 * The values are pure ASCII (verified: 0 rows outside 0x20–0x7E, longest 15
 * characters), so latin1 and utf8mb3 encode them to identical bytes and the
 * conversion cannot change a single barcode. It fixes the legacy
 * `bpl_delivery_printout` joins too, which carry the same mismatch.
 *
 * Only `barcode` moves. `delivery_barcode` and `bpl_delivery_id` join to
 * `bpl_delivery_barcode` and `bpl_delivery`, which are latin1 themselves, so
 * those three agree already and converting one of them would break the match
 * this one repairs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('bpl')->hasTable('bpl_delivery_details')) {
            return;
        }

        if ($this->charset() === 'utf8mb3') {
            return;
        }

        // Guard the assumption the conversion rests on, rather than trusting
        // that today's data is still tomorrow's.
        $odd = (int) DB::connection('bpl')->table('bpl_delivery_details')
            ->whereRaw("`barcode` REGEXP '[^ -~]'")->count();

        if ($odd > 0) {
            throw new RuntimeException(
                "bpl_delivery_details.barcode holds {$odd} non-ASCII value(s); converting the "
                . 'character set could change them. Inspect those rows before running this.'
            );
        }

        DB::connection('bpl')->statement(
            'ALTER TABLE `bpl_delivery_details` MODIFY `barcode` VARCHAR(255)'
            . ' CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL'
        );
    }

    public function down(): void
    {
        if (! Schema::connection('bpl')->hasTable('bpl_delivery_details') || $this->charset() === 'latin1') {
            return;
        }

        DB::connection('bpl')->statement(
            'ALTER TABLE `bpl_delivery_details` MODIFY `barcode` VARCHAR(255)'
            . ' CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL'
        );
    }

    private function charset(): ?string
    {
        return DB::connection('bpl')->selectOne(
            'SELECT character_set_name AS cs FROM information_schema.columns'
            . " WHERE table_schema = DATABASE() AND table_name = 'bpl_delivery_details'"
            . " AND column_name = 'barcode'"
        )?->cs;
    }
};
