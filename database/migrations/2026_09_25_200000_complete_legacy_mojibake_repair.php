<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finishes the repair `2026_09_25_190000` started and got wrong.
 *
 * That migration looked for a literal `Ã` or `Â` and found 152 notes. Those
 * are only the mojibake of `Ã`/`Â` themselves — the damage also reads as `â`,
 * `€`, `™`, `•` and a dozen other characters, and 2,451 more notes carry it.
 * `Op2â€”back pusher` (an em-dash) was sitting in plain sight the whole time.
 *
 * ## Naming the characters is the wrong approach
 *
 * There is no useful list: ANY byte of a UTF-8 sequence misread as latin1
 * becomes some character in U+0080–U+00FF, so the set is the whole upper half
 * of cp1252. What identifies the damage is not which characters are present
 * but that the text SURVIVES A DECODE ROUND — which correctly-encoded text
 * cannot. Four conditions, together:
 *
 *   1. it holds something outside ASCII at all;
 *   2. reading its bytes back as latin1 yields valid UTF-8 (correct text does
 *      not: `°` becomes the lone byte B0, which is not valid UTF-8, and MySQL
 *      returns NULL);
 *   3. that decode CHANGES the text; and
 *   4. the trip through latin1 is LOSSLESS.
 *
 * ## Condition 4 is what stops it eating good text
 *
 * Without it the repair runs twice and destroys what it fixed. `2â°` decodes
 * to `2⁰`, which is right — and `⁰` (U+2070) has no latin1 form, so a second
 * round turns it into `2?`. Because that `?` is valid UTF-8, conditions 2 and
 * 3 are both satisfied and 501 rows would have been silently mangled. The
 * lossless test refuses them, which also makes this migration idempotent:
 * once repaired, a row can never match again.
 *
 * Verified before writing: 0 of the 2,451 are lossy on the first round, and
 * the 152 that `190000` already repaired now fail condition 2, so they are
 * left alone.
 *
 * `bpl_customers` 131 stays unrepaired — see 190000 for why the UNIQUE index
 * makes it a merge decision rather than an encoding one.
 */
return new class extends Migration
{
    private const TABLE = 'factory_machine_maintenance';

    /** Reading the text's bytes back as latin1 and relabelling them utf8mb4. */
    private const DECODE = 'CONVERT(BINARY(CONVERT(`note` USING latin1)) USING utf8mb4)';

    /** The four conditions, together. See the class docblock. */
    private const DAMAGED = '`note` <> CONVERT(`note` USING ascii)'
        . ' AND ' . self::DECODE . ' IS NOT NULL'
        . ' AND HEX(' . self::DECODE . ') <> HEX(`note`)'
        . ' AND HEX(CONVERT(CONVERT(`note` USING latin1) USING utf8mb4)) = HEX(`note`)';

    public function up(): void
    {
        if (! Schema::connection('bil')->hasTable(self::TABLE)) {
            return;
        }

        $db = DB::connection('bil');

        $before = (int) $db->selectOne(
            'SELECT COUNT(*) c FROM `' . self::TABLE . '` WHERE ' . self::DAMAGED
        )->c;

        $done = $db->update(
            'UPDATE `' . self::TABLE . '` SET `note` = ' . self::DECODE
            . ' WHERE ' . self::DAMAGED
        );

        $left = (int) $db->selectOne(
            'SELECT COUNT(*) c FROM `' . self::TABLE . '` WHERE ' . self::DAMAGED
        )->c;

        echo "  repaired {$done} of {$before} double-encoded note(s); {$left} still matching\n";
    }

    /**
     * Not reversible, deliberately.
     *
     * Re-encoding would have to know which rows this migration touched, and
     * the only signature left is "text that could be re-damaged" — which is
     * every row holding a latin1-representable character, including ones that
     * were correct all along. `190000`'s own down() has the same limit. The
     * forward repair is guarded and idempotent; recovery from a bad run is a
     * restore, not a rollback.
     */
    public function down(): void
    {
        echo "  nothing undone — see the class docblock\n";
    }
};
