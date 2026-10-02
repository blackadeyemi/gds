<?php
/*
 | One-off: re-repair legacy mojibake that a dump refresh UNDOES.
 |
 | Context (see memory legacy-text-encoding / dump-refresh-undoes-backfills):
 | the original 2026-08-07 fix was a RELABEL (bytes right, label wrong). A
 | legacy dump refresh reloads those columns through a latin1->utf8mb4 transcode,
 | so the bytes come back DOUBLE-encoded in an already-utf8mb4 column. That needs
 | a DECODE ROUND, not a relabel:
 |     CONVERT(BINARY(CONVERT(col USING latin1)) USING utf8mb4)
 | i.e. read the utf8mb4 text as cp1252 bytes (recovering the original UTF-8
 | bytes), then read those bytes as UTF-8.
 |
 | SAFETY: every row is guarded by a reversibility test — we only rewrite a row
 | when the decode is a genuine, reversible single-round mojibake (re-encoding
 | the fix reproduces the stored value byte-for-byte). Correctly-stored text does
 | not round-trip and is left untouched. Dry by default; --apply writes.
 |
 | latin1 columns (e.g. sales_loading.truckdriver): the recovered text may hold
 | characters latin1 cannot store (the POLICE emoji). There we keep only the
 | cp1252-representable part and rtrim — which reproduces the original migration's
 | "POLICE".
 |
 | Usage:
 |   php scripts/repair_mojibake_decode.php --selftest
 |   php scripts/repair_mojibake_decode.php --db=mojibake_check            (dry)
 |   php scripts/repair_mojibake_decode.php --db=mojibake_check --apply
 |   php scripts/repair_mojibake_decode.php --db=<live bil db> --apply
 */

$opt = getopt('', ['db:', 'apply', 'host:', 'user:', 'pass:', 'selftest', 'samples:']);
$apply   = isset($opt['apply']);
$samples = (int) ($opt['samples'] ?? 8);

// table => [column => pk]. Only the columns a refresh re-damages (refreshed
// tables from config/legacy_refresh.php). Masters (sales_customers,
// bpl_customers) are not reloaded, so they are not re-damaged here.
$TARGETS = [
    'factory_machine_maintenance' => ['note' => 'id'],
    'sales_loading'               => ['truckdriver' => 'id'],
];

/**
 * Decode one value. Returns [fixed, ok]: ok=true only for genuine, reversible
 * single-round mojibake that is safe to rewrite.
 */
function decode_round(string $m): array
{
    // codepoints(m) re-encoded as cp1252 bytes == the original UTF-8 bytes
    $bytes = @iconv('UTF-8', 'Windows-1252', $m);
    if ($bytes === false) return [$m, false];               // non-cp1252 codepoint present
    if (!mb_check_encoding($bytes, 'UTF-8')) return [$m, false]; // not valid UTF-8 => not the original
    if ($bytes === $m) return [$m, false];                  // nothing to change
    // reversibility guard: re-mojibake must reproduce the stored value exactly
    $back = @iconv('Windows-1252', 'UTF-8', $bytes);
    if ($back !== $m) return [$m, false];
    return [$bytes, true];
}

/** Store form for a column of the given charset (latin1 can't hold every char). */
function store_for(string $fixed, string $charset): string
{
    if (stripos($charset, 'latin1') === 0) {
        $lat = @iconv('UTF-8', 'Windows-1252//IGNORE', $fixed); // drop un-storable chars
        $fixed = $lat === false ? $fixed : (@iconv('Windows-1252', 'UTF-8', $lat) ?: $fixed);
        $fixed = rtrim($fixed);
    }
    return $fixed;
}

// ---------------- self test ----------------
if (isset($opt['selftest'])) {
    // Build each input the way the DB returns it: original UTF-8 bytes stored as
    // cp1252 and transcoded back to UTF-8 by the client = the mojibake string.
    $moji = fn (string $origBytes) => iconv('Windows-1252', 'UTF-8', $origBytes);
    $cases = [
        // label, stored(mojibake) value, expected utf8 fix, expected latin1 store
        ['bullet',  $moji("\xE2\x80\xA2"),                 '•',               '•'],
        ['societe', $moji("Soci\xC3\xA9t\xC3\xA9"),        'Société',         'Société'],
        ['dash',    $moji("A \xE2\x80\x93 B"),             'A – B',           'A – B'],
        ['police',  $moji("POLICE \xF0\x9F\x9A\xA8"),      "POLICE \u{1F6A8}", 'POLICE'],
        ['ascii',   'Hello',                               'Hello',           'Hello'], // unchanged
        ['correct', 'Café',                                'Café',            'Café'],  // already correct: untouched
    ];
    $fail = 0;
    foreach ($cases as $c) {
        [$label, $m, $expUtf, $expLat] = $c;
        [$fixed, $ok] = decode_round($m);
        $utf = $ok ? $fixed : $m;
        $lat = store_for($utf, 'latin1');
        printf("%-9s ok=%s  utf=%-16s latin1=%-10s\n", $label, $ok ? 'Y' : 'n', $utf, $lat);
        if ($utf !== $expUtf) { echo "   ^ utf expected [$expUtf]\n"; $fail++; }
        if ($lat !== $expLat) { echo "   ^ latin1 expected [$expLat]\n"; $fail++; }
    }
    echo $fail ? "\nSELFTEST FAILED ($fail)\n" : "\nselftest ok — decode round + latin1 handling correct\n";
    exit($fail ? 1 : 0);
}

