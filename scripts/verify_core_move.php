<?php

/**
 * Verifies 2026_08_28_150000_move_bil_tables_out_of_core: the six tables are in
 * `bil` with every row intact, the foreign keys are what they should be, and
 * every page that reads them still renders.
 *
 * Run with a before-snapshot for the row/aggregate comparison:
 *
 *   php scripts/verify_core_move.php --snapshot     (BEFORE the migration)
 *   php scripts/verify_core_move.php                (after)
 *
 * The snapshot goes to storage/app/core_move_before.json; without it the
 * content checks are skipped and say so.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;

/** table => the aggregate that proves its contents came across unchanged. */
const MOVED = [
    'finished_goods_warehouse_receipts' => 'SUM(bundles)',
    'finished_goods_warehouse_stock' => 'SUM(bundles)',
    'finished_goods_stock_adjustments' => 'SUM(bundles)',
    'raw_materials_warehouse_stock' => 'SUM(quantity)',
    'conversion_waste_runs' => 'COUNT(*)',
    'conversion_waste_entries' => 'SUM(weight_kg)',
];

$snapshotFile = __DIR__ . '/../storage/app/core_move_before.json';

if (in_array('--snapshot', $argv, true)) {
    $out = [];

    foreach (MOVED as $table => $agg) {
        $r = DB::connection('core')->selectOne("SELECT COUNT(*) n, $agg v FROM `$table`");
        $out[$table] = ['rows' => (int) $r->n, 'agg' => (string) $r->v];
        printf("  %-36s %10s rows   %s = %s\n", $table, number_format($r->n), $agg, $r->v);
    }

    file_put_contents($snapshotFile, json_encode($out));
    echo "\nSnapshot written — now run the migration.\n";
    exit(0);
}

$before = is_file($snapshotFile) ? json_decode(file_get_contents($snapshotFile), true) : null;
$fail = 0;
$skipped = 0;

