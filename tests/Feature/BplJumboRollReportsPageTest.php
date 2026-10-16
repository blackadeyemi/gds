<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\JumboRolls\Reports\FactoryExit;
use Modules\Bpl\Livewire\JumboRolls\Reports\Production;
use Modules\Bpl\Livewire\JumboRolls\Reports\RollTrail;
use Modules\Bpl\Livewire\JumboRolls\Reports\WaitingArea;
use Modules\Bpl\Livewire\JumboRolls\Reports\WarehouseEntry;
use Modules\Bpl\Livewire\JumboRolls\Reports\WarehouseExit;
use Modules\Bpl\Livewire\JumboRolls\Reports\WarehouseTransfer;
use Modules\Bpl\Livewire\JumboRolls\Statistics;
use Modules\Bpl\Livewire\JumboRolls\WarehouseStock;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Jumbo Rolls → Reports + Statistics.
 *
 * Read-only pages over the LIVE bpl database: nothing here writes, so there is
 * nothing to clean up. The assertions are about shape and consistency — that
 * every view of every report runs, that the two streams' quirks are handled,
 * and that the figures agree with the screens they report on.
 */
class BplJumboRollReportsPageTest extends TestCase
{
    /** A range with real movement in it on the live data. */
    private const FROM = '2026-09-01';

    private const TO = '2026-09-30';

    /** slug => [component, route] */
    private const REPORTS = [
        'production' => [Production::class, 'bpl.jumbo-rolls.reports.production'],
        'factory-exit' => [FactoryExit::class, 'bpl.jumbo-rolls.reports.factory-exit'],
        'warehouse-entry' => [WarehouseEntry::class, 'bpl.jumbo-rolls.reports.warehouse-entry'],
        'warehouse-exit' => [WarehouseExit::class, 'bpl.jumbo-rolls.reports.warehouse-exit'],
        'warehouse-transfer' => [WarehouseTransfer::class, 'bpl.jumbo-rolls.reports.warehouse-transfer'],
        'waiting-area' => [WaitingArea::class, 'bpl.jumbo-rolls.reports.waiting-area'],
        'roll-trail' => [RollTrail::class, 'bpl.jumbo-rolls.reports.roll-trail'],
    ];

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    /** A report with its date range set and every filter cleared. */
    private function report(string $class, array $filters = [])
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test($class)->set('dateFrom', self::FROM)->set('dateTo', self::TO);

        foreach (array_keys($c->instance()->filterDefs()) as $name) {
            $c->set('filters.' . $name, $filters[$name] ?? '');
        }

