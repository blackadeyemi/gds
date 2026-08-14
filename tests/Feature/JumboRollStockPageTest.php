<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bil\Livewire\JumboRolls\Stock;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BIL → Jumbo Rolls → Stock: the route, the `page:` middleware, the three
 * places a reel can be standing, and that the summaries agree with the
 * reel-level list. Read-only.
 */
class JumboRollStockPageTest extends TestCase
{
    protected function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u, 'no admin user in core.user');

        return $u;
    }

    protected function outsider(): User
    {
        $u = User::whereDoesntHave('roles', fn ($q) => $q->where('legacy_level', 1))
            ->get()
            ->first(fn (User $u) => ! $u->canAccessPage('bil.jumbo_rolls.stock'));
        $this->assertNotNull($u, 'no non-admin user without the page');

        return $u;
    }

    /** Rows of one view, run outside Livewire. */
    protected function rows(string $view, array $filters = [])
    {
        $c = new Stock();
        $c->view = $view;
        $c->filters = $filters;

        return $c->views()[$view]['query']()->get();
    }

    public function test_admin_can_open_the_page(): void
    {
        $res = $this->actingAs($this->admin())->get('/bil/jumbo-rolls/stock');

        $res->assertOk();
        $res->assertSee('Jumbo Roll Stock');
        $res->assertSee('Hardroll Number');
        $res->assertSee('Grade Type');
    }

    public function test_a_user_without_the_page_is_refused(): void
    {
        $this->actingAs($this->outsider())
            ->get('/bil/jumbo-rolls/stock')
            ->assertForbidden();
    }

    public function test_it_is_a_snapshot_with_no_row_actions(): void
    {
        $c = new Stock();

        $this->assertFalse($c->hasDateRange(), 'stock is a snapshot, not a date range');
        $this->assertTrue($c->readOnly());
    }

    /**
     * The union covers all three places a jumbo roll can be standing, and
     * reports nothing outside them.
     *
     * Asserted as shape rather than "each place has stock": a place is legitimately
     * empty when nothing is standing there. The BPL stores emptied on 2026-08-14
     * when the pre-go-live rows were closed off, and an empty warehouse must not
     * read as a broken report. test_each_leg_matches_its_source_table() is what
     * proves each leg is actually wired up, empty or not.
     */
    public function test_stock_spans_bil_factories_bpl_factories_and_bpl_warehouses(): void
    {
        $known = [Stock::AT_BIL_FACTORY, Stock::AT_BPL_FACTORY, Stock::AT_BPL_WAREHOUSE];
        $places = $this->rows('by_location')->pluck('place')->unique()->values()->all();

        $this->assertNotEmpty($places, 'no jumbo roll stock at all');
        $this->assertEmpty(array_diff($places, $known), 'stock reported somewhere unrecognised');

        // The place filter offers exactly the places that hold stock right now.
        $this->assertSame(
            array_values(array_intersect($known, $places)),
            array_values(array_intersect($known, array_keys((new Stock())->filterDefs()['place']['options'])))
        );
    }

    /**
     * Reels the old system left flagged as sitting in a PM store are closed off,
     * not counted (migration 2026_08_14_140000).
     */
    public function test_pre_golive_store_entrances_are_not_stock(): void
    {
        $stale = DB::connection('bil')->table('jumboreel_storeentrance as se')
            ->join('bpl_production as prod', 'prod.barcode', '=', 'se.barcode')
            ->whereNull('se.status')
            ->where('se.dateofentrance', '<', '2021/03/01')
            ->where('prod.customer_id', (int) config('bil.jumbo_roll_customer_id'))
            ->count();

        $this->assertSame(0, $stale, 'pre-go-live store entrances are back in stock');
    }

    /** Each leg matches a direct count against the table that defines it. */
    public function test_each_leg_matches_its_source_table(): void
    {
        $bil = DB::connection('bil');
        $customer = (int) config('bil.jumbo_roll_customer_id');
        $byPlace = $this->rows('by_location')->groupBy('place')
            ->map(fn ($g) => (int) $g->sum('quantity'));

        $onBilFloor = $bil->table('factory_entrance_reel')
            ->where('is_deleted', 0)
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'mid'))
            ->count();
        $this->assertSame($onBilFloor, $byPlace[Stock::AT_BIL_FACTORY] ?? 0);

        $atMachine = $bil->table('bpl_production')
            ->where('customer_id', $customer)->whereNull('deleted_at')->whereNull('status')
            ->count();
        $this->assertSame($atMachine, $byPlace[Stock::AT_BPL_FACTORY] ?? 0);

        $inStore = $bil->table('jumboreel_storeentrance as se')
            ->join('bpl_production as prod', 'prod.barcode', '=', 'se.barcode')
            ->whereNull('se.status')
            ->where('prod.customer_id', $customer)->whereNull('prod.deleted_at')
            ->count();
        $this->assertSame($inStore, $byPlace[Stock::AT_BPL_WAREHOUSE] ?? 0);
    }

    /**
     * A reel stands in exactly one place. Each leg is defined by a different
     * table's flag, so nothing structurally stops the same barcode qualifying
     * twice — this is what would catch it.
     */
    public function test_a_reel_is_never_in_two_places_at_once(): void
    {
        $barcodes = $this->rows('default')->pluck('barcode');

        $this->assertSame(
            $barcodes->count(),
            $barcodes->unique()->count(),
            'the same reel is reported standing in two places'
        );
    }

    /** Every row is aged, so a reel standing too long is visible without reading dates. */
    public function test_every_reel_is_aged(): void
    {
        $rows = $this->rows('default');

        $this->assertNotEmpty($rows);

        foreach ($rows->take(25) as $row) {
            $this->assertIsNumeric($row->days, 'row has no age');
            $this->assertGreaterThanOrEqual(0, (int) $row->days);
        }
    }

    /** Every summary is a different cut of the same set, so the totals agree. */
    public function test_the_summaries_agree_with_the_reel_list(): void
    {
        $reels = $this->rows('default');
        $expectedCount = $reels->count();
        $expectedWeight = round((float) $reels->sum('weight'), 2);

        foreach (['by_location', 'by_grade', 'by_product'] as $view) {
            $rows = $this->rows($view);
            $this->assertSame($expectedCount, (int) $rows->sum('quantity'), "{$view} reel count");
            $this->assertEqualsWithDelta($expectedWeight, (float) $rows->sum('weight'), 0.5, "{$view} weight");
        }
    }

    /**
     * A part-used reel is carried at what is left, not at what BPL made — the
     * one number this page computes rather than reads.
     */
    public function test_a_part_used_reel_is_carried_at_its_remaining_weight(): void
    {
        $mid = DB::connection('bil')->table('factory_entrance_reel')
            ->where('is_deleted', 0)->where('status', 'mid')->value('barcode');

        if (! $mid) {
            $this->markTestSkipped('no part-used reel on a factory floor right now');
        }

        $row = $this->rows('default')->firstWhere('barcode', $mid);
        $this->assertNotNull($row, 'a mid reel should appear in stock');

        $made = (float) DB::connection('bil')->table('bpl_production')
            ->where('barcode', $mid)->value('weight');
        $used = (float) DB::connection('bil')->table('factory_usage_reel')
            ->where('reel_barcode', $mid)->where('is_deleted', 0)->sum('weight');
        $returned = (float) DB::connection('bil')->table('factory_event')
            ->where('reel_barcode', $mid)->where('event', 'return')->sum('weight');

        $this->assertEqualsWithDelta(round($made - $used - $returned, 2), (float) $row->weight, 0.01);
        $this->assertLessThan($made, (float) $row->weight, 'a part-used reel should weigh less than it was made');
    }

    /** Filters narrow the set, and their options come from the stock itself. */
    public function test_filtering_by_place_narrows_the_set(): void
    {
        $all = $this->rows('default')->count();
        $bilOnly = $this->rows('default', ['place' => Stock::AT_BIL_FACTORY]);

        $this->assertGreaterThan(0, $bilOnly->count());
        $this->assertLessThan($all, $bilOnly->count());
        $this->assertSame([Stock::AT_BIL_FACTORY], $bilOnly->pluck('place')->unique()->values()->all());
    }

    public function test_filter_options_are_drawn_from_the_stock_not_the_master_tables(): void
    {
        Livewire::actingAs($this->admin());
        $defs = (new Stock())->filterDefs();

        $this->assertSame(['place', 'location', 'gradetype', 'product'], array_keys($defs));

        $products = array_keys($defs['product']['options']);
        $allProducts = DB::connection('bil')->table('bpl_products')->count();

        $this->assertNotEmpty($products);
        $this->assertLessThan($allProducts, count($products), 'product filter should list only what is in stock');
    }

    /** Summary rows drill down to the reels behind them. */
    public function test_a_summary_row_drills_down_to_its_reels(): void
    {
        $c = new Stock();
        $c->view = 'by_location';

        $row = $c->views()['by_location']['query']()->get()->first();
        $this->assertNotNull($row);

        $this->assertSame(['place', 'location'], $c->expandableBy());

        $reels = $c->detailQuery($c->detailKeyFor($row))->get();
        $this->assertCount((int) $row->quantity, $reels);
        $this->assertSame([$row->location], $reels->pluck('location')->unique()->values()->all());
    }

    public function test_export_and_print_are_gated_on_the_export_ability(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/bil/jumbo-rolls/reports/stock/print')->assertOk();
        $this->actingAs($admin)->get('/bil/jumbo-rolls/reports/stock/download?format=csv')->assertOk();
        $this->actingAs($admin)->get('/bil/jumbo-rolls/reports/stock/download?format=zip')->assertNotFound();
        $this->actingAs($admin)->get('/bil/jumbo-rolls/reports/nope/print')->assertNotFound();

        $this->actingAs($this->outsider())
            ->get('/bil/jumbo-rolls/reports/stock/print')
            ->assertForbidden();
    }

    public function test_the_page_is_registered(): void
    {
        $page = collect(config('pages.pages'))->firstWhere('key', 'bil.jumbo_rolls.stock');

        $this->assertNotNull($page, 'page not declared in config/pages.php');
        $this->assertSame(['view', 'export'], $page['abilities']);
        $this->assertSame('bil.jumbo-rolls.stock', $page['route']);
    }
}
