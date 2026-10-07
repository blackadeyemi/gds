<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One customer address is still double-encoded: `bpl_customers` id 150 reads
 * `LomÃ©-TOGO` where it should read `Lomé-TOGO`.
 *
 * The same fault the 2026-08-07 pass repaired
 * (`2026_08_07_100000_repair_legacy_mojibake`) and missed this row of. The
 * legacy `bil` never called `set_charset`, so UTF-8 pasted from Word went into
 * the column as its raw bytes and was then encoded AGAIN: `é` (U+00E9) becomes
 * `C3 A9`, and each of those bytes becomes its own UTF-8 character, `C3 83 C2
 * A9`.
 *
 * `bpl_customers` is the "encoded twice" shape, not the "wrong label" one —
 * the column is already utf8mb3 — so the repair is a DECODE round, not a
 * relabel: read the text back as latin1 bytes and reinterpret those bytes as
 * utf8mb3. The column keeps its character set, as the August pass decided for
 * these short varchars that legacy queries group and join on.
 *
 * ## Why only this row
 *
 * A scan of every BPL text column found exactly two rows left, and the other
 * one cannot be repaired here: `bpl_customers` id 131,
 * `SociÃ©tÃ© HygiÃ¨ne Plus Gabon`. `customername` carries a UNIQUE index and
 * `utf8mb3_general_ci` folds accents, so the repaired
 * `Société Hygiène Plus Gabon` collides with id 128,
 * `SOCIETE HYGIENE PLUS GABON` — the same company at the same address, entered
 * twice. Repairing it means merging two live customer records, which is a
 * business decision and not a migration's to take.
 *
 * Verify with `HEX(col) REGEXP 'C383|C382'`, never `LIKE '%Ã%'` — the
 * collation is accent-insensitive and that pattern matches every row
 * containing "a".
 */
return new class extends Migration
{
    private const ID = 150;

    private const WAS = 'LomÃ©-TOGO';

    private const NOW = 'Lomé-TOGO';

    public function up(): void
    {
        $this->rewrite(self::WAS, self::NOW);
    }

    public function down(): void
    {
        $this->rewrite(self::NOW, self::WAS);
    }

    /**
     * Matched on the value as well as the id, so a row somebody has already
     * corrected by hand — or replaced — is left alone.
     */
    private function rewrite(string $from, string $to): void
    {
        if (! Schema::connection('bpl')->hasTable('bpl_customers')) {
            return;
        }

        DB::connection('bpl')->table('bpl_customers')
            ->where('id', self::ID)
            ->where('customeraddress', $from)
            ->update(['customeraddress' => $to]);
    }
};
