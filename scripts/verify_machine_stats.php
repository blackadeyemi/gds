<?php
/** Machines → Statistics: every section × every range renders, with timings. */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Livewire\Livewire;
use Modules\Bil\Livewire\Machines\Statistics;
use Modules\Bil\Livewire\RawMaterials\Statistics as RmStatistics;
use Modules\Core\Models\User;

$fail = 0;
$ok = function (string $l, bool $p, string $d = '') use (&$fail) {
    echo ($p ? '  PASS  ' : '  FAIL  ') . str_pad($l, 44) . $d . PHP_EOL;
    if (! $p) $fail++;
};

$admin = User::query()->with('roles')->get()->first(fn ($u) => $u->roles->contains('legacy_level', 1));
Livewire::actingAs($admin);

$c = new Statistics();
$sections = $c->sections();
$ranges = array_keys($c->rangeOptions());

echo "-- sections x ranges --\n";
$slowest = 0; $slowestWhat = '';
foreach ($sections as $key => $label) {
    foreach ($ranges as $r) {
        $t = microtime(true);
        try {
            $comp = Livewire::test(Statistics::class, ['section' => $key, 'range' => $r]);
            $comp->set('section', $key)->set('range', $r);
            $tiles = $comp->viewData('tiles');
            $charts = $comp->viewData('charts');
            $ms = (microtime(true) - $t) * 1000;
            if ($ms > $slowest) { $slowest = $ms; $slowestWhat = "$key/$r"; }
            $emptyChart = collect($charts)->first(fn ($ch) => count($ch['labels']) === 0);
            $ok(sprintf('%-10s %-4s', $key, $r),
                count($tiles) > 0 && count($charts) > 0,
                sprintf('%2d tiles, %d charts, %5.0f ms%s', count($tiles), count($charts), $ms,
                    $emptyChart && $r !== '7d' ? '  (empty: ' . $emptyChart['title'] . ')' : ''));
        } catch (\Throwable $e) {
            $ok("$key/$r", false, get_class($e) . ': ' . substr($e->getMessage(), 0, 120));
        }
    }
}
echo "  slowest: $slowestWhat at " . round($slowest) . " ms\n";

echo "\n-- figures reconcile with the report --\n";
$c2 = new Statistics(); $c2->section = 'downtime'; $c2->range = 'all';
$data = (function () { return $this->section('downtime'); })->call($c2);
$total = $data['tiles'][0]['value'];
$db = Illuminate\Support\Facades\DB::connection('bil');
// Deliberately re-derived from the JSON, not the materialised column, so this
// also proves duration_minutes agrees with what duration actually says.
$mins = (int) $db->selectOne("SELECT SUM(
      COALESCE(JSON_EXTRACT(duration,'$.d'),0)*1440
    + COALESCE(JSON_EXTRACT(duration,'$.h'),0)*60
    + COALESCE(JSON_EXTRACT(duration,'$.m'),0)) v
  FROM factory_machine_maintenance WHERE `date` BETWEEN '2000/01/01' AND '2100/01/01'")->v;
$drift = (int) $db->selectOne("SELECT COUNT(*) n FROM factory_machine_maintenance
  WHERE COALESCE(duration_minutes,0) <> COALESCE(JSON_EXTRACT(duration,'$.d'),0)*1440
      + COALESCE(JSON_EXTRACT(duration,'$.h'),0)*60
      + COALESCE(JSON_EXTRACT(duration,'$.m'),0)")->n;
$ok('duration_minutes matches the JSON on every row', $drift === 0, "$drift rows differ");
$ok('all-time stop time matches SQL', $total === Modules\Bil\Support\ServiceDuration::format($mins), $total);

$jobs = (int) $db->selectOne("SELECT COUNT(*) n FROM factory_machine_maintenance WHERE `date` BETWEEN '2000/01/01' AND '2100/01/01'")->n;
$c3 = new Statistics(); $c3->section = 'overview'; $c3->range = 'all';
$ov = (function () { return $this->section('overview'); })->call($c3);
$ok('all-time job count matches SQL', $ov['tiles'][0]['value'] === number_format($jobs), $ov['tiles'][0]['value'] . " vs $jobs");
$ok('dirty dd/mm/yy rows excluded', $jobs === 43402 - 3, "$jobs of 43402");

echo "\n-- export --\n";
foreach (['overview', 'downtime'] as $s) {
    $c4 = new Statistics(); $c4->section = $s; $c4->range = '12m';
    $resp = $c4->exportResponse('csv');
    $ok("csv export ($s)", $resp !== null, get_class($resp));
}

echo "\n-- raw materials still works (shared trait) --\n";
foreach (['overview', 'stock', 'flow', 'consumption', 'losses'] as $s) {
    try {
        $t = microtime(true);
        Livewire::test(RmStatistics::class, ['section' => $s, 'range' => '30d']);
        $ok("rm $s", true, round((microtime(true) - $t) * 1000) . ' ms');
    } catch (\Throwable $e) {
        $ok("rm $s", false, substr($e->getMessage(), 0, 100));
    }
}

echo PHP_EOL, $fail === 0 ? "ALL CHECKS PASSED\n" : "$fail CHECK(S) FAILED\n";
