<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Logistics\Reports\BillsOfLading;
use Modules\Bpl\Livewire\Logistics\Reports\Deliveries;
use Modules\Bpl\Livewire\Logistics\Statistics;
use Modules\Bpl\Models\BplDelivery;
use Modules\Bpl\Support\DeliveryFigures;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Logistics → Reports + Statistics.
 *
 * Read-only pages over the LIVE bpl database: nothing here writes, so there is
 * nothing to clean up. The assertions are about agreement with the screens
 * that wrote the rows — the weighbridge tolerance, the two date formats, and
 * the broken links the MyISAM years left behind.
 */
class BplLogisticsReportsPageTest extends TestCase
{
    /** All time: ~1,200 waybills, so this is cheap. */
    private const FROM = '';

    private const TO = '';

    /** slug => [component, route] */
    private const REPORTS = [
        'deliveries' => [Deliveries::class, 'bpl.logistics.reports.deliveries'],
        'bills-of-lading' => [BillsOfLading::class, 'bpl.logistics.reports.bills-of-lading'],
    ];

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

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

    /* ---------------- The two dates ---------------- */

    /**
     * `bpl_delivery_barcode`.`date` is `yy-mm-dd` — written to match the
     * `DD-MM-YY-BPD-NNN` waybill number rather than the rest of the schema —
     * while `bpl_delivery`.`date` is the usual `Y/m/d`. Ranging on the wrong
     * one silently matches nothing, so the report must use the delivery's.
     */
    public function test_the_report_ranges_on_the_delivery_date_not_the_waybills(): void
    {
        // The two columns really are different shapes; if that ever changes,
        // this test is why the report chose the one it did.
        $waybillDate = DB::connection('bpl')->table('bpl_delivery_barcode')
            ->whereNotNull('date')->where('date', '<>', '')->orderByDesc('id')->value('date');
        $this->assertMatchesRegularExpression('~^\d{2}-\d{2}-\d{2}$~', (string) $waybillDate);

        $deliveryDate = DB::connection('bpl')->table('bpl_delivery')
            ->orderByDesc('id')->value('date');
        $this->assertMatchesRegularExpression('~^\d{4}/\d{2}/\d{2}$~', (string) $deliveryDate);

        // A range around the latest delivery finds it.
        $day = str_replace('/', '-', (string) $deliveryDate);

        $rows = $this->report(Deliveries::class)
            ->set('dateFrom', $day)->set('dateTo', $day)->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        foreach ($rows->items() as $row) {
            $this->assertSame($deliveryDate, $row->date);
        }
    }

    /* ---------------- The weighbridge ---------------- */

    /**
     * The variance is net (loaded − empty) less what the rolls weigh, and the
     * Weighbridge screen refuses anything more than 150 kg SHORT while setting
     * no upper bound. So the data should hold heavy loads and no short ones.
     */
    public function test_the_variance_matches_the_weighbridge_rule(): void
    {
        $rows = $this->report(Deliveries::class)->set('view', 'variance')
            ->set('perPage', 250)->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        $heavy = 0;

        foreach ($rows->items() as $row) {
            $this->assertNotNull($row->variance, $row->waybill);
            $this->assertGreaterThan(DeliveryFigures::TOLERANCE, abs((float) $row->variance));

            // net = loaded − empty, and variance = net − rolls.
            $this->assertEqualsWithDelta(
                (float) $row->full_weight - (float) $row->tare, (float) $row->net_weight, 0.01, $row->waybill
            );
            $this->assertEqualsWithDelta(
                (float) $row->net_weight - (float) $row->roll_weight, (float) $row->variance, 0.01, $row->waybill
            );

            (float) $row->variance > 0 && $heavy++;
        }

        // Every one out of tolerance is HEAVY: the screen has refused short
        // loads since it was built, and the legacy data has none either.
        $this->assertSame($rows->total(), $heavy);
    }

    /** An unweighed waybill has no net and no variance, rather than a zero. */
    public function test_an_unweighed_load_has_no_variance(): void
    {
        $rows = DB::connection('bpl')->query()
            ->fromSub(DeliveryFigures::waybills(), 'f')
            ->where('weighed', 0)->get();

        $this->assertGreaterThan(0, $rows->count());

        foreach ($rows as $row) {
            $this->assertNull($row->net_weight, $row->waybill);
            $this->assertNull($row->variance, $row->waybill);
        }
    }

    /** An internal move completes at once, so it is not waiting for anything. */
    public function test_internal_moves_are_not_waiting_for_the_weighbridge(): void
    {
        $pending = $this->report(Deliveries::class)->set('view', 'pending')
            ->set('perPage', 250)->viewData('rows');

        foreach ($pending->items() as $row) {
            $this->assertNotSame(BplDelivery::INTERNAL_CUSTOMER, trim((string) $row->customer));
            $this->assertSame('pending', (string) $row->status);
        }
    }

    /* ---------------- Broken links ---------------- */