// ---------------- db run ----------------
$db = $opt['db'] ?? null;
if (!$db) { fwrite(STDERR, "--db=<database> required (or --selftest)\n"); exit(2); }
$host = $opt['host'] ?? '127.0.0.1';
$user = $opt['user'] ?? 'root';
$pass = $opt['pass'] ?? '';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo ($apply ? 'APPLY' : 'DRY') . " — mojibake decode round on `$db`\n\n";
$grand = 0;

foreach ($TARGETS as $table => $cols) {
    // table present?
    $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='$table'")->fetchColumn();
    if (!$exists) { echo "  $table: not in `$db` — skipped\n"; continue; }

    foreach ($cols as $col => $pk) {
        $charset = (string) $pdo->query("SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='$table' AND COLUMN_NAME='$col'")->fetchColumn();

        if (stripos($charset, 'latin1') === 0) {
            // latin1 column (e.g. truckdriver): the recovered text may hold a
            // char latin1 can't store (the emoji). Do it per-row in PHP so we
            // can reduce to the cp1252-safe form (-> "POLICE"). These columns'
            // damage is cp1252-only, so PHP's Windows-1252 map is sufficient.
            $rows = $pdo->query("SELECT `$pk` pk, `$col` v FROM `$table`
                WHERE `$col` IS NOT NULL AND `$col` <> CONVERT(`$col` USING ascii)")
                ->fetchAll(PDO::FETCH_ASSOC);
            $fixes = [];
            foreach ($rows as $r) {
                [$fixed, $ok] = decode_round((string) $r['v']);
                if (!$ok) continue;
                $store = store_for($fixed, $charset);
                if ($store === (string) $r['v']) continue;
                $fixes[] = ['pk' => $r['pk'], 'from' => (string) $r['v'], 'to' => $store];
            }
            printf("  %-32s %-12s charset=%-8s to-fix=%d\n", $table, $col, $charset, count($fixes));
            foreach (array_slice($fixes, 0, $samples) as $f) {
                printf("     #%-8s  %s  ->  %s\n", $f['pk'], mb_strimwidth($f['from'], 0, 48, '…'), mb_strimwidth($f['to'], 0, 48, '…'));
            }
            if ($apply && $fixes) {
                $up = $pdo->prepare("UPDATE `$table` SET `$col` = ? WHERE `$pk` = ?");
                foreach ($fixes as $f) { $up->execute([$f['to'], $f['pk']]); }
                echo "     applied " . count($fixes) . " update(s)\n";
            }
            $grand += count($fixes);
            continue;
        }

        // utf8mb4/utf8mb3 column (e.g. note): the decode round done IN SQL, so
        // MySQL's full-256 latin1 map handles bytes PHP's Windows-1252 rejects
        // (0x81/8D/8F/90/9D — e.g. the ” curly quote). Guard = reversible:
        // re-encoding the fix as latin1 bytes must reproduce the stored value.
        $dec   = "CONVERT(CAST(CONVERT(`$col` USING latin1) AS BINARY) USING utf8mb4)";
        $remoji = "CONVERT(CONVERT(CAST($dec AS BINARY) USING latin1) USING utf8mb4)";
        // All comparisons via HEX / byte-length to avoid collation-mix errors
        // (CONVERT yields utf8mb4_general_ci; the column may be utf8mb4_unicode_ci).
        $guard = "`$col` IS NOT NULL
            AND LENGTH(`$col`) <> CHAR_LENGTH(`$col`)
            AND HEX($dec) <> HEX(`$col`)
            AND HEX($remoji) = HEX(`$col`)";

        $n = (int) $pdo->query("SELECT COUNT(*) FROM `$table` WHERE $guard")->fetchColumn();
        printf("  %-32s %-12s charset=%-8s to-fix=%d\n", $table, $col, $charset, $n);
        foreach ($pdo->query("SELECT `$pk` pk, LEFT(`$col`,48) a, LEFT($dec,48) b
                              FROM `$table` WHERE $guard LIMIT $samples") as $s) {
            printf("     #%-8s  %s  ->  %s\n", $s['pk'], $s['a'], $s['b']);
        }
        if ($apply && $n) {
            $pdo->exec("UPDATE `$table` SET `$col` = $dec WHERE $guard");
            echo "     applied $n update(s)\n";
        }
        $grand += $n;
    }
}

echo "\n" . ($apply ? 'Applied' : 'Would fix') . " $grand row(s) total.\n";
