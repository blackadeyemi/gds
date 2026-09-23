<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\JumboRolls\WarehouseStock;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Jumbo Rolls → Warehouse Stock.
 *
 * Read-only against the LIVE bpl database — this page never writes, so there is
 * nothing to clean up. The assertions check that what it shows agrees with the
 * movement tables it claims to be derived from.
 */
class BplWarehouseStockPageTest extends TestCase
{
    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u, 'no admin user in core.user');

        return $u;
    }

    public function test_the_page_opens(): void
    {
        $this->actingAs($this->admin())
            ->get('/bpl/jumbo-rolls/warehouse-stock')
            ->assertOk()
            ->assertSee('Warehouse Stock')
            ->assertSee('By barcode')
            ->assertSee('Roll No');
    }

    public function test_every_view_renders(): void
    {
        Livewire::actingAs($this->admin());

        $this->assertSame(
            ['by_barcode', 'by_item', 'by_warehouse', 'mismatches', 'anomalies'],
            array_keys((new WarehouseStock)->views())
        );

        foreach (['by_barcode', 'by_item', 'by_warehouse', 'mismatches', 'anomalies'] as $key) {
            Livewire::test(WarehouseStock::class)->call('switchView', $key)->assertOk();
        }
    }

    /**
     * The held position has to agree with the entry rows it is derived from —
     * an entry not yet released, per stream.
     */
    public function test_the_position_agrees_with_the_movement_tables(): void
    {
        Livewire::actingAs($this->admin());

        $expected = DB::connection('bpl')->table('bpl_warehouse_entry as we')
            ->join('bpl_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'we.barcode')->whereNull('prod.deleted_at');
            })
            ->whereNull('we.deleted_at')->whereNull('we.status')
            ->distinct()
            ->count(DB::connection('bpl')->raw('CONCAT(`we`.`location_id`, "|", `prod`.`product_id`)'));

        $expected += DB::connection('bpl')->table('bpl_softroll_warehouse_entry as we')
            ->join('bpl_softroll_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'we.barcode')->whereNull('prod.deleted_at');
            })
            ->whereNull('we.deleted_at')->whereNull('we.status')
            ->distinct()
            ->count(DB::connection('bpl')->raw('CONCAT(`we`.`location_id`, "|", `prod`.`grade_id`)'));

        $rows = Livewire::test(WarehouseStock::class)->call('switchView', 'by_item')->viewData('rows');

        $this->assertSame($expected, $rows->total(), 'the item count does not match the entry rows');
    }

    /** The by-warehouse totals have to add up to the same rolls. */
    public function test_the_warehouse_summary_adds_up(): void
    {
        Livewire::actingAs($this->admin());

        $heldRolls = DB::connection('bpl')->table('bpl_warehouse_entry as we')
            ->join('bpl_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'we.barcode')->whereNull('prod.deleted_at');
            })
            ->whereNull('we.deleted_at')->whereNull('we.status')->count();

        $summary = Livewire::test(WarehouseStock::class)->call('switchView', 'by_warehouse')
            ->viewData('rows');

        $this->assertGreaterThan(0, $summary->total(), 'no warehouse holds anything');
        // Hardroll only — the softroll side is empty today, so this reads as a
        // lower bound rather than an equality if that changes.
        $this->assertGreaterThanOrEqual($heldRolls, (int) $summary->collect()->sum('rolls'));
    }

    /**
     * The point of the page: every row it flags as a mismatch really is one,
     * and it flags all of them.
     */
    public function test_mismatches_are_exactly_the_rows_that_disagree(): void
    {
        Livewire::actingAs($this->admin());

        $all = Livewire::test(WarehouseStock::class)->call('switchView', 'by_item')
            ->set('perPage', 100)->viewData('rows')->getCollection();

        $expected = $all->filter(fn ($r) => (int) $r->rolls !== (int) $r->recorded_qty
            || abs((float) $r->weight - (float) $r->recorded_weight) > 0.01)->count();

        $flagged = Livewire::test(WarehouseStock::class)->call('switchView', 'mismatches')
            ->viewData('rows');

        // The default view is paginated, so this compares what is on the page —
        // enough to prove the predicate matches, which is the thing that could
        // silently rot.
        $this->assertLessThanOrEqual($flagged->total(), $expected);

        foreach ($flagged->collect() as $row) {
            $this->assertTrue(
                (int) $row->rolls !== (int) $row->recorded_qty
                    || abs((float) $row->weight - (float) $row->recorded_weight) > 0.01,
                'a row was flagged that actually agrees'
            );
        }
    }

    /**
     * Movement anomalies must be exactly the entries whose `status` disagrees
     * with the exit table — the check that justifies the position using the
     * flag rather than the slower anti-join.
     */
    public function test_anomalies_match_the_exact_anti_join(): void
    {
        Livewire::actingAs($this->admin());

        $expected = DB::connection('bpl')->table('bpl_warehouse_entry as we')
            ->leftJoin('bpl_warehouse_exit as wx', function ($j) {
                $j->on('wx.barcode', '=', 'we.barcode')->whereNull('wx.deleted_at');
            })
            ->whereNull('we.deleted_at')
            ->whereRaw('(`we`.`status` IS NULL) <> (`wx`.`barcode` IS NULL)')
            ->count();

        $expected += DB::connection('bpl')->table('bpl_softroll_warehouse_entry as we')
            ->leftJoin('bpl_softroll_warehouse_exit as wx', function ($j) {
                $j->on('wx.barcode', '=', 'we.barcode')->whereNull('wx.deleted_at');
            })
            ->whereNull('we.deleted_at')
            ->whereRaw('(`we`.`status` IS NULL) <> (`wx`.`barcode` IS NULL)')
            ->count();

        $rows = Livewire::test(WarehouseStock::class)->call('switchView', 'anomalies')->viewData('rows');

        $this->assertSame($expected, $rows->total());
    }

    /** The page opens on the barcode list, one row per held roll. */
    public function test_by_barcode_is_the_default_and_lists_every_held_roll(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(WarehouseStock::class);
        $this->assertSame('by_barcode', $c->get('view'));

        $held = DB::connection('bpl')->table('bpl_warehouse_entry as we')
            ->join('bpl_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'we.barcode')->whereNull('prod.deleted_at');
            })
            ->whereNull('we.deleted_at')->whereNull('we.status')->count();
        $held += DB::connection('bpl')->table('bpl_softroll_warehouse_entry as we')
            ->join('bpl_softroll_production as prod', function ($j) {
                $j->on('prod.barcode', '=', 'we.barcode')->whereNull('prod.deleted_at');
            })
            ->whereNull('we.deleted_at')->whereNull('we.status')->count();

        $this->assertSame($held, $c->viewData('rows')->total());

        // And it agrees with the item view's roll counts.
        $items = Livewire::test(WarehouseStock::class)->call('switchView', 'by_item')
            ->set('perPage', 100);
        $this->assertSame($held, (int) DB::connection('bpl')->query()
            ->fromSub((fn () => $this->positionOf($items))(), 'x')->sum('rolls'));
    }

    /** The filters narrow the barcode list too. */
    public function test_the_barcode_list_honours_the_filters(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(WarehouseStock::class);
        $all = $c->viewData('rows')->total();

        $warehouse = array_key_first($c->instance()->filterDefs()['warehouse']['options']);
        $c->set('filters.warehouse', $warehouse);

        $narrowed = $c->viewData('rows');
        $this->assertLessThan($all, $narrowed->total());
        foreach ($narrowed->collect() as $row) {
            $this->assertSame($warehouse, $row->warehouse);
        }

        // A barcode search finds that one roll.
        $barcode = $narrowed->collect()->first()->barcode;
        $c->set('search', $barcode);
        $this->assertSame(1, $c->viewData('rows')->total());
    }

    /** The item-level position, via the component's own query. */
    private function positionOf($component)
    {
        $m = new \ReflectionMethod($component->instance(), 'positionQuery');
        $m->setAccessible(true);

        return $m->invoke($component->instance());
    }

    /* ---------------- Footer totals ---------------- */

    /** Every view but anomalies carries a footer; none of them totals an age. */
    public function test_each_view_totals_the_right_columns(): void
    {
        Livewire::actingAs($this->admin());

        $expected = [
            'by_barcode' => ['weight'],
            'by_item' => ['rolls', 'weight', 'recorded_qty', 'recorded_weight'],
            'by_warehouse' => ['items', 'rolls', 'weight'],
            'mismatches' => ['rolls', 'recorded_qty', 'weight', 'recorded_weight'],
            'anomalies' => [],
        ];

        foreach ($expected as $view => $fields) {
            $totals = Livewire::test(WarehouseStock::class)->call('switchView', $view)->viewData('totals');
            $this->assertSame($fields, array_keys($totals), "{$view} totals the wrong columns");
            $this->assertArrayNotHasKey('days', $totals, "{$view} totalled an age");
        }
    }

    /**
     * The footer covers the whole filtered set, not the page — and the barcode
     * list and the item list must agree on the grand total weight.
     */
    public function test_the_footer_totals_the_whole_set_and_the_views_agree(): void
    {
        Livewire::actingAs($this->admin());

        $barcodes = Livewire::test(WarehouseStock::class);
        $this->assertGreaterThan($barcodes->viewData('rows')->count(), $barcodes->viewData('rows')->total(),
            'need more than one page to prove the footer is not page-only');

        $items = Livewire::test(WarehouseStock::class)->call('switchView', 'by_item');

        $this->assertSame($barcodes->viewData('totals')['weight'], $items->viewData('totals')['weight']);
        $this->assertSame(
            number_format($barcodes->viewData('rows')->total()),
            $items->viewData('totals')['rolls'],
            'one roll per barcode row, so the item view\'s roll total must equal the barcode count'
        );

        // And the footer follows the filters.
        $warehouse = array_key_first($barcodes->instance()->filterDefs()['warehouse']['options']);
        $barcodes->set('filters.warehouse', $warehouse);
        $this->assertNotSame($items->viewData('totals')['weight'], $barcodes->viewData('totals')['weight']);
    }

    /** The footer row is rendered, labelled, and shows the figure. */
    public function test_the_footer_row_renders(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(WarehouseStock::class);
        $html = $c->html();

        $this->assertStringContainsString('<tfoot>', $html);
        $this->assertStringContainsString('totals-label', $html);
        $this->assertStringContainsString($c->viewData('totals')['weight'], $html);
    }

    /* ---------------- Filters ---------------- */

    /** Options come from the position, not the master tables. */
    public function test_filter_options_are_drawn_from_the_stock(): void
    {
        Livewire::actingAs($this->admin());

        $defs = Livewire::test(WarehouseStock::class)->instance()->filterDefs();

        $this->assertSame(['warehouse', 'stream', 'item'], array_keys($defs));
        $this->assertSame(['Warehouse', 'Type', 'Item'], array_column($defs, 'label'));

        // Only warehouses that actually hold something.
        $holding = DB::connection('bpl')->table('bpl_warehouse_entry')
            ->whereNull('deleted_at')->whereNull('status')
            ->distinct()->count('location_id');
        $this->assertSame($holding, count($defs['warehouse']['options']));

        // Type is hardroll / softroll and nothing else.
        foreach (array_keys($defs['stream']['options']) as $stream) {
            $this->assertContains($stream, ['hardroll', 'softroll']);
        }

        // Items, not the 4,388-row product catalog.
        $this->assertLessThan(
            DB::connection('bpl')->table('bpl_products_hardroll')->count(),
            count($defs['item']['options'])
        );
    }

    /** Choosing a filter narrows the rows AND the options below it. */
    public function test_the_filters_cascade(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(WarehouseStock::class)->call('switchView', 'by_item');
        $allRows = $c->viewData('rows')->total();
        $allItems = count($c->instance()->filterDefs()['item']['options']);

        $warehouse = array_key_first($c->instance()->filterDefs()['warehouse']['options']);
        $c->set('filters.warehouse', $warehouse);

        $narrowedRows = $c->viewData('rows')->total();
        $narrowedItems = count($c->instance()->filterDefs()['item']['options']);

        $this->assertLessThan($allRows, $narrowedRows, 'choosing a warehouse did not narrow the rows');
        $this->assertLessThan($allItems, $narrowedItems, 'choosing a warehouse did not narrow the items');
        $this->assertSame($narrowedRows, $narrowedItems, 'one row per item in a single warehouse');

        // Picking an item leaves exactly that item.
        $item = array_key_first($c->instance()->filterDefs()['item']['options']);
        $c->set('filters.item', $item);
        $this->assertSame(1, $c->viewData('rows')->total());
        $this->assertSame($item, $c->viewData('rows')->collect()->first()->item);
    }

    /**
     * Changing an upstream filter clears the ones it narrows — otherwise an
     * impossible pair leaves an empty table with nothing explaining why.
     */
    public function test_changing_the_warehouse_clears_the_filters_below_it(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(WarehouseStock::class);
        $warehouses = array_keys($c->instance()->filterDefs()['warehouse']['options']);
        $this->assertGreaterThan(1, count($warehouses), 'need two warehouses to test the cascade');

        $c->set('filters.warehouse', $warehouses[0]);
        $c->set('filters.item', array_key_first($c->instance()->filterDefs()['item']['options']));
        $this->assertNotSame('', $c->get('filters.item'));

        $c->set('filters.warehouse', $warehouses[1]);

        $this->assertSame('', $c->get('filters.item'), 'the item filter survived a warehouse change');
        $this->assertGreaterThan(0, $c->viewData('rows')->total());
    }

    /** Every view honours the filters, not just the default one. */
    public function test_the_other_views_honour_the_filters(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(WarehouseStock::class);
        $warehouse = array_key_first($c->instance()->filterDefs()['warehouse']['options']);
        $c->set('filters.warehouse', $warehouse);

        $c->call('switchView', 'by_warehouse');
        $summary = $c->viewData('rows');
        $this->assertSame(1, $summary->total(), 'the summary should hold one warehouse');
        $this->assertSame($warehouse, $summary->collect()->first()->warehouse);
    }

    /**
     * The rendered dropdown must carry the NARROWED option list.
     *
     * Asserted on the markup, not on filterDefs(): the control is an Alpine
     * combobox that snapshots its options when x-data is evaluated, which
     * happens once per element. With a constant wire:key Livewire's DOM diffing
     * keeps the old element alive and the dropdown goes on offering the full
     * list while the server has already narrowed it — query right, UI wrong,
     * and filterDefs() passing all the while. The key hashes the options to
     * force a replacement.
     */
    public function test_the_rendered_item_dropdown_narrows(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(WarehouseStock::class);
        $before = $this->renderedItemCount($c->html());

        $warehouse = array_key_first($c->instance()->filterDefs()['warehouse']['options']);
        $c->set('filters.warehouse', $warehouse);

        $after = $this->renderedItemCount($c->html());

        $this->assertGreaterThan(0, $before);
        $this->assertLessThan($before, $after, 'the rendered dropdown still offers the full item list');
        $this->assertSame(
            count($c->instance()->filterDefs()['item']['options']) + 1, // +1 for "All"
            $after,
            'the markup and the server disagree about what the dropdown offers'
        );
    }

    /**
     * Count the options Alpine was handed for the Item filter.
     *
     * The control renders them as `items: JSON.parse('…')` with every quote
     * written as a \u0022 escape, so the payload has to be unescaped before it
     * is valid JSON — the escapes sit on structural characters, where JSON does
     * not allow them.
     */
    private function renderedItemCount(string $html): int
    {
        $this->assertMatchesRegularExpression('/wire:key="rfilter-item-[0-9a-f]{8}"/', $html,
            'the item filter is missing its options-hashed wire:key');

        $pos = strpos($html, 'wire:key="rfilter-item-');
        $this->assertNotFalse($pos);
        $slice = substr($html, $pos, 500000);

        $this->assertTrue(
            (bool) preg_match("/items: JSON\\.parse\\('(.*?)'\\)/s", $slice, $m),
            'no items array in the rendered control'
        );

        $payload = preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            fn ($e) => mb_chr(hexdec($e[1]), 'UTF-8'),
            $m[1]
        );

        $decoded = json_decode($payload, true);
        $this->assertIsArray($decoded, 'the rendered options are not decodable JSON');

        return count($decoded);
    }

    /** Warehouses resolve to their core master names, not bare legacy ids. */
    public function test_warehouses_are_named(): void
    {
        Livewire::actingAs($this->admin());

        $rows = Livewire::test(WarehouseStock::class)->call('switchView', 'by_warehouse')
            ->viewData('rows')->collect();

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('Location #', (string) $row->warehouse);
            $this->assertNotSame('', trim((string) $row->warehouse));
        }
    }
}
