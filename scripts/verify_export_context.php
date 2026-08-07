<?php

/**
 * Exports must say what produced them (view, date range, every active filter,
 * search), and the drill-down modal must search, page and export.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bil\Livewire\Machines\Reports\Services as ServicesReport;
use Modules\Bil\Livewire\RawMaterials\Reports\SupplierDeliveries;
use Modules\Core\Models\User;

$fail = 0;
$ok = function (string $l, bool $p, string $d = '') use (&$fail) {
    echo ($p ? '  PASS  ' : '  FAIL  ') . str_pad($l, 50) . $d . PHP_EOL;
    if (! $p) {
        $fail++;
    }
};

$admin = User::query()->with('roles')->get()->first(fn ($u) => $u->roles->contains('legacy_level', 1));
Livewire::actingAs($admin);

/** Read an xlsx/csv export back into rows of cells. */
$readExport = function ($response): array {
    $path = $response->getFile()->getPathname();
    $rows = [];
    $reader = new OpenSpout\Reader\CSV\Reader();
    // The csv writer emits comma-separated UTF-8; read it straight back.
    $reader->open($path);
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($c) => (string) $c, $row->toArray());
        }
    }
    $reader->close();

    return $rows;
};

echo "== report export carries the filters ==\n";
$r = new ServicesReport();
$r->view = 'details';
$r->dateFrom = '2026-01-01';
$r->dateTo = '2026-06-30';
$r->filters = ['line' => 'REW 11', 'division' => (string) Modules\Core\Models\Division::where('name', 'Electrical')->value('id')];
$r->search = 'preventive';

$context = $r->reportContext();
$flat = collect($context)->map(fn ($c) => $c[0] . '=' . $c[1])->implode(' | ');
$ok('context lists view, range, filters, search', count($context) >= 5, $flat);
$ok('date range is human, not ISO', str_contains($flat, 'Date range=') && ! str_contains($flat, '2026-01-01'), '');
$ok('division shows its name, not its id', str_contains($flat, 'Division=Electrical'), '');

$export = $r->export('csv');
$raw = file_get_contents($export->getFile()->getPathname());
$rows = $readExport($export);
$ok('csv leads with the context block', ($rows[0][0] ?? '') === 'View', implode(',', $rows[0] ?? []));
// Checked against the file text: the reader drops empty rows, so asking it
// about the separator would prove nothing.
$lines = preg_split('/\r?\n/', $raw);
$headingLine = null;
foreach ($lines as $i => $line) {
    if (str_starts_with($line, '"Job ID"')) {
        $headingLine = $i;
        break;
    }
}
$ok('a blank line separates context from the table',
    $headingLine !== null && trim($lines[$headingLine - 1] ?? 'x', " \"") === '',
    'heading at line ' . var_export($headingLine, true));
$ok('every context entry precedes it', $headingLine === count($context) + 1, "context=" . count($context));

echo "\n== print payload carries it too ==\n";
$payload = $r->reportPayload();
$ok('print payload has context', ! empty($payload['context']), count($payload['context'] ?? []) . ' entries');
$html = view('core::print.grid', $payload)->render();
$ok('print page renders the filters', str_contains($html, 'Date range') && str_contains($html, 'REW 11'), '');

echo "\n== a report with no filters set ==\n";
$clean = new ServicesReport();
$clean->view = 'details';
$clean->dateFrom = '2026-08-01';
$clean->dateTo = '2026-08-07';
$c2 = $clean->reportContext();
$ok('still records view + range', count($c2) === 2, collect($c2)->map(fn ($c) => $c[0])->implode(', '));

echo "\n== raw materials report (unchanged code path) ==\n";
$rm = new SupplierDeliveries();
$rm->dateFrom = '2026-07-01';
$rm->dateTo = '2026-08-07';
$rmRows = $readExport($rm->export('csv'));
$ok('rm export carries context', ($rmRows[0][0] ?? '') === 'View' || ($rmRows[0][0] ?? '') === 'Date range', implode(',', $rmRows[0] ?? []));

