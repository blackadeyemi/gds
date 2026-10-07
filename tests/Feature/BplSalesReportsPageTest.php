<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Sales\Reports\OrderTrail;
use Modules\Bpl\Livewire\Sales\Reports\Orders;
use Modules\Bpl\Livewire\Sales\Reports\Proformas;
use Modules\Bpl\Livewire\Sales\Reports\Shipments;
use Modules\Bpl\Livewire\Sales\Statistics;
use Modules\Bpl\Support\ProformaInvoice;
use Modules\Bpl\Support\SalesFigures;
use Modules\Bpl\Support\SalesOrderTrail;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Sales → Reports + Statistics + Order Trail.
 *
 * Read-only pages over the LIVE bpl database: nothing here writes, so there is
 * nothing to clean up. The assertions are about shape and agreement — that
 * every view runs, that the money the reports show is the money the printed
 * invoice shows, and that every one of the nine ways into the Order Trail
 * finds its order.
 */
class BplSalesReportsPageTest extends TestCase
{
    /** All time: BPL raises a few hundred orders a year, so this is cheap. */
    private const FROM = '';

    private const TO = '';

    /** slug => [component, route] */
    private const REPORTS = [
        'orders' => [Orders::class, 'bpl.sales.reports.orders'],
        'proformas' => [Proformas::class, 'bpl.sales.reports.proformas'],
        'shipments' => [Shipments::class, 'bpl.sales.reports.shipments'],
    ];

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    /** A report over all time with every filter cleared. */
    private function report(string $class, array $filters = [])
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test($class)->set('dateFrom', self::FROM)->set('dateTo', self::TO);

        foreach (array_keys($c->instance()->filterDefs()) as $name) {
            $c->set('filters.' . $name, $filters[$name] ?? '');
        }

