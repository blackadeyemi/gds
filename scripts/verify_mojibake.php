<?php

/**
 * Verifies 2026_08_07_100000_repair_legacy_mojibake did what it claims:
 * the bytes were preserved, they now read as the intended characters, and
 * nothing else in those columns moved.
 *
 * Run *after* the migration:  php scripts/verify_mojibake.php
 *
 * Take the "before" snapshot first if you want the byte-for-byte comparison
 * (checks 2 and 4) rather than just the after-state checks:
 *
 *   php scripts/verify_mojibake.php --snapshot
 *
 * It writes storage/app/mojibake_before.json. Without it the script still runs,
 * and says which checks it had to skip.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

/** Columns the migration rewrote, by primary key — excluded from the "unchanged" hashes. */
const REPAIRED = [
    'bil.factory_machine_maintenance.note' => [],
    'bil.sales_loading.truckdriver' => [404993, 404994, 404995, 437039],
    'bil.sales_customers.customeraddress' => [1646],
];

$snapshotFile = __DIR__ . '/../storage/app/mojibake_before.json';

if (in_array('--snapshot', $argv, true)) {
    $out = ['rows' => [], 'checksums' => []];

    foreach (array_keys(REPAIRED) as $target) {
        [$conn, $table, $column] = explode('.', $target);
        $rows = DB::connection($conn)->select(
            "SELECT id pk, HEX(`$column`) hx FROM `$table` WHERE `$column` <> CONVERT(`$column` USING ascii) ORDER BY id"
        );
        $out['rows'][$target] = array_map(fn ($r) => [$r->pk, $r->hx], $rows);

        // GROUP_CONCAT truncates at group_concat_max_len; this aggregate does not.
        $sum = DB::connection($conn)->selectOne(
            "SELECT COUNT(*) n,
                    COALESCE(SUM(CASE WHEN `$column` = CONVERT(`$column` USING ascii)
                                      THEN CRC32(COALESCE(HEX(`$column`),'~')) ELSE 0 END),0) sa
             FROM `$table`"
        );
        $out['checksums'][$target] = ['rows' => (int) $sum->n, 'crc_ascii_only' => (string) $sum->sa];

        printf("  %-46s %d damaged row(s) of %d\n", $target, count($rows), $sum->n);
    }

    file_put_contents($snapshotFile, json_encode($out));
    echo "\nSnapshot written to storage/app/mojibake_before.json — now run the migration.\n";
    exit(0);
}

$before = is_file($snapshotFile) ? json_decode(file_get_contents($snapshotFile), true) : null;
$fail = 0;
$skipped = 0;

