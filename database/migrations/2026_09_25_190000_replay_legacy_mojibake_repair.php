<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replays the 2026-08-07 mojibake repair, which a production dump refresh
 * undid while leaving `2026_08_07_100000_repair_legacy_mojibake` marked as run
 * — the pattern that has already claimed `factory_event.date` and
 * `bpl_factoryexit.received_at`.
 *
 * ## It is NOT the same repair, because the damage is not the same shape
 *
 * The August fix was a RELABEL: the bytes were correct UTF-8 and only the
 * column's declared charset was wrong, so round-tripping
 * `TEXT → BLOB → TEXT utf8mb4` fixed every row without rewriting one.
 *
 * That relabel survived — `note` is still utf8mb4. What came back on the
 * refresh is different: the restore read the source's mojibake CHARACTERS and
 * wrote them into the utf8mb4 column, encoding each one again. `°` (U+00B0)
 * is now `C3 82 C2 B0` where it should be `C2 B0`, and the note reads
 * `110Â°c`. Relabelling that a second time would do nothing at all; it needs a
 * DECODE round, the shape `bpl_customers` always needed.
 *
 * ## What is repaired
 *
 *   `bil.factory_machine_maintenance.note`  157 of 44,436 rows
 *   `bil.sales_customers.customeraddress`   id 1646
 *   `bil.sales_loading.truckdriver`         4 rows
 *
 * The 2,451 rows that already hold correct multi-byte text are untouched: a
 * literal `Ã` or `Â` is what separates them, matched with `LIKE BINARY` — see
 * DAMAGED below for why neither a plain LIKE nor a HEX pattern will do.
 *
 * ## The guards
 *
 * Every `note` row must survive the latin1 hop unchanged before it is
 * rewritten — a character latin1 cannot hold would be replaced by `?` on the
 * way through. Measured before writing this: 0 of the 157 are lossy, and the
 * decode is reversible for all of them. The row fixes are byte-exact pairs
 * lifted from the August migration, so a row that has since been edited is
 * skipped and reported rather than overwritten.
 *
 * `bpl_customers` 131 (`SociÃ©tÃ© HygiÃ¨ne Plus Gabon`) is still NOT repaired:
 * `customername` is UNIQUE and `utf8mb3_general_ci` folds accents, so the
 * corrected name collides with id 128, `SOCIETE HYGIENE PLUS GABON` — the same
 * company entered twice. That is a merge decision, not an encoding repair.
 */