    /**
     * A waybill and its load are joined only through `bpl_delivery_details`,
     * so a half-committed save leaves an orphan at one end. gds writes all
     * three tables in one transaction; this view is what the MyISAM years left
     * behind, and it must not be date-ranged — an orphan waybill has no
     * delivery to take a date from.
     */
    public function test_broken_links_are_listed_whatever_the_date_range(): void
    {
        $orphans = $this->report(Deliveries::class)->set('view', 'orphans')->viewData('rows');

        $this->assertGreaterThan(0, $orphans->total());

        $problems = collect($orphans->items())->pluck('problem')->unique()->values()->all();

        foreach ($problems as $problem) {
            $this->assertContains($problem, ['Waybill with no load behind it', 'Load with no waybill']);
        }

        // Narrowing the dates to a single recent day must not hide them.
        $narrowed = $this->report(Deliveries::class)->set('view', 'orphans')
            ->set('dateFrom', '2026-09-01')->set('dateTo', '2026-09-01')->viewData('rows');

        $this->assertSame($orphans->total(), $narrowed->total());
    }

    /* ---------------- Bills of lading ---------------- */

    /**
     * A bill is issued once the goods are aboard, so it cannot have sailed
     * afterwards — the rule the current screen enforces and the old form never
     * checked.
     */
    public function test_bills_with_dates_out_of_order_are_listed(): void
    {
        $rows = $this->report(BillsOfLading::class)->set('view', 'date_order')
            ->set('perPage', 250)->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        foreach ($rows->items() as $row) {
            $this->assertNotSame('', (string) $row->problem, $row->number);

            if ($row->problem === 'Sailed after it was issued') {
                $this->assertGreaterThan((string) $row->issued_date, (string) $row->shipped_date);
            }
        }
    }

    /** Arrival is recorded on a handful, and the report says which. */
    public function test_the_arrival_filter_splits_the_bills(): void
    {
        $all = $this->report(BillsOfLading::class)->viewData('rows')->total();
        $with = $this->report(BillsOfLading::class, ['arrival' => 'yes'])->viewData('rows')->total();
        $without = $this->report(BillsOfLading::class, ['arrival' => 'no'])->viewData('rows')->total();

        $this->assertSame($all, $with + $without);

        // The column is almost never filled — which is exactly why the report
        // cannot answer "what is still at sea" and says so.
        $this->assertGreaterThan($with, $without);

        $noArrival = $this->report(BillsOfLading::class)->set('view', 'no_arrival')
            ->set('perPage', 250)->viewData('rows');

        foreach ($noArrival->items() as $row) {
            $this->assertSame('', (string) $row->arrival_date, $row->number);
        }
    }

    /* ---------------- Drill-downs ---------------- */

    public function test_every_summary_drills_into_exactly_what_it_counted(): void
    {
        $checks = [
            Deliveries::class => ['by_customer' => 'loads', 'by_vehicle' => 'loads',
                'by_day' => 'loads', 'by_month' => 'loads'],
            BillsOfLading::class => ['by_customer' => 'bills', 'by_month' => 'bills'],
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

    /* ---------------- Print and export ---------------- */

    public function test_print_and_export_work_for_every_report(): void
    {
        foreach (array_keys(self::REPORTS) as $slug) {
            $this->actingAs($this->admin())
                ->get(route('bpl.logistics.reports.print', ['report' => $slug]))
                ->assertOk();

            foreach (['xlsx', 'csv'] as $format) {
                $this->actingAs($this->admin())->get(
                    route('bpl.logistics.reports.download', ['report' => $slug, 'format' => $format])
                )->assertOk();
            }
        }

        $this->actingAs($this->admin())
            ->get(route('bpl.logistics.reports.print', ['report' => 'nonsense']))
            ->assertNotFound();
    }

    /* ---------------- Statistics ---------------- */

    public function test_the_statistics_page_opens(): void
    {
        $this->actingAs($this->admin())->get(route('bpl.logistics.statistics'))
            ->assertOk()
            ->assertSee('BPL Logistics Statistics');
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

    /** The dashboard's weighbridge figures are the report's. */
    public function test_the_dashboard_agrees_with_the_variance_report(): void
    {
        Livewire::actingAs($this->admin());

        $tiles = Livewire::test(Statistics::class)->set('range', 'all')
            ->call('selectSection', 'weighbridge')->viewData('tiles');

        $overTolerance = collect($tiles)->firstWhere('label', 'Over Tolerance')['value'];

        $report = $this->report(Deliveries::class)->set('view', 'variance')->viewData('rows')->total();

        $this->assertSame(number_format($report), $overTolerance);
    }

    public function test_the_statistics_export_works(): void
    {
        foreach (['xlsx', 'csv'] as $format) {
            $this->actingAs($this->admin())->get(
                route('bpl.logistics.statistics.export', ['format' => $format, 'section' => 'overview'])
            )->assertOk();
        }
    }
}