$check = function (string $label, bool $ok, string $detail = '') use (&$fail) {
    printf("  [%s] %-50s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail);

    if (! $ok) {
        $fail++;
    }
};

echo "== 1. the tables are in bil, and gone from core ==\n";
foreach (array_keys(MOVED) as $table) {
    $inBil = DB::connection('core')->selectOne(
        'SELECT 1 ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', ['bil', $table]);
    $inCore = DB::connection('core')->selectOne(
        'SELECT 1 ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', ['core', $table]);
    $check($table, (bool) $inBil && ! $inCore, $inBil ? ($inCore ? 'STILL IN CORE TOO' : 'bil') : 'missing');
}

echo "== 2. every row came across ==\n";
foreach (MOVED as $table => $agg) {
    if (! $before) {
        printf("  [--] %-50s no before-snapshot\n", $table);
        $skipped++;

        continue;
    }

    $r = DB::connection('bil')->selectOne("SELECT COUNT(*) n, $agg v FROM `$table`");
    $was = $before[$table];
    $check($table,
        (int) $r->n === (int) $was['rows'] && (string) $r->v === (string) $was['agg'],
        number_format($r->n) . ' rows, ' . $agg . ' = ' . $r->v);
}

echo "== 3. foreign keys ==\n";
$fks = DB::connection('core')->select(
    "SELECT CONSTRAINT_NAME n, TABLE_SCHEMA ts, REFERENCED_TABLE_SCHEMA rs, REFERENCED_TABLE_NAME rt
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_NAME = 'conversion_waste_entries' AND REFERENCED_TABLE_NAME IS NOT NULL");
$byName = [];
foreach ($fks as $f) {
    $byName[$f->n] = "{$f->ts} -> {$f->rs}.{$f->rt}";
}
$check('run_id still constrained, inside bil',
    ($byName['conversion_waste_entries_run_id_foreign'] ?? '') === 'bil -> bil.conversion_waste_runs',
    $byName['conversion_waste_entries_run_id_foreign'] ?? 'missing');
$check('no cross-schema keys left behind',
    ! isset($byName['conversion_waste_entries_cause_id_foreign'], $byName['conversion_waste_entries_origin_id_foreign']),
    implode(', ', array_keys($byName)));

$idx = DB::connection('bil')->select(
    "SELECT DISTINCT INDEX_NAME n FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = 'bil' AND TABLE_NAME = 'conversion_waste_entries'");
$names = array_map(fn ($i) => $i->n, $idx);
$check('the dropped keys kept their indexes',
    in_array('cwe_cause_idx', $names, true)
    && in_array('conversion_waste_entries_origin_id_foreign', $names, true),
    implode(', ', $names));

echo "== 4. cross-schema reads still resolve ==\n";
$named = DB::connection('bil')->table('finished_goods_warehouse_stock as s')
    ->leftJoin('core.warehouses as w', 's.warehouse_id', '=', 'w.id')
    ->whereNotNull('w.id')->count();
$check('stock joins core.warehouses', $named > 0, "$named rows matched a warehouse");

$waste = DB::connection('bil')->table('conversion_waste_entries as e')
    ->join('conversion_waste_runs as r', 'e.run_id', '=', 'r.id')
    ->leftJoin('core.waste_causes as c', 'e.cause_id', '=', 'c.id')
    ->whereNotNull('c.id')->count();
$check('waste entries join core.waste_causes', $waste > 0, "$waste entries named a cause");

// The claim the two migrations were built on — that the product master "cannot
// be joined" — was never true, and after the move it is not even cross-schema.
// 2026_08_28_160000 then dropped the denormalised copy in favour of this join,
// so the Stock grid depends on it resolving.
$joined = DB::connection('bil')->table('finished_goods_warehouse_stock as s')
    ->leftJoin('products as p', 'p.productid', '=', 's.productid')
    ->whereNotNull('p.productname')->count();
$check('stock joins bil.products in one statement', $joined > 0, "$joined rows resolve a product name");

echo "== 5. the pages that read them still render ==\n";
$admin = User::query()->with('roles')->get()->first(fn ($u) => $u->roles->contains('legacy_level', 1));
Livewire::actingAs($admin);

foreach ([
    'FG Stock' => Modules\Bil\Livewire\FinishedGoods\Stock::class,
    'FG Warehouse Entrance' => Modules\Bil\Livewire\FinishedGoods\WarehouseEntrance::class,
    'FG Stock Transfer' => Modules\Bil\Livewire\FinishedGoods\StockTransfer::class,
    'FG Statistics' => Modules\Bil\Livewire\FinishedGoods\Statistics::class,
    'Conversion Waste' => Modules\Bil\Livewire\FinishedGoods\ConversionWaste::class,
    'Report: Warehouse Entrance' => Modules\Bil\Livewire\FinishedGoods\Reports\WarehouseEntrance::class,
    'Report: Conversion Waste' => Modules\Bil\Livewire\FinishedGoods\Reports\ConversionWaste::class,
    'Report: Stock Transfer' => Modules\Bil\Livewire\FinishedGoods\Reports\StockTransfer::class,
    'Admin: Warehouse Gates' => Modules\Core\Livewire\Admin\WarehouseGates::class,
    'Settings: Waste' => Modules\Core\Livewire\Settings\WasteSettings::class,
] as $label => $class) {
    try {
        Livewire::test($class);
        $check($label, true);
    } catch (Throwable $e) {
        $check($label, false, get_class($e) . ': ' . substr($e->getMessage(), 0, 90));
    }
}

echo "== 6. FG Statistics: every section, both idioms ==\n";
$stats = new Modules\Bil\Livewire\FinishedGoods\Statistics();
foreach (array_keys($stats->sections()) as $section) {
    try {
        $c = Livewire::test(Modules\Bil\Livewire\FinishedGoods\Statistics::class)
            ->set('range', 'all')->set('section', $section);
        $check("section $section", count($c->viewData('tiles')) > 0,
            count($c->viewData('tiles')) . ' tiles, ' . count($c->viewData('charts')) . ' charts');
    } catch (Throwable $e) {
        $check("section $section", false, substr($e->getMessage(), 0, 100));
    }
}

echo "== 7. the stock ledger still reconciles ==\n";
// drift() compares what the stock table says against what the receipts and
// adjustments add up to — both tables have just changed schema, so this proves
// the ledger still reconciles across the move.
$drift = Modules\Bil\Support\FinishedGoodsStock::drift();
$expected = Modules\Bil\Support\FinishedGoodsStock::expected();
$check('stored stock matches the receipts it derives from', $drift === [],
    count($drift) . ' of ' . count($expected) . ' warehouse/product keys differ');

echo "\n", $fail === 0 ? 'ALL CHECKS PASSED' : "$fail CHECK(S) FAILED";
echo $skipped ? " ($skipped skipped — no before-snapshot)\n" : "\n";

exit($fail === 0 ? 0 : 1);
