<?php
/** duration_minutes must be maintained on writes from BOTH apps, not just backfilled. */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$fail = 0;
$ok = function (string $l, bool $p, string $d = '') use (&$fail) {
    echo ($p ? '  PASS  ' : '  FAIL  ') . str_pad($l, 46) . $d . PHP_EOL;
    if (! $p) $fail++;
};

$db = DB::connection('bil');
$db->beginTransaction();
try {
    // A legacy-shaped insert: names only, duration as the JSON the old screen writes.
    $id = (int) $db->table('factory_machine_maintenance')->max('id') + 1;
    $db->table('factory_machine_maintenance')->insert([
        'id' => $id, 'jobtitle' => 'TRIGGER CHECK', 'jobid' => 'TC-1',
        'linename' => 'REW 11', 'project' => 'GAMBINI REWINDER 01', 'subproject' => '',
        'division' => 'MAINTENANCE ELECTRICAL', 'staff' => 'IYERE EDWARD', 'user' => 'check',
        'date' => '2026/08/07', 'starttime' => 'x', 'endtime' => 'y', 'note' => 'n',
        'duration' => '{"d":1,"h":2,"m":30}',
    ]);
    $row = $db->table('factory_machine_maintenance')->where('id', $id)->first();
    $ok('insert sets duration_minutes', (int) $row->duration_minutes === 1590, "got {$row->duration_minutes}, want 1590");
    $ok('insert still resolves the ids', $row->line_id && $row->project_id && $row->division_id && $row->staff_id,
        "line={$row->line_id} project={$row->project_id} division={$row->division_id} staff={$row->staff_id}");

    $db->table('factory_machine_maintenance')->where('id', $id)->update(['duration' => '{"d":0,"h":0,"m":45}']);
    $row = $db->table('factory_machine_maintenance')->where('id', $id)->first();
    $ok('update re-derives duration_minutes', (int) $row->duration_minutes === 45, "got {$row->duration_minutes}, want 45");

    // Junk duration must not fail the write — that is why this is a trigger, not
    // a generated column.
    $db->table('factory_machine_maintenance')->where('id', $id)->update(['duration' => 'not json']);
    $row = $db->table('factory_machine_maintenance')->where('id', $id)->first();
    $ok('invalid duration degrades to NULL, no error', $row->duration_minutes === null, var_export($row->duration_minutes, true));
} catch (\Throwable $e) {
    $ok('write path', false, get_class($e) . ': ' . substr($e->getMessage(), 0, 120));
} finally {
    $db->rollBack();
}

echo PHP_EOL, $fail === 0 ? "ALL CHECKS PASSED\n" : "$fail CHECK(S) FAILED\n";