echo "\n== drill-down modal: search, paging, export ==\n";
$t = Livewire::test(ServicesReport::class)
    ->set('view', 'by_project')->set('dateFrom', '2019-01-01')->set('dateTo', '2026-12-31');
$group = collect($t->viewData('rows'))->first(fn ($x) => (int) $x->jobs > 60);
$key = $t->instance()->detailKeyFor($group);
$t->call('openRowDetails', $key);

$page1 = $t->instance()->detailRows($key);
$ok('modal returns a paginator', $page1 instanceof Illuminate\Contracts\Pagination\LengthAwarePaginator, get_class($page1));
$ok('first page is one page long', $page1->count() === 10 && $page1->total() === (int) $group->jobs,
    $page1->count() . ' of ' . $page1->total() . ' (group says ' . $group->jobs . ')');

$t->call('detailGotoPage', 2);
$page2 = $t->instance()->detailRows($key);
$ok('page 2 holds different records', $page2->currentPage() === 2 && $page2->first()->id !== $page1->first()->id,
    'p1 starts ' . $page1->first()->jobid . ', p2 starts ' . $page2->first()->jobid);

$t->set('detailPerPage', 25);
$ok('page size applies and resets to page 1',
    $t->instance()->detailRows($key)->count() === 25 && $t->get('detailPage') === 1);

$term = mb_substr((string) $page1->first()->jobtitle, 0, 6);
$t->set('detailSearch', $term);
$searched = $t->instance()->detailRows($key);
$ok('search narrows the group', $searched->total() > 0 && $searched->total() <= $page1->total(),
    "\"$term\" → {$searched->total()} of {$page1->total()}");

$noise = $t->set('detailSearch', 'zzz-no-such-record');
$ok('a search with no hits returns nothing', $t->instance()->detailRows($key)->total() === 0);
$t->set('detailSearch', '');

$t->call('openRowDetails', $key);
$ok('reopening resets search and page', $t->get('detailSearch') === '' && $t->get('detailPage') === 1);

echo "\n== drill-down export ==\n";
$d = new ServicesReport();
$d->view = 'by_project';
$d->dateFrom = '2019-01-01';
$d->dateTo = '2026-12-31';
$d->detailMode = true;
$d->detailKey = $key;

$dRows = $readExport($d->export('csv'));
$dContext = $d->reportContext();
$ok('detail csv leads with the group identity', ($dRows[0][0] ?? '') === 'Line', implode('=', $dRows[0] ?? []));
$ok('and then the report filters', collect($dRows)->contains(fn ($r) => ($r[0] ?? '') === 'Date range'), '');
$headingRow = collect($dRows)->search(fn ($r) => ($r[0] ?? '') === 'Job ID');
$ok('detail export has the job columns', $headingRow !== false, 'heading row at ' . var_export($headingRow, true));
$dataRows = count($dRows) - $headingRow - 1;
$ok('detail export holds EVERY record, not one page', $dataRows === (int) $group->jobs,
    "$dataRows rows vs {$group->jobs} jobs");

$dp = $d->reportPayload();
$ok('detail print payload titled by the group', str_contains($dp['label'], $d->detailTitle($key)), $dp['label']);

echo "\n== statistics export context ==\n";
$st = new Modules\Bil\Livewire\Machines\Statistics();
$st->section = 'downtime';
$st->range = '12m';
$stRows = $readExport($st->exportResponse('csv'));
$ok('statistics csv names the section and period',
    ($stRows[0][0] ?? '') === 'Section' && ($stRows[1][1] ?? '') === 'Last 12 months',
    implode(' / ', array_map(fn ($r) => implode('=', $r), array_slice($stRows, 0, 3))));

echo PHP_EOL, $fail === 0 ? "ALL CHECKS PASSED\n" : "$fail CHECK(S) FAILED\n";