        return $c;
    }

    /* ---------------- The reports ---------------- */

    public function test_every_report_page_opens(): void
    {
        foreach (self::REPORTS as [$class, $route]) {
            $this->actingAs($this->admin())->get(route($route))
                ->assertOk()
                ->assertSee((new $class())->title());
        }

        $this->actingAs($this->admin())->get(route('bpl.sales.reports.order-trail'))
            ->assertOk()
            ->assertSee('Order Trail');
    }

    public function test_every_view_of_every_report_renders(): void
    {
        foreach (self::REPORTS as $slug => [$class]) {
            $c = $this->report($class);

            foreach (array_keys($c->instance()->views()) as $view) {
                $c->set('view', $view)->assertOk();
                $this->assertNotNull($c->viewData('rows'), $slug . ' / ' . $view);
            }
        }
    }

    /**
     * The money the Shipments report shows IS the money the printed invoice
     * shows. The report computes it in SQL so it can sort and total; the
     * document computes it in PHP by walking the containers JSON. Two
     * implementations of one rule, checked against each other for every
     * packing list in the database.
     */
    public function test_the_shipment_value_agrees_with_the_printed_invoice(): void
    {
        $sql = DB::connection('bpl')->query()
            ->fromSub(SalesFigures::shipments(), 'f')
            ->get()->keyBy('packing_id');

        $this->assertGreaterThan(100, $sql->count(), 'no shipments to check');

        $rows = DB::connection('bpl')->table('bpl_packing_list')
            ->where('split', '0')->get(['id', 'order_id', 'containers']);

        $checked = 0;
        $withUnpriced = 0;

        foreach ($rows as $pl) {
            $shipped = [];

            foreach (json_decode((string) $pl->containers, true) ?: [] as $container) {
                foreach ($container['goods'] ?? [] as $lot) {
                    $key = (string) ($lot['description'] ?? '');
                    $shipped[$key] = ($shipped[$key] ?? 0) + (float) ($lot['weight'] ?? 0);
                }
            }

            $prices = [];
            foreach (ProformaInvoice::lines((int) $pl->order_id) as $line) {
                if ($line['price'] !== null) {
                    $prices[$line['fullname']] = $line['price'];
                }
            }

            $value = 0.0;
            $unpriced = 0.0;

            foreach ($shipped as $description => $weight) {
                isset($prices[$description])
                    ? $value += $prices[$description] * $weight / 1000
                    : $unpriced += $weight;
            }

            $row = $sql[$pl->id] ?? null;
            $this->assertNotNull($row, 'packing list ' . $pl->id . ' missing from the figures');
            $this->assertEqualsWithDelta($value, (float) $row->goods_value, 0.05, 'value of packing list ' . $pl->id);
            $this->assertEqualsWithDelta($unpriced, (float) $row->unpriced_weight, 0.5, 'unpriced weight of ' . $pl->id);

            $checked++;
            $unpriced > 0 && $withUnpriced++;
        }

        $this->assertSame($sql->count(), $checked);
        // The unpriced rows are a real condition in this data, not a bug in
        // the join — if this ever reaches zero the "Unpriced weight" view has
        // nothing to show and can go.
        $this->assertGreaterThan(0, $withUnpriced);
    }

    /** A split parent is scaffolding, not a shipment, and is never counted. */
    public function test_split_parents_are_excluded_from_the_shipments(): void
    {
        $parents = DB::connection('bpl')->table('bpl_packing_list')->where('split', '1')->pluck('id');
        $this->assertGreaterThan(0, $parents->count(), 'no split parents in this data');

        $counted = DB::connection('bpl')->query()->fromSub(SalesFigures::shipments(), 'f')
            ->whereIn('packing_id', $parents)->count();

        $this->assertSame(0, $counted);
    }

    /**
     * Money is never totalled across currencies — a naira invoice added to a
     * dollar one is a number nobody can act on. The totals appear only once
     * the currency filter narrows the page to one.
     */
    public function test_amounts_are_only_totalled_within_one_currency(): void
    {
        $mixed = $this->report(Shipments::class);
        $this->assertArrayNotHasKey('invoice_total', $mixed->viewData('totals'));
        // …and the page says why.
        $this->assertStringContainsString('pick a currency', $mixed->instance()->subtitle());

        $usd = (int) DB::connection('bpl')->table('currencies')->where('code', 'USD')->value('id');
        $one = $this->report(Shipments::class, ['currency' => (string) $usd]);

        $this->assertArrayHasKey('invoice_total', $one->viewData('totals'));
        $this->assertStringNotContainsString('pick a currency', $one->instance()->subtitle());
    }

    /** The outstanding view and its summary have to agree on what is owed. */
    public function test_the_outstanding_summary_matches_the_outstanding_rows(): void
    {
        $rows = $this->report(Shipments::class)->set('view', 'outstanding')->viewData('rows')->total();

        $summary = $this->report(Shipments::class)->set('view', 'owed_by_customer')
            ->set('perPage', 250)->viewData('rows');

        $this->assertSame($rows, (int) collect($summary->items())->sum('shipments'));
    }

    /**
     * Clicking into a summary row shows the records behind it — so the number
     * of records must be the number the row counted. Easy to break: a summary
     * with an extra predicate (Outstanding by customer has one) whose
     * drill-down forgets it shows more rows than the figure clicked.
     */
    public function test_every_summary_drills_into_exactly_what_it_counted(): void
    {
        $checks = [
            Orders::class => ['by_customer' => 'orders', 'by_country' => 'orders', 'by_month' => 'orders'],
            Proformas::class => ['by_customer' => 'proformas', 'by_currency' => 'proformas', 'by_month' => 'proformas'],
            Shipments::class => ['owed_by_customer' => 'shipments', 'by_currency' => 'shipments', 'by_month' => 'shipments'],
        ];

        foreach ($checks as $class => $views) {
            foreach ($views as $view => $countField) {
                $c = $this->report($class)->set('view', $view)->set('perPage', 10);
                $report = $c->instance();

                $rows = collect($c->viewData('rows')->items())->take(5);
                $this->assertNotEmpty($rows, $class . ' / ' . $view . ' has no groups');

                foreach ($rows as $row) {
                    $key = $report->detailKeyFor($row);
                    $this->assertNotNull($key, $class . ' / ' . $view . ' has no drill-down key');

                    $this->assertSame(
                        (int) $row->{$countField},
                        $report->detailQuery($key)->count(),
                        $class . ' / ' . $view . ' — group ' . str_replace("\x1f", ' | ', $key)
                    );
                }
            }
        }
    }

    /* ---------------- The Order Trail ---------------- */

    /**
     * A word of the customer's name that a `label%` search cannot claim, so
     * the Customer way can be told apart from the Customer-code way.
     *
     * Returns null when the name says nothing the label does not -- "Sitracel"
     * with label SITRACEL has no such word, and that customer simply cannot
     * demonstrate the difference.
     */
    private function nameTermOutsideLabel(string $name, string $label): ?string
    {
        $label = mb_strtolower($label);

        foreach (preg_split('~\\s+~', trim($name)) ?: [] as $word) {
            $word = trim($word);

            // Two characters is suggest()'s own floor, and a word the label
            // starts with would be answered as the code, not the name.
            if (mb_strlen($word) >= 2 && ! str_starts_with($label, mb_strtolower($word))) {
                return $word;
            }
        }

        return null;
    }

    /** Every identifier a BPL order carries finds it, and says which it was. */
    public function test_all_nine_ways_of_finding_an_order(): void
    {
        $bpl = DB::connection('bpl');

        // THE ORDER IS CHOSEN, NOT ASSUMED. The Customer and Customer-code
        // ways have to be told apart, and 60 of BPL's 131 customers have a
        // label that is a substring of their own name (EVERPACK / "Everpack
        // Ltd") -- for those, a term drawn from the front of the name is also
        // a code prefix, and the code is the more specific reason, so it wins.
        // So this needs a customer whose NAME carries a word the LABEL cannot
        // claim, and the customer term is taken from there.
        $candidates = $bpl->table('bpl_sales as s')
            ->join('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->whereNotNull('s.orderno')->where('s.orderno', '<>', '')
            ->whereNotNull('c.customerlabel')->where('c.customerlabel', '<>', '')
            ->whereNull('s.deleted_at')
            ->orderByDesc('s.id')->limit(200)
            ->get(['s.id', 's.orderno', 's.ref', 'c.customername', 'c.customerlabel']);

        $order = null;
        $customerTerm = null;
        foreach ($candidates as $row) {
            $customerTerm = $this->nameTermOutsideLabel($row->customername, $row->customerlabel);
            if ($customerTerm !== null) {
                $order = $row;
                break;
            }
        }

        $this->assertNotNull($order, 'no order whose customer name says more than its label');

        $terms = [
            'orderno' => $order->orderno,
            'ref' => $order->ref,
            'label' => $order->customerlabel,
            'customer' => $customerTerm,
            'customer_ref' => $bpl->table('bpl_proforma')->whereNotNull('customer_ref')
                ->where('customer_ref', '<>', '')->orderByDesc('order_id')->value('customer_ref'),
            'nxp' => $bpl->table('bpl_proforma')->whereNotNull('nxp')
                ->where('nxp', '<>', '')->orderByDesc('order_id')->value('nxp'),
            'packing' => $bpl->table('bpl_packing_list')->where('split', '0')
                ->orderByDesc('id')->value('number'),
            'bl' => $bpl->table('bpl_bill_of_lading')->orderByDesc('id')->value('number'),
            'container' => $bpl->selectOne(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(containers, '$[0].name')) AS n FROM bpl_packing_list"
                . " WHERE split = '0' AND JSON_LENGTH(containers) > 0 ORDER BY id DESC LIMIT 1")->n,
        ];

        // Every way declared is exercised; a new one added without a term here
        // fails rather than going quietly untested.
        $this->assertSame(array_keys(SalesOrderTrail::WAYS), array_keys($terms));

        foreach ($terms as $way => $term) {
            $this->assertNotEmpty($term, 'nothing in the data to search by ' . $way);

            $hits = SalesOrderTrail::suggest((string) $term);

            $this->assertNotEmpty($hits, 'searching by ' . $way . ' for "' . $term . '" found nothing');
            $this->assertTrue(
                collect($hits)->contains('matched_on', $way),
                'searching by ' . $way . ' never reported that as the reason'
            );
        }
    }

    /**
     * A packing list is stored with a `:customerid` placeholder and read with
     * the customer's label — nobody types the stored form, so both must find it.
     */
    public function test_a_packing_list_is_found_by_the_number_people_see(): void
    {
        $row = DB::connection('bpl')->table('bpl_packing_list as pl')
            ->join('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->join('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->where('pl.split', '0')
            ->whereRaw("`pl`.`number` LIKE CONCAT('%:', `c`.`id`, '%')")
            ->orderByDesc('pl.id')->first(['pl.number', 'c.id as customer_id', 'c.customerlabel']);

        $this->assertNotNull($row, 'no packing list carries the placeholder');

        $printed = \Modules\Bpl\Support\PackingList::label($row->number, (int) $row->customer_id, $row->customerlabel);
        $this->assertNotSame($row->number, $printed);

        foreach ([$row->number, $printed] as $term) {
            $this->assertNotEmpty(SalesOrderTrail::suggest($term), 'no hit for ' . $term);
        }
    }

    /** Two characters at least, so an empty box does not scan nine tables. */
    public function test_a_one_character_search_is_not_run(): void
    {
        $this->assertSame([], SalesOrderTrail::suggest('a'));
        $this->assertSame([], SalesOrderTrail::suggest(''));
    }

    /** The trail of an order that went the whole way reads in order. */
    public function test_the_trail_of_a_completed_order(): void
    {
        $orderId = DB::connection('bpl')->table('bpl_packing_list as pl')
            ->join('bpl_bill_of_lading as bl', 'bl.packing_id', '=', 'pl.id')
            ->join('bpl_invoice_payments as ip', 'ip.packing_id', '=', 'pl.id')
            ->orderByDesc('pl.id')->value('pl.order_id');

        $this->assertNotNull($orderId);

        $order = SalesOrderTrail::find((string) $orderId);
        $this->assertNotNull($order);

        $trail = SalesOrderTrail::trail($order);

        // The order is always the first event, whatever else happened.
        $this->assertSame('order', $trail[0]['stage']);

        // Dates never go backwards.
        $dates = array_column($trail, 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);

        // The steps this order reached are all present.
        foreach (['proforma', 'shipment', 'bl', 'payment'] as $stage) {
            $this->assertContains($stage, array_column($trail, 'stage'), 'no ' . $stage . ' event');
        }

        // …and the progress strip agrees with the trail.
        $progress = collect(SalesOrderTrail::progress($order))->keyBy('key');
        $this->assertTrue($progress['order']['done']);
        $this->assertTrue($progress['shipment']['done']);
        $this->assertTrue($progress['bl']['done']);
    }

    /** The order's figures add up to what its lines and shipments say. */
    public function test_the_trail_totals_match_its_lines(): void
    {
        $orderId = DB::connection('bpl')->table('bpl_packing_list')
            ->where('split', '0')->orderByDesc('id')->value('order_id');

        $order = SalesOrderTrail::find((string) $orderId);
        $this->assertNotNull($order);

        $lines = SalesOrderTrail::lines((int) $order->id);
        $totals = SalesOrderTrail::totals($order);

        $this->assertSame(count($lines), $totals['lines']);
        $this->assertEqualsWithDelta(
            array_sum(array_map(fn ($l) => $l->weight, $lines)), $totals['ordered'], 0.01
        );
        $this->assertEqualsWithDelta(
            array_sum(array_map(fn ($l) => $l->shipped, $lines)), $totals['shipped'], 0.01
        );
        $this->assertEqualsWithDelta($totals['invoiced'] - $totals['paid'], $totals['outstanding'], 0.01);
    }

    /** Opening an order from the search clears the box and shows the trail. */
    public function test_the_order_trail_page_opens_an_order(): void
    {
        Livewire::actingAs($this->admin());

        $orderId = DB::connection('bpl')->table('bpl_sales')
            ->whereNull('deleted_at')->orderByDesc('id')->value('id');

        $c = Livewire::test(OrderTrail::class)->call('openOrder', (string) $orderId);

        $this->assertSame('', $c->get('search'));
        $this->assertNotNull($c->instance()->order);
        $this->assertNotEmpty($c->instance()->trail);

        $c->call('clearOrder');
        $this->assertNull($c->instance()->order);
    }

    /** An order that does not exist is not found, rather than a fatal. */
    public function test_an_unknown_order_is_not_found(): void
    {
        $this->assertNull(SalesOrderTrail::find('NO-SUCH-ORDER'));
        $this->assertNull(SalesOrderTrail::find(''));

        $this->actingAs($this->admin())
            ->get(route('bpl.sales.reports.order-trail.print', ['order' => 'NO-SUCH-ORDER']))
            ->assertNotFound();
    }

    /* ---------------- Print and export ---------------- */

    public function test_print_and_export_work_for_every_report(): void
    {
        foreach (array_keys(self::REPORTS) as $slug) {
            $this->actingAs($this->admin())
                ->get(route('bpl.sales.reports.print', ['report' => $slug]))
                ->assertOk();

            foreach (['xlsx', 'csv'] as $format) {
                $this->actingAs($this->admin())->get(
                    route('bpl.sales.reports.download', ['report' => $slug, 'format' => $format])
                )->assertOk();
            }
        }

        $this->actingAs($this->admin())
            ->get(route('bpl.sales.reports.print', ['report' => 'nonsense']))
            ->assertNotFound();
    }

    public function test_the_order_trail_prints_and_exports(): void
    {
        $orderId = DB::connection('bpl')->table('bpl_packing_list')
            ->where('split', '0')->orderByDesc('id')->value('order_id');

        $this->actingAs($this->admin())
            ->get(route('bpl.sales.reports.order-trail.print', ['order' => $orderId]))
            ->assertOk();

        foreach (['xlsx', 'csv'] as $format) {
            $this->actingAs($this->admin())->get(
                route('bpl.sales.reports.order-trail.download', ['order' => $orderId, 'format' => $format])
            )->assertOk();
        }
    }

    /* ---------------- Statistics ---------------- */

    public function test_the_statistics_page_opens(): void
    {
        $this->actingAs($this->admin())->get(route('bpl.sales.statistics'))
            ->assertOk()
            ->assertSee('BPL Sales Statistics');
    }

    public function test_every_statistics_section_builds(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(Statistics::class);

        foreach (array_keys($c->instance()->sections()) as $section) {
            $c->call('selectSection', $section)->assertOk();
            $this->assertNotEmpty($c->viewData('tiles'), $section . ' has no tiles');
            $this->assertNotEmpty($c->viewData('charts'), $section . ' has no charts');
        }

        foreach (array_keys($c->instance()->rangeOptions()) as $range) {
            $c->set('range', $range)->assertOk();
        }
    }

    /**
     * A debt does not stop existing because the date filter moved, so the
     * receivables figures are the same whatever range is chosen.
     */
    public function test_receivables_ignore_the_date_range(): void
    {
        Livewire::actingAs($this->admin());

        $owed = function (string $range) {
            $tiles = Livewire::test(Statistics::class)->set('range', $range)
                ->call('selectSection', 'receivables')->viewData('tiles');

            return collect($tiles)->filter(fn ($t) => str_starts_with($t['label'], 'Owed'))
                ->mapWithKeys(fn ($t) => [$t['label'] => $t['value']])->all();
        };

        $this->assertNotEmpty($owed('all'));
        $this->assertSame($owed('all'), $owed('90d'));
    }

    public function test_the_statistics_export_works(): void
    {
        foreach (['xlsx', 'csv'] as $format) {
            $this->actingAs($this->admin())->get(
                route('bpl.sales.statistics.export', ['format' => $format, 'section' => 'overview'])
            )->assertOk();
        }
    }
}
