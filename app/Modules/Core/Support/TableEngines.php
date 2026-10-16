<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\DB;

/**
 * Every gds table is InnoDB — this finds and converts the ones that are not.
 *
 * The legacy app created a handful of its tables as MyISAM, which has no
 * transactions: `DB::transaction()` around them commits each statement as it
 * runs, so a batch that fails half-way leaves half its rows behind. That was
 * the reason the raw-materials screens serialise on GET_LOCK, and why a
 * rollback-based test once left four real rows in a live table.
 *
 * Migration 2026_10_07_100000 converts them. This class is shared with
 * `gds:replay-backfills` ('innodb-engine') because a hand restore from a
 * production dump re-creates tables with the dump's `ENGINE=MyISAM` while the
 * migration stays marked as run. (`gds:refresh-legacy` does not: it TRUNCATEs
 * and INSERTs into the existing table, so the engine survives.)
 *
 * The conversion copies the table. 19 tables, ~95 MB on dev; the biggest
 * (`rawmaterials_warehouse_exit`, 318k rows) takes a few seconds. Writes to a
 * table wait while it is copied, so run it outside working hours.
 */
class TableEngines
{
    /** The connections gds owns. `bilfg` is the legacy's own database and is left alone. */
    public const CONNECTIONS = ['core', 'bil', 'bpl'];

    /**
     * Base tables that are not InnoDB, as "connection.table".
     *
     * @return list<string>
     */
    public static function nonInnoDb(): array
    {
        $found = [];

        foreach (self::CONNECTIONS as $conn) {
            $db = DB::connection($conn);
            // information_schema answers in upper case on MySQL 8, hence the alias.
            $tables = $db->table('information_schema.tables')
                ->where('table_schema', $db->getDatabaseName())
                ->where('table_type', 'BASE TABLE')
                ->where('engine', '<>', 'InnoDB')
                ->orderBy('table_name')
                ->pluck('table_name as name');

            foreach ($tables as $table) {
                $found[] = "{$conn}.{$table}";
            }
        }

        return $found;
    }

    /**
     * Convert every non-InnoDB table. Returns what was converted.
     *
     * @param  callable(string $table, float $seconds): void|null  $progress
     * @return list<string>
     */
    public static function convertAll(?callable $progress = null): array
    {
        $done = [];

        foreach (self::nonInnoDb() as $qualified) {
            [$conn, $table] = explode('.', $qualified, 2);
            $started = microtime(true);
            self::convert($conn, $table);
            $done[] = $qualified;

            if ($progress) {
                $progress($qualified, microtime(true) - $started);
            }
        }

        return $done;
    }

    public static function convert(string $conn, string $table): void
    {
        $db = DB::connection($conn);
        $mode = $db->selectOne('SELECT @@SESSION.sql_mode AS m')->m;

        // The copy re-validates every row under the session's sql_mode, and the
        // legacy rows include zero dates ('0000-00-00') that strict mode rejects.
        // They are the table's existing data, not new input, so relax it for the
        // copy only.
        $db->statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

        try {
            $db->statement("ALTER TABLE `{$table}` ENGINE = InnoDB");
        } finally {
            $db->statement('SET SESSION sql_mode = ?', [$mode]);
        }
    }
}
