<?php

/*
| Export a COMMITTABLE snapshot of the gds databases: full schema for every
| table, but transactional ("entry") tables trimmed to 2025-onward so the dump
| stays small enough for git. Reference/lookup tables keep all their data.
|
|   php scripts/export_db_snapshot.php
|
| Output: database/dumps/<db>.sql.gz (one per database). Re-run to refresh.
|
| Trim rules (a table is a "big" candidate only above ROW_THRESHOLD):
|   - a unix-int date column (`timestamp`, or core.logs.`time`) -> >= CUTOFF_UNIX
|   - a datetime `created_at`                                   -> >= CUTOFF_DATE
|   - the handful below (no usable date col, or a child table)  -> explicit WHERE
|   - everything else (reference, or small)                     -> full data
*/

$DBS = ['core', 'bil', 'bpl', 'bhn'];
$CUTOFF_UNIX = 1735689600;      // 2025-01-01 00:00:00 UTC
$CUTOFF_DATE = '2025-01-01';
$ROW_THRESHOLD = 20000;         // tables at/below this are dumped in full

$HOST = '127.0.0.1';
$USER = 'root';
$PASS = '';
$BIN = 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin';
$OUT = __DIR__ . '/../database/dumps';

// Explicit WHERE for big tables without a plain unix/created_at column, and for
// the one large child table (trimmed to the parents that survive the cutoff).
$OVERRIDE = [
    'core.logs' => "`time` >= {$CUTOFF_UNIX}",
    'bil.factory_entrance_rawmaterials' => "`entrance_date` >= '{$CUTOFF_DATE}'",
    'bhn.factory_entrance_rawmaterials' => "`entrance_date` >= '{$CUTOFF_DATE}'",
    'bil.sales_order_details' => "`orderid` IN (SELECT `orderid` FROM `sales_order` WHERE `timestamp` >= {$CUTOFF_UNIX})",
    'bil.factory_machine_maintenance' => "STR_TO_DATE(`date`,'%d/%m/%y') >= '{$CUTOFF_DATE}'",
    'bil.conversion_setup_history' => "`date_modified` >= '{$CUTOFF_DATE}'",
];
// Reference tables kept FULL even when large.
$FORCE_FULL = ['core.geo_cities', 'core.geo_regions', 'core.geo_states', 'core.geo_countries'];

@mkdir($OUT, 0777, true);

$pdo = new PDO("mysql:host={$HOST};charset=utf8mb4", $USER, $PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/** Run a mysql client tool with array args, streaming stdout (append) to $file. */
function dumpTo(string $file, array $args, string $mode = 'a'): void
{
    // Use proc_open's own file redirection (passing a PHP fopen handle as a
    // descriptor loses output on Windows).
    $p = proc_open($args, [1 => ['file', $file, $mode], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($p)) {
        fwrite(STDERR, "proc_open failed for: " . implode(' ', $args) . "\n");
        exit(1);
    }
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($p);
    // mysqldump prints a harmless GTID/‐tablespaces notice to stderr; only fail on non-zero.
    if ($code !== 0) {
        fwrite(STDERR, "mysqldump failed ({$code}): " . trim($err) . "\n");
        exit(1);
    }
}

foreach ($DBS as $db) {
    $base = "{$OUT}/{$db}.sql";
    @unlink($base);
    @unlink("{$base}.gz");
    file_put_contents($base, "-- gds snapshot of `{$db}` (schema full; entry tables from {$CUTOFF_DATE})\n"
        . "-- generated " . date('c') . " by scripts/export_db_snapshot.php\n"
        . "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

    // 1) Full structure for every table/view/trigger/routine.
    dumpTo($base, ["{$BIN}/mysqldump.exe", "-h{$HOST}", "-u{$USER}", '--skip-lock-tables',
        '--no-data', '--routines', '--triggers', '--events', '--skip-set-charset',
        '--default-character-set=utf8mb4', $db]);

    // Base tables + row estimate + the TYPE of the timestamp/created_at column
    // (a `timestamp` column may be an int unix epoch OR a datetime).
    $tables = $pdo->query(
        "SELECT t.table_name AS tname,
                t.table_rows AS trows,
                MAX(CASE WHEN c.column_name='timestamp' THEN c.data_type END) AS ts_type,
                MAX(CASE WHEN c.column_name='created_at' THEN c.data_type END) AS created_type
         FROM information_schema.tables t
         JOIN information_schema.columns c
           ON c.table_schema=t.table_schema AND c.table_name=t.table_name
         WHERE t.table_schema=" . $pdo->quote($db) . " AND t.table_type='BASE TABLE'
         GROUP BY t.table_name, t.table_rows ORDER BY t.table_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $plan = [];
    foreach ($tables as $t) {
        $name = $t['tname'];
        $key = "{$db}.{$name}";
        $where = null;

        if (in_array($key, $FORCE_FULL, true)) {
            $where = null;                              // full
        } elseif (isset($OVERRIDE[$key])) {
            $where = $OVERRIDE[$key];
        } elseif ((int) $t['trows'] > $ROW_THRESHOLD) {
            if (in_array($t['ts_type'], ['int', 'bigint'], true)) {
                $where = "`timestamp` >= {$CUTOFF_UNIX}";          // unix epoch
            } elseif ($t['ts_type'] !== null) {
                $where = "`timestamp` >= '{$CUTOFF_DATE}'";        // datetime/date
            } elseif ($t['created_type'] !== null) {
                $where = "`created_at` >= '{$CUTOFF_DATE}'";
            } else {
                $where = null;                          // big but no date col -> full (fallback)
                fwrite(STDERR, "  note: {$key} is large ({$t['trows']}) with no date column — dumped FULL\n");
            }
        }

        $args = ["{$BIN}/mysqldump.exe", "-h{$HOST}", "-u{$USER}",
            '--no-create-info', '--skip-triggers', '--skip-set-charset', '--skip-lock-tables',
            '--default-character-set=utf8mb4', '--no-tablespaces'];
        if ($where !== null) {
            $args[] = "--where={$where}";
        }
        $args[] = $db;
        $args[] = $name;

        dumpTo($base, $args);
        $plan[] = sprintf('    %-42s %s', $name, $where === null ? 'full' : "WHERE {$where}");
    }

    file_put_contents($base, "\nSET FOREIGN_KEY_CHECKS=1;\n", FILE_APPEND);

    // gzip (streamed in chunks — the bil .sql is hundreds of MB) and drop the .sql
    $in = fopen($base, 'rb');
    $gz = gzopen("{$base}.gz", 'wb9');
    while (! feof($in)) {
        gzwrite($gz, fread($in, 1 << 20));
    }
    gzclose($gz);
    fclose($in);
    unlink($base);

    printf("%-6s -> %s.sql.gz  (%.1f MB, %d tables)\n", $db, $db,
        filesize("{$base}.gz") / 1048576, count($tables));
    echo implode("\n", $plan) . "\n\n";
}

echo "Done. Snapshots in database/dumps/\n";
