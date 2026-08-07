<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Repairs text the legacy app stored under the wrong label.
 *
 * The old PHP connected without ever setting a charset, so UTF-8 bytes pasted
 * from Word went straight into columns declared latin1. Nothing complained --
 * the bytes were fine, the label was wrong -- but any client that connects as
 * utf8mb4 (gds, and nowadays the legacy app too) expands each of those bytes
 * into its own character: a bullet's E2 80 A2 reads back as "a-EUR-cent".
 *
 * Two shapes of damage, so two repairs:
 *
 *  1. bil.factory_machine_maintenance.note -- 2,514 of 43,401 rows. The bytes
 *     are already correct UTF-8; only the column's label is wrong. Repaired by
 *     round-tripping the column through BLOB, which carries bytes across
 *     untouched, then re-declaring them utf8mb4. No row is rewritten, so rows
 *     the apps stored correctly are unaffected. The BLOB -> TEXT step is its
 *     own guard: MySQL refuses it if any row is not valid UTF-8, which is
 *     exactly the case where a blanket relabel would be wrong.
 *
 *  2. Seven rows in columns whose charset must NOT change -- short varchars
 *     that legacy queries group and join on, where widening the collation
 *     risks "illegal mix of collations". Those are rewritten byte-for-byte
 *     instead, from values computed once and recorded below.
 *
 * bpl_customers is a third variant: that column is already utf8mb3, so the
 * mojibake is stored as genuine characters and was encoded twice. It needs a
 * decode round rather than a relabel.
 *
 * Every row fix is an exact before -> after byte pair, so it is idempotent (a
 * row matching neither is left alone and reported) and down() is exact.
 */
