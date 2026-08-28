<?php

/**
 * Verifies 2026_08_28_170000_drop_dead_inter_transfer_tables: the three dead
 * tables and their compatibility views are gone, the ones that only LOOK dead
 * are still there, and everything that reads `core` still works.
 *
 * Run: php scripts/verify_dead_table_drop.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const DROPPED = ['local_governments', 'rawmaterial_inter_transfer', 'rawmaterials_inter_received'];

/** Looked equally dead, but the legacy app still reads them. */
const KEPT = ['transfer_company_from', 'transfer_company_to', 'fg_inter_transfer', 'fg_inter_received',
    'countries', 'currencies', 'states'];

$fail = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$fail) {
    printf("  [%s] %-52s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail);

    if (! $ok) {
        $fail++;
    }
};

$exists = fn (string $schema, string $table) => (bool) DB::connection('core')->selectOne(
    'SELECT 1 ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$schema, $table]);

echo "== 1. gone from core, and from the bil/bpl views ==\n";
foreach (DROPPED as $table) {
    $where = array_values(array_filter(['core', 'bil', 'bpl'], fn ($s) => $exists($s, $table)));
    $check($table, $where === [], $where ? 'still in ' . implode(', ', $where) : 'gone');
}

echo "== 2. the ones that only LOOK dead are untouched ==\n";
foreach (KEPT as $table) {
    $in = array_values(array_filter(['core', 'bil', 'bpl'], fn ($s) => $exists($s, $table)));
    $rows = $exists('core', $table) ? DB::connection('core')->table($table)->count() : 0;
    $check($table, $in === ['core', 'bil', 'bpl'], "$rows rows, in " . implode('+', $in));
}

echo "== 3. the legacy queries that read them still run ==\n";
// report_fg_inter_transfer.php reads both company lookups through the bil view.
foreach (['transfer_company_from', 'transfer_company_to'] as $table) {
    try {
        $n = DB::connection('bil')->table($table)->count();
        $check("bil.$table readable", $n > 0, "$n rows");
    } catch (Throwable $e) {
        $check("bil.$table readable", false, substr($e->getMessage(), 0, 90));
    }
}

// The finished-goods inter-transfer screens the legacy app still serves.
try {
    $n = DB::connection('bil')->table('fg_inter_transfer')->count();
    $check('bil.fg_inter_transfer readable', $n > 0, "$n rows");
} catch (Throwable $e) {
    $check('bil.fg_inter_transfer readable', false, substr($e->getMessage(), 0, 90));
}

echo "== 4. nothing left referencing the dropped tables ==\n";
$roots = ['c:/laragon/www/gds/app', 'c:/laragon/www/gds/routes', 'c:/laragon/www/gds/config',
    'c:/laragon/www/bil/app', 'c:/laragon/www/bil/includes', 'c:/laragon/www/bil/js'];

foreach (DROPPED as $table) {
    $hits = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! in_array($file->getExtension(), ['php', 'js', 'yaml'], true)) {
                continue;
            }

            $body = @file_get_contents($file->getPathname());

            // The migration and this script name them on purpose.
            if ($body === false || str_contains($file->getPathname(), 'drop_dead_inter_transfer')
                || str_contains($file->getPathname(), 'verify_dead_table_drop')) {
                continue;
            }

            if (preg_match('/\b' . preg_quote($table, '/') . '\b/', $body)) {
                $hits[] = str_replace(['c:/laragon/www/', '\\'], ['', '/'], $file->getPathname());
            }
        }
    }

    // A retirement note that names the table is a comment, not a use.
    $hits = array_values(array_filter($hits, fn ($h) => ! str_contains($h, 'routes/api.yaml')));
    $check("$table unreferenced", $hits === [], $hits ? implode(', ', array_slice($hits, 0, 3)) : '');
}

echo "== 5. the retired legacy routes are unrouted ==\n";
$yaml = @file_get_contents('c:/laragon/www/bil/app/private/routes/api.yaml');
// Match the PATHS: '::save' is a substring of '::save_fg_transfer', which is
// still live, so the controller-method names cannot answer this.
$check('rm inter routes removed',
    $yaml !== false
    && ! str_contains($yaml, '/rawmaterials/inter/transfer')
    && ! str_contains($yaml, '/rawmaterials/inter/save')
    && ! str_contains($yaml, '/rawmaterials/inter/received'),
    'api.yaml');
$check('finished-goods transfer routes kept',
    $yaml !== false && str_contains($yaml, 'RawmaterialInterTransfer::save_fg_transfer')
    && str_contains($yaml, 'RawmaterialInterTransfer::to_company'), 'api.yaml');

echo "\n", $fail === 0 ? "ALL CHECKS PASSED\n" : "$fail CHECK(S) FAILED\n";

exit($fail === 0 ? 0 : 1);