        return $c;
    }

    /** The rolls the Warehouse Stock page says BPL is holding. */
    private function heldRolls(): int
    {
        return (int) DB::connection('bpl')->query()
            ->fromSub((new WarehouseStock())->positionQuery(), 'p')
            ->sum('rolls');
    }

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

    /** Both streams are in play unless the Type filter narrows it. */
    public function test_the_type_filter_splits_the_streams(): void
    {
        $both = $this->report(Production::class)->viewData('rows')->total();
        $hard = $this->report(Production::class, ['stream' => 'hardroll'])->viewData('rows')->total();
        $soft = $this->report(Production::class, ['stream' => 'softroll'])->viewData('rows')->total();

        $this->assertSame($both, $hard + $soft);
        $this->assertGreaterThan(0, $hard);
    }

    /**
     * A softroll records its machine as the bare number, a hardroll as the
     * name. Filtering on 'PM3' has to find both — reading the softroll number
     * as a stock-location id names a STORE, which is what it did at first.
     */
    public function test_the_machine_filter_matches_both_spellings(): void
    {
        $rows = $this->report(Production::class, ['machine' => 'PM3'])
            ->set('perPage', 250)->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        foreach ($rows->items() as $row) {
            $this->assertSame('PM3', $row->machine, $row->barcode . ' is not on PM3');
        }

        // …and the softroll rows really are in there. Over a wider window,
        // because softrolls are made in ones and twos — September's only one
        // happens to be a PM2.
        $soft = $this->report(Production::class, ['machine' => 'PM3', 'stream' => 'softroll'])
            ->set('dateFrom', '2026-01-01')->set('dateTo', self::TO)
            ->set('perPage', 250)->viewData('rows');

        $this->assertGreaterThan(0, $soft->total());

        foreach ($soft->items() as $row) {
            $this->assertSame('PM3', $row->machine, $row->barcode . ' is not on PM3');
        }
    }

    /**
     * Warehouse Entry filtered to "in the store" over all time IS the held
     * position, so it must agree with what the Warehouse Stock page totals.
     */
    public function test_rolls_in_the_store_agree_with_the_stock_page(): void
    {
        Livewire::actingAs($this->admin());

        $report = Livewire::test(WarehouseEntry::class)
            ->set('dateFrom', '')->set('dateTo', '')
            ->set('filters.status', 'none');

        $this->assertSame($this->heldRolls(), $report->viewData('rows')->total());
    }

    /** The Gaps view lists only rows that really have a break in them. */
    public function test_the_roll_trail_gaps_view_only_shows_problems(): void
    {
        $rows = $this->report(RollTrail::class)->set('view', 'gaps')->viewData('rows');

        foreach ($rows->items() as $row) {
            $this->assertNotSame('', (string) $row->problem, $row->barcode);

            // "Went nowhere" has to mean BOTH doors, not just the waybill —
            // most rolls leave BPL through BIL's factory, and testing only the
            // waybill flagged four normal rolls in five.
            if ($row->problem === 'Released from the store, went nowhere') {
                $this->assertSame('', (string) $row->waybill, $row->barcode);
                $this->assertSame('', (string) $row->bil_date, $row->barcode);
                $this->assertNotSame('', (string) $row->exit_date, $row->barcode);
            }
        }
    }

    /**
     * The BIL hand-off is a stage of its own. Without it the report has no
     * explanation for the commonest thing that happens to a BPL roll.
     */
    public function test_the_roll_trail_follows_rolls_into_the_bil_factory(): void
    {
        $rows = $this->report(RollTrail::class, ['stage' => 'Received at BIL'])
            ->set('dateFrom', '2026-01-01')->set('perPage', 100)->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        foreach ($rows->items() as $row) {
            $this->assertNotSame('', (string) $row->bil_date, $row->barcode);
            // Received at BIL and still flagged as going nowhere would be the
            // bug this stage exists to fix.
            $this->assertNotSame('Released from the store, went nowhere', (string) $row->problem);
        }
    }

    /** A stage is whatever the roll's own dates say it is. */
    public function test_the_roll_trail_stage_follows_the_dates(): void
    {
        $rows = $this->report(RollTrail::class)->set('perPage', 100)->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        foreach ($rows->items() as $row) {
            if ($row->stage === 'On the factory floor') {
                $this->assertSame('', (string) $row->fx_date, $row->barcode);
            }

            if ($row->stage === 'Delivered') {
                $this->assertNotSame('', (string) $row->waybill, $row->barcode);
            }
        }
    }

    /**
     * Asked for more than a year, the Roll Trail narrows the range and SAYS
     * it did — on the page and in the printout — rather than presenting a
     * year's figures under a decade's heading.
     */
    public function test_the_roll_trail_clamps_a_range_wider_than_a_year(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(RollTrail::class)->set('dateFrom', '2020-01-01')->set('dateTo', self::TO);

        $notice = $c->instance()->slowCountNotice();
        $this->assertNotNull($notice);
        $this->assertStringContainsString('a year at a time', $notice);

        $context = collect($c->instance()->reportContext());
        $this->assertNotNull($context->firstWhere(0, 'Range actually read'));

        // Nothing older than the clamp comes back.
        $oldest = collect($c->set('perPage', 100)->viewData('rows')->items())->min('made');
        $this->assertNotNull($oldest);
        $this->assertGreaterThanOrEqual('2025/09', substr((string) $oldest, 0, 7));

        // A range inside the limit is left alone and says nothing.
        $this->assertNull(
            Livewire::test(RollTrail::class)->set('dateFrom', self::FROM)->set('dateTo', self::TO)
                ->instance()->slowCountNotice()
        );
    }

    /**
     * A delivery line's barcode must match a roll's on the INDEX, not just on
     * the value: the two columns were different character sets, which made the
     * waybill lookup a full scan and the report unusable over any real range.
     */
    public function test_the_delivery_barcode_column_matches_the_roll_barcode(): void
    {
        $charset = fn (string $table) => DB::connection('bpl')->selectOne(
            'SELECT character_set_name AS cs FROM information_schema.columns'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, 'barcode']
        )?->cs;

        $this->assertSame($charset('bpl_production'), $charset('bpl_delivery_details'));
    }

    /** Print and the streamed download formats come back, for each report. */
    public function test_print_and_export_work_for_every_report(): void
    {
        $query = ['dateFrom' => self::FROM, 'dateTo' => self::TO];

        foreach (array_keys(self::REPORTS) as $slug) {
            $this->actingAs($this->admin())
                ->get(route('bpl.jumbo-rolls.reports.print', ['report' => $slug]) . '?' . http_build_query($query))
                ->assertOk();

            foreach (['xlsx', 'csv'] as $format) {
                $this->actingAs($this->admin())->get(
                    route('bpl.jumbo-rolls.reports.download', ['report' => $slug])
                    . '?' . http_build_query($query + ['format' => $format])
                )->assertOk();
            }
        }
    }

    /** An unknown report slug is a 404, not a fatal. */
    public function test_an_unknown_report_slug_is_not_found(): void
    {
        $this->actingAs($this->admin())
            ->get(route('bpl.jumbo-rolls.reports.print', ['report' => 'nonsense']))
            ->assertNotFound();
    }

    /* ---------------- Statistics ---------------- */

    public function test_the_statistics_page_opens(): void
    {
        $this->actingAs($this->admin())->get(route('bpl.jumbo-rolls.statistics'))
            ->assertOk()
            ->assertSee('BPL Jumbo Roll Statistics');
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
    }

    /** Every range option builds. */
    public function test_the_statistics_range_control_works(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(Statistics::class)->call('selectSection', 'production');

        foreach (array_keys($c->instance()->rangeOptions()) as $range) {
            $c->set('range', $range)->assertOk();
            $this->assertNotEmpty($c->viewData('tiles'), $range);
        }
    }

    /** The dashboard's stock figure is the Warehouse Stock page's figure. */
    public function test_the_stock_tile_agrees_with_the_stock_page(): void
    {
        Livewire::actingAs($this->admin());

        $tiles = Livewire::test(Statistics::class)->call('selectSection', 'stock')->viewData('tiles');
        $held = collect($tiles)->firstWhere('label', 'Rolls Held')['value'];

        // The tile compacts only at 100,000; the held count is in the thousands.
        $this->assertSame(number_format($this->heldRolls()), $held);
    }

    public function test_the_statistics_export_works(): void
    {
        foreach (['xlsx', 'csv'] as $format) {
            $this->actingAs($this->admin())->get(
                route('bpl.jumbo-rolls.statistics.export', ['format' => $format, 'section' => 'overview'])
            )->assertOk();
        }
    }
}