return new class extends Migration
{
    /**
     * [connection, table, column, pk, damaged bytes, repaired bytes]
     *
     * The one lossy entry is the emoji in truckdriver: U+1F6A8 has no cp1252
     * form, and widening that column is what we are avoiding, so it is dropped.
     * The rows then read "POLICE", which is what the other 47 rows already say.
     */
    private const ROW_FIXES = [
        // "UBA PHARMACY & SUPERMARKET - ELEGANZA; Ashley's Place Mall, ..." (en-dash + curly apostrophe)
        ['bil', 'sales_customers', 'customeraddress', 1646,
            '55424120504841524D41435920262053555045524D41524B455420E2809320454C4547414E5A413B204173686C6579E280997320506C616365204D616C6C2C20627920456C6567616E7A61206275732073746F702C204C656B6B692D416A6168',
            '55424120504841524D41435920262053555045524D41524B4554209620454C4547414E5A413B204173686C6579927320506C616365204D616C6C2C20627920456C6567616E7A61206275732073746F702C204C656B6B692D416A6168'],

        // "POLICE" + siren emoji -> "POLICE"
        ['bil', 'sales_loading', 'truckdriver', 404993, '504F4C49434520F09F9AA8', '504F4C494345'],
        ['bil', 'sales_loading', 'truckdriver', 404994, '504F4C49434520F09F9AA8', '504F4C494345'],
        ['bil', 'sales_loading', 'truckdriver', 404995, '504F4C49434520F09F9AA8', '504F4C494345'],
        ['bil', 'sales_loading', 'truckdriver', 437039, '504F4C49434520F09F9AA8', '504F4C494345'],

        // "Societe Hygiene Plus Gabon" / "Lome-TOGO" -- doubly encoded, one round back.
        //
        // The customername fix collides with the UNIQUE index: customer 128 is
        // "SOCIETE HYGIENE PLUS GABON" at the same address, and utf8mb3_general_ci
        // treats e and e-acute as equal, so repairing 131 makes them the same key.
        // They are two records for one company -- both in active use (230 and 42
        // production rows) -- which is a merge decision for the business, not an
        // encoding repair. setBytes() reports the skip and moves on.
        ['bpl', 'bpl_customers', 'customername', 131,
            '536F6369C383C2A974C383C2A92048796769C383C2A86E6520506C7573204761626F6E',
            '536F6369C3A974C3A92048796769C3A86E6520506C7573204761626F6E'],
        ['bpl', 'bpl_customers', 'customeraddress', 150,
            '4C6F6DC383C2A92D544F474F', '4C6F6DC3A92D544F474F'],
    ];

    public function up(): void
    {
        $this->relabelNoteColumn();

        foreach (self::ROW_FIXES as [$conn, $table, $column, $pk, $damaged, $repaired]) {
            $this->setBytes($conn, $table, $column, $pk, $damaged, $repaired);
        }
    }

    public function down(): void
    {
        // Byte-preserving in both directions, so note returns to precisely the
        // bytes -- and the mojibake -- it had before. Notes written *after* this
        // migration get re-damaged, which is inherent to a mislabelled column.
        $db = DB::connection('bil');
        $db->statement('ALTER TABLE `factory_machine_maintenance` MODIFY `note` BLOB');
        $db->statement('ALTER TABLE `factory_machine_maintenance` MODIFY `note` TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci');

        foreach (self::ROW_FIXES as [$conn, $table, $column, $pk, $damaged, $repaired]) {
            // The dropped emoji cannot come back -- the column still cannot hold it.
            $this->setBytes($conn, $table, $column, $pk, $repaired, $damaged);
        }
    }

    /**
     * Carries note's bytes into a utf8mb4 column without rewriting a row:
     * TEXT -> BLOB drops the wrong label, BLOB -> TEXT applies the right one.
     */
    private function relabelNoteColumn(): void
    {
        $db = DB::connection('bil');

        $current = $db->selectOne(
            "SELECT CHARACTER_SET_NAME cs FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factory_machine_maintenance'
               AND COLUMN_NAME = 'note'"
        );

        if (! $current || $current->cs === 'utf8mb4') {
            return; // already repaired
        }

        // Fail before touching the table if any row would be mangled. Anything
        // not valid UTF-8 was stored correctly as cp1252 and a relabel would
        // corrupt it -- that needs a person, not a blanket ALTER.
        $bad = [];

        foreach ($db->select('SELECT id, HEX(note) hx FROM factory_machine_maintenance
                              WHERE note <> CONVERT(note USING ascii)') as $row) {
            if (! mb_check_encoding(hex2bin($row->hx), 'UTF-8')) {
                $bad[] = $row->id;
            }
        }

        if ($bad !== []) {
            throw new RuntimeException(
                'factory_machine_maintenance.note holds ' . count($bad) . ' row(s) that are not UTF-8 and would be '
                . 'corrupted by a relabel (ids: ' . implode(', ', array_slice($bad, 0, 20)) . '). Repair those rows '
                . 'individually first.'
            );
        }

        $db->statement('ALTER TABLE `factory_machine_maintenance` MODIFY `note` BLOB');
        $db->statement('ALTER TABLE `factory_machine_maintenance` MODIFY `note` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /**
     * Writes $to only if the row still holds $from. UNHEX() yields a binary
     * string, which lands in the column byte-for-byte -- no charset conversion
     * on the way in, which is the whole point.
     */
    private function setBytes(string $conn, string $table, string $column, int $pk, string $from, string $to): void
    {
        $db = DB::connection($conn);

        $row = $db->selectOne("SELECT HEX(`$column`) hx FROM `$table` WHERE id = ?", [$pk]);

        if (! $row || $row->hx === null) {
            return;
        }

        $hx = strtoupper($row->hx);

        if ($hx === strtoupper($to)) {
            return; // already in the target state
        }

        if ($hx !== strtoupper($from)) {
            // Edited since these pairs were recorded; leave it and say so.
            echo "  skipped $conn.$table.$column id=$pk -- content changed since this repair was written\n";

            return;
        }

        try {
            $db->statement("UPDATE `$table` SET `$column` = UNHEX(?) WHERE id = ?", [$to, $pk]);
        } catch (UniqueConstraintViolationException $e) {
            // Repairing the text can reveal a duplicate that the mojibake was
            // hiding. Leave the row damaged rather than guess at a merge.
            echo "  skipped $conn.$table.$column id=$pk -- repaired value duplicates an existing row\n";
        }
    }
};