return new class extends Migration
{
    /** Rows whose damage is a character, not a whole column: [conn, table, column, pk, from, to] as HEX. */
    private const ROW_FIXES = [
        // "UBA PHARMACY & SUPERMARKET – ELEGANZA; Ashley's Place Mall, …"
        ['bil', 'sales_customers', 'customeraddress', 1646,
            '55424120504841524D41435920262053555045524D41524B455420E2809320454C4547414E5A413B204173686C6579E280997320506C616365204D616C6C2C20627920456C6567616E7A61206275732073746F702C204C656B6B692D416A6168',
            '55424120504841524D41435920262053555045524D41524B4554209620454C4547414E5A413B204173686C6579927320506C616365204D616C6C2C20627920456C6567616E7A61206275732073746F702C204C656B6B692D416A6168'],

        // "POLICE" + siren emoji. U+1F6A8 has no cp1252 form and widening the
        // column is what the August pass was avoiding, so it is dropped — the
        // rows then read "POLICE", which is what the other 47 already say.
        ['bil', 'sales_loading', 'truckdriver', 404993, '504F4C49434520F09F9AA8', '504F4C494345'],
        ['bil', 'sales_loading', 'truckdriver', 404994, '504F4C49434520F09F9AA8', '504F4C494345'],
        ['bil', 'sales_loading', 'truckdriver', 404995, '504F4C49434520F09F9AA8', '504F4C494345'],
        ['bil', 'sales_loading', 'truckdriver', 437039, '504F4C49434520F09F9AA8', '504F4C494345'],
    ];

    /**
     * The double-encoded rows, and only those.
     *
     * `LIKE BINARY` rather than a HEX pattern. A plain `LIKE '%Ã%'` is useless
     * — the collation is accent-insensitive and matches every row containing
     * "a" — but matching on the HEX STRING is not byte-aligned either: "C383"
     * turns up inside `100,80` (`2C 38 30`) and five pure-ASCII notes matched
     * it on the first run. They were harmless, because decoding ASCII is a
     * no-op, but the test was wrong. `LIKE BINARY '%Ã%'` compares the bytes
     * `C3 83`, and since `83` is a continuation byte it can never begin a
     * character — so every hit is the real thing.
     */
    private const DAMAGED = "(`note` LIKE BINARY '%Ã%' OR `note` LIKE BINARY '%Â%')";

    /**
     * Every character must survive the round to latin1 and back, or the hop
     * would substitute `?` for it.
     */
    private const LOSSLESS = 'HEX(CONVERT(CONVERT(`note` USING latin1) USING utf8mb4)) = HEX(`note`)';

    public function up(): void
    {
        $this->repairNotes();

        foreach (self::ROW_FIXES as [$conn, $table, $column, $pk, $damaged, $repaired]) {
            $this->setBytes($conn, $table, $column, $pk, $damaged, $repaired);
        }
    }

    public function down(): void
    {
        $this->damageNotes();

        foreach (self::ROW_FIXES as [$conn, $table, $column, $pk, $damaged, $repaired]) {
            // The dropped emoji cannot come back — the column still cannot hold it.
            $this->setBytes($conn, $table, $column, $pk, $repaired, $damaged);
        }
    }

    /**
     * One decode round: read the text's bytes back as latin1 — which is what
     * they were before the restore re-encoded them — and relabel those bytes
     * utf8mb4.
     */
    private function repairNotes(): void
    {
        if (! Schema::connection('bil')->hasTable('factory_machine_maintenance')) {
            return;
        }

        $db = DB::connection('bil');

        $lossy = (int) $db->selectOne(
            'SELECT COUNT(*) c FROM `factory_machine_maintenance`'
            . ' WHERE ' . self::DAMAGED . ' AND NOT (' . self::LOSSLESS . ')'
        )->c;

        if ($lossy > 0) {
            // Rewriting these would silently replace a character with '?'.
            // Better to repair none than to damage some further.
            echo "  skipped factory_machine_maintenance.note — {$lossy} row(s) hold a character"
                . " latin1 cannot carry; inspect them before replaying\n";

            return;
        }

        $done = $db->update(
            'UPDATE `factory_machine_maintenance`'
            . ' SET `note` = CONVERT(BINARY(CONVERT(`note` USING latin1)) USING utf8mb4)'
            . ' WHERE ' . self::DAMAGED . ' AND ' . self::LOSSLESS
        );

        echo "  repaired {$done} double-encoded note(s)\n";
    }

    /**
     * The inverse: take the correct text's bytes, read each one as a latin1
     * character, and let the utf8mb4 column encode them again.
     *
     * Only rows that can make the trip are touched. Five byte values are
     * undefined in cp1252 (MySQL's "latin1"), so a note containing one cannot
     * be re-damaged and is left correct — which is the harmless direction to
     * fail in.
     */
    private function damageNotes(): void
    {
        if (! Schema::connection('bil')->hasTable('factory_machine_maintenance')) {
            return;
        }

        DB::connection('bil')->update(
            'UPDATE `factory_machine_maintenance`'
            . ' SET `note` = CONVERT(CAST(`note` AS BINARY) USING latin1)'
            . ' WHERE `note` <> CONVERT(`note` USING ascii)'
            . ' AND HEX(CAST(CONVERT(CAST(`note` AS BINARY) USING latin1) AS BINARY)) <> HEX(`note`)'
        );
    }

    /** Writes $to only if the row still holds $from, and says so when it does not. */
    private function setBytes(string $conn, string $table, string $column, int $pk,
        string $from, string $to): void
    {
        if (! Schema::connection($conn)->hasTable($table)) {
            return;
        }

        $db = DB::connection($conn);

        $current = $db->selectOne(
            "SELECT HEX(`$column`) h FROM `$table` WHERE `id` = ?", [$pk]
        );

        if (! $current) {
            echo "  skipped $conn.$table.$column id=$pk — row no longer exists\n";

            return;
        }

        if (strcasecmp($current->h, $to) === 0) {
            return; // already repaired
        }

        if (strcasecmp($current->h, $from) !== 0) {
            echo "  skipped $conn.$table.$column id=$pk — content changed since this repair was written\n";

            return;
        }

        try {
            $db->update("UPDATE `$table` SET `$column` = UNHEX(?) WHERE `id` = ?", [$to, $pk]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            echo "  skipped $conn.$table.$column id=$pk — repaired value duplicates an existing row\n";
        }
    }
};
