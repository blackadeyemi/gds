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

        $inStore = $bil->table('bpl_storeentrance as se')
            ->join('bpl_production as prod', 'prod.barcode', '=', 'se.barcode')
            ->whereNull('se.status')->whereNull('se.deleted_at')
            ->where('prod.customer_id', $customer)->whereNull('prod.deleted_at')
            ->count();
        $this->assertSame($inStore, $byPlace[Stock::AT_BPL_WAREHOUSE] ?? 0);
    }

    /**
     * BPL holds a large amount of BIL's stock in its own warehouses, and it is
     * read from the LIVE store table.
     *
     * `jumboreel_storeentrance` was the dead 2018-19 route; reading it instead
     * (as this page originally did) reported zero and hid ~700 tonnes. That
     * whole route has since been dropped from `bil`/`bpl`/`core`, so a leg
     * reading it would now fail loudly rather than quietly — but the position
     * itself is what this guards.
     */
    public function test_bpl_warehouse_stock_comes_from_the_live_store_table(): void
    {
        $inStore = $this->rows('by_location')->where('place', Stock::AT_BPL_WAREHOUSE);

        $this->assertNotEmpty($inStore, 'no BIL stock reported in a BPL warehouse');
        $this->assertGreaterThan(0, (int) $inStore->sum('quantity'));

        // Every store it reports must be a real BPL store location.
        $stores = DB::connection('bpl')->table('bpl_stock_locations')->where('type', 1)->pluck('id');
        $held = DB::connection('bil')->table('bpl_storeentrance')
            ->whereNull('status')->whereNull('deleted_at')
            ->whereIn('location_id', $stores)->count();

        $this->assertGreaterThan(0, $held);
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

    /**
     * The filters cascade: Where narrows Location, Location narrows Grade Type,
     * Grade Type narrows Product. Each dropdown only offers what the choices
     * above it leave standing.
     */
    public function test_the_filters_cascade(): void
    {
        Livewire::actingAs($this->admin());
        $c = Livewire::test(Stock::class);
        $optionsFor = fn (string $filter) => array_keys($c->instance()->filterDefs()[$filter]['options']);

        $allLocations = $optionsFor('location');
        $allProducts = $optionsFor('product');

        // Where -> Location
        $c->set('filters.place', Stock::AT_BIL_FACTORY);
        $bilLocations = $optionsFor('location');
        $this->assertNotEmpty($bilLocations);
        $this->assertEmpty(array_diff($bilLocations, $allLocations));
        $this->assertLessThan(count($allLocations), count($bilLocations), 'Where did not narrow Location');

        // Location -> Grade Type -> Product
        $c->set('filters.location', $bilLocations[0]);
        $grades = $optionsFor('gradetype');
        $this->assertNotEmpty($grades);

        $productsAtLocation = $optionsFor('product');
        $c->set('filters.gradetype', $grades[0]);
        $productsInGrade = $optionsFor('product');

        $this->assertNotEmpty($productsInGrade);
        $this->assertEmpty(array_diff($productsInGrade, $productsAtLocation));
        $this->assertLessThan(count($allProducts), count($productsInGrade), 'Grade Type did not narrow Product');
    }

    /**
     * The RENDERED dropdown narrows, not just filterDefs().
     *
     * The filter is an Alpine combobox that snapshots its options when x-data
     * is evaluated. With a constant wire:key, Livewire's DOM diffing kept the
     * old element alive and the dropdown went on showing every location while
     * the server had already narrowed the list — the query was right and the UI
     * was wrong. The key now carries a hash of the options, so this asserts on
     * the markup the browser actually receives.
     */
    public function test_the_rendered_location_dropdown_narrows(): void
    {
        Livewire::actingAs($this->admin());
        $c = Livewire::test(Stock::class);

        /** @return array{key: string, items: string[]} */
        $dropdown = function (string $filter) use ($c): array {
            $pattern = '/wire:key="rfilter-' . $filter . '-([0-9a-f]{8})"(.*?)items: JSON\.parse\(\'(.*?)\'\)/s';
            if (! preg_match($pattern, $c->html(), $m)) {
                $this->fail("could not find the rendered {$filter} filter");
            }
            $json = str_replace(['\\u0022', "\\'"], ['"', "'"], $m[3]);

            return ['key' => $m[1], 'items' => array_column(json_decode($json, true) ?: [], 'label')];
        };

        $before = $dropdown('location');
        $this->assertContains('Bil-1', $before['items']);

        $c->set('filters.place', Stock::AT_BPL_WAREHOUSE);
        $after = $dropdown('location');

        $this->assertNotContains('Bil-1', $after['items'], 'the rendered dropdown still offers a BIL factory');
        $this->assertLessThan(count($before['items']), count($after['items']));
        $this->assertNotSame(
            $before['key'],
            $after['key'],
            'wire:key did not change, so Alpine would keep the stale option list'
        );
    }

    /** Changing an upstream filter clears the ones it narrows. */
    public function test_changing_where_clears_the_filters_below_it(): void
    {
        Livewire::actingAs($this->admin());
        $c = Livewire::test(Stock::class)->set('filters.place', Stock::AT_BIL_FACTORY);

        $location = array_key_first($c->instance()->filterDefs()['location']['options']);
        $c->set('filters.location', $location);
        $grade = array_key_first($c->instance()->filterDefs()['gradetype']['options']);
        $c->set('filters.gradetype', $grade);

        $c->set('filters.place', Stock::AT_BPL_FACTORY);

        $this->assertSame('', $c->get('filters.location'), 'stale location survived a Where change');
        $this->assertSame('', $c->get('filters.gradetype'), 'stale grade survived a Where change');
        $this->assertSame('', $c->get('filters.product'));

        // And the offered locations followed the new Where.
        $this->assertNotContains($location, array_keys($c->instance()->filterDefs()['location']['options']));
    }

    /**
     * Locations read their display name from the core masters, so renaming a
     * factory or warehouse in Admin flows through to this page.
     *
     * The movement tables hold legacy strings — a factory code, a paper machine
     * name, a `bpl_stock_locations` id — and reading those straight through
     * would show "PM2" where the rest of the app says "Paper Machine 2".
     */
    public function test_locations_use_the_names_from_the_core_masters(): void
    {
        $locations = $this->rows('by_location')->pluck('location')->unique();

        $factories = DB::connection('core')->table('factories')->whereNull('deleted_at');
        $warehouses = DB::connection('core')->table('warehouses')->whereNull('deleted_at');

        // A paper machine's code is PM2/PM3; its NAME is what should be shown.
        $machineNames = (clone $factories)->whereIn('code', ['PM2', 'PM3'])->pluck('name');
        foreach ($machineNames as $name) {
            $this->assertNotSame('PM2', $name);   // guards the fixture, not the code
        }

        $known = (clone $factories)->pluck('name')
            ->merge((clone $warehouses)->pluck('name'))
            ->all();

        foreach ($locations as $location) {
            $this->assertContains(
                $location,
                $known,
                "'{$location}' is not a name any factory or warehouse carries — the page is showing a raw legacy string"
            );
        }
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