$check = function (string $label, bool $ok, string $detail = '') use (&$fail) {
    printf("  [%s] %-52s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail);

    if (! $ok) {
        $fail++;
    }
};

$skip = function (string $label) use (&$skipped) {
    printf("  [--] %-52s no before-snapshot\n", $label);
    $skipped++;
};

echo "== 1. note is now labelled utf8mb4 ==\n";
$col = DB::connection('bil')->selectOne(
    "SELECT DATA_TYPE dt, CHARACTER_SET_NAME cs, COLLATION_NAME co FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'bil' AND TABLE_NAME = 'factory_machine_maintenance' AND COLUMN_NAME = 'note'"
);
$check('type/charset', $col->dt === 'text' && $col->cs === 'utf8mb4', "{$col->dt} {$col->cs}/{$col->co}");

echo "== 2. every damaged note kept its exact bytes ==\n";
if ($before) {
    $now = [];
    foreach (DB::connection('bil')->select('SELECT id, HEX(note) hx FROM factory_machine_maintenance
                                            WHERE note <> CONVERT(note USING ascii) ORDER BY id') as $r) {
        $now[$r->id] = strtoupper($r->hx);
    }

    $was = [];
    foreach ($before['rows']['bil.factory_machine_maintenance.note'] as [$pk, $hx]) {
        $was[$pk] = strtoupper($hx);
    }

    $check('row count unchanged', count($now) === count($was), count($was) . ' -> ' . count($now));

    $diff = 0;
    foreach ($was as $pk => $hx) {
        if (($now[$pk] ?? null) !== $hx) {
            $diff++;
        }
    }

    $check('bytes identical for all damaged rows', $diff === 0, "$diff differ");
} else {
    $skip('bytes identical for all damaged rows');
}

echo "== 3. those bytes now READ as the intended characters ==\n";
// Compare bytes, not characters: utf8mb4_unicode_ci is accent-insensitive, so
// LIKE '%Ã%' matches every row containing a plain "a" and would prove nothing.
$bad = 0;
$sample = null;
foreach (DB::connection('bil')->select("SELECT id FROM factory_machine_maintenance
        WHERE HEX(note) LIKE '%C3A2E282AC%' OR HEX(note) LIKE '%C382C2%'") as $r) {
    $bad++;
    $sample ??= $r->id;
}
$check('no mojibake left in notes', $bad === 0, $bad ? "still $bad rows (e.g. id $sample)" : '');

$n = DB::connection('bil')->selectOne("SELECT COUNT(*) c FROM factory_machine_maintenance WHERE note LIKE '%•%'")->c;
$check('bullets read as bullets', $n > 1000, "$n rows contain •");

echo "== 4. untouched rows really were untouched ==\n";
foreach (REPAIRED as $target => $pks) {
    [$conn, $table, $column] = explode('.', $target);

    if (! $before) {
        $skip("$table.$column untouched rows unchanged");

        continue;
    }

    // Repaired rows legitimately move from non-ascii to ascii, so exclude them
    // by primary key; every other row must hash exactly as it did before.
    $not = $pks ? 'AND id NOT IN (' . implode(',', $pks) . ')' : '';
    $r = DB::connection($conn)->selectOne(
        "SELECT COUNT(*) n,
                COALESCE(SUM(CASE WHEN `$column` = CONVERT(`$column` USING ascii) $not
                                  THEN CRC32(COALESCE(HEX(`$column`),'~')) ELSE 0 END),0) sa
         FROM `$table`"
    );
    $b = $before['checksums'][$target];
    $check("$table.$column untouched rows unchanged",
        (string) $r->sa === $b['crc_ascii_only'] && (int) $r->n === (int) $b['rows'], "rows={$r->n}");
}

echo "== 5. the individually repaired rows ==\n";
$address = DB::connection('bil')->table('sales_customers')->where('id', 1646)->value('customeraddress');
$check('sales_customers 1646', str_contains((string) $address, 'Ashley’s'), (string) $address);

$drivers = DB::connection('bil')->table('sales_loading')->whereIn('id', [404993, 404994, 404995, 437039])
    ->pluck('truckdriver')->all();
$check('sales_loading truckdriver x4', $drivers === array_fill(0, 4, 'POLICE'), implode(', ', $drivers));

$lome = DB::connection('bpl')->table('bpl_customers')->where('id', 150)->value('customeraddress');
$check('bpl_customers 150 address', $lome === 'Lomé-TOGO', (string) $lome);

$gabon = (string) DB::connection('bpl')->table('bpl_customers')->where('id', 131)->value('customername');
$check('bpl_customers 131 left alone (known duplicate)', str_contains($gabon, 'Ã'), "still: $gabon");

echo "== 6. reports can still search the column ==\n";
try {
    $hits = DB::connection('bil')->table('factory_machine_maintenance as m')
        ->where('m.note', 'like', '%preventive%')->count();
    $check('LIKE on note works', $hits > 0, "$hits rows match 'preventive'");
} catch (Throwable $e) {
    $check('LIKE on note works', false, substr($e->getMessage(), 0, 80));
}

echo "\n", $fail === 0 ? 'ALL CHECKS PASSED' : "$fail CHECK(S) FAILED";
echo $skipped ? " ($skipped skipped — no before-snapshot)\n" : "\n";

exit($fail === 0 ? 0 : 1);
