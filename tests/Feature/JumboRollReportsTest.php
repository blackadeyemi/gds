<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bil\Livewire\JumboRolls\Reports\Consumption;
use Modules\Bil\Livewire\JumboRolls\Reports\FactoryEntrance;
use Modules\Bil\Livewire\JumboRolls\Reports\Returns;
use Modules\Bil\Livewire\JumboRolls\Statistics;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BIL → Jumbo Rolls reports and statistics: every route, every view of every
 * report, every section of the dashboard, and the export guards. Read-only.
 */
class JumboRollReportsTest extends TestCase
{
    /** report slug => [component, page key, url] */
    public static function reports(): array
    {
        return [
            'factory entrance' => [FactoryEntrance::class, 'bil.jumbo_rolls.reports.factory_entrance', '/bil/jumbo-rolls/reports/factory-entrance'],
            'consumption' => [Consumption::class, 'bil.jumbo_rolls.reports.consumption', '/bil/jumbo-rolls/reports/consumption'],
            'returns' => [Returns::class, 'bil.jumbo_rolls.reports.returns', '/bil/jumbo-rolls/reports/returns'],
        ];
    }

    protected function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u, 'no admin user in core.user');

        return $u;
    }

    protected function outsider(string $pageKey): User
    {
        $u = User::whereDoesntHave('roles', fn ($q) => $q->where('legacy_level', 1))
            ->get()
            ->first(fn (User $u) => ! $u->canAccessPage($pageKey));
        $this->assertNotNull($u, 'no non-admin user without the page');

        return $u;
    }

    public function test_each_report_opens(): void
    {
        foreach (self::reports() as $label => [, $pageKey, $url]) {
            $this->actingAs($this->admin())->get($url)->assertOk("{$label} did not open");
            $this->actingAs($this->outsider($pageKey))->get($url)->assertForbidden();
        }
    }

    /**
     * Every declared view of every report runs.
     *
     * These are hand-written SQL against legacy tables with cross-schema joins;
     * a typo in one view only shows up when someone switches to it.
     */
    public function test_every_view_of_every_report_runs(): void
    {
        Livewire::actingAs($this->admin());

        foreach (self::reports() as $label => [$class]) {
            $views = array_keys((new $class())->views());
            $this->assertNotEmpty($views, "{$label} declares no views");

            foreach ($views as $view) {
                Livewire::test($class)
                    ->set('dateFrom', now()->subYear()->format('Y-m-d'))
                    ->set('dateTo', now()->format('Y-m-d'))
                    ->set('view', $view)
                    ->assertOk();
            }
        }
    }

    /** Summary rows drill down, and the key round-trips through the modal. */
    public function test_summary_rows_drill_down(): void
    {
        Livewire::actingAs($this->admin());

        foreach (self::reports() as $label => [$class]) {
            $report = new $class();
            $report->dateFrom = now()->subYear()->format('Y-m-d');
            $report->dateTo = now()->format('Y-m-d');

            foreach ($report->views() as $key => $view) {
                if (($view['type'] ?? 'table') !== 'summary') {
                    continue;
                }

                $report->view = $key;
                $row = $view['query']()->get()->first();

                if (! $row) {
                    continue;   // nothing in range for this cut
                }

                $this->assertNotNull($report->expandableBy(), "{$label}/{$key} has no drill-down");

                $rows = $report->detailQuery($report->detailKeyFor($row))->get();
                $this->assertNotEmpty($rows, "{$label}/{$key} drill-down found nothing behind a row that has a count");
            }
        }
    }

    /** The status filter can ask for NULL — "on the floor" is the interesting one. */
    public function test_the_entrance_status_filter_can_select_on_floor(): void
    {
        Livewire::actingAs($this->admin());

        $onFloor = DB::connection('bil')->table('factory_entrance_reel')
            ->where('is_deleted', 0)->whereNull('status')->count();

        $report = new FactoryEntrance();
        $report->dateFrom = '';
        $report->dateTo = '';
        $report->filters = ['status' => 'null'];

        $this->assertSame($onFloor, $report->views()['default']['query']()->count());
    }

    /** Consumption sums the piece that moved, not the reel it came off. */
    public function test_consumption_sums_the_slice_weight(): void
    {
        $report = new Consumption();
        $report->dateFrom = now()->subYear()->format('Y-m-d');
        $report->dateTo = now()->format('Y-m-d');
        $report->view = 'by_grade';

        $reported = (float) collect($report->views()['by_grade']['query']()->get())->sum('weight');

        $actual = (float) DB::connection('bil')->table('factory_usage_reel')
            ->where('is_deleted', 0)
            ->whereBetween('dateofuse', [
                str_replace('-', '/', $report->dateFrom),
                str_replace('-', '/', $report->dateTo),
            ])->sum('weight');

        $this->assertEqualsWithDelta($actual, $reported, 1.0);
    }

    /** Returns reads only the 'return' events; 'remain' rows are still on the floor. */
    public function test_returns_excludes_remainders_still_on_the_floor(): void
    {
        $report = new Returns();
        $report->dateFrom = '';
        $report->dateTo = '';

        $reported = $report->views()['default']['query']()->count();
        $actual = DB::connection('bil')->table('factory_event')->where('event', 'return')->count();

        $this->assertSame($actual, $reported);
        $this->assertGreaterThan(0, DB::connection('bil')->table('factory_event')
            ->where('event', 'remain')->count(), 'no remain rows — this test proves nothing');
    }

    /* ---------------- Statistics ---------------- */

    public function test_the_statistics_page_opens(): void
    {
        $this->actingAs($this->admin())->get('/bil/jumbo-rolls/statistics')->assertOk();
        $this->actingAs($this->outsider('bil.jumbo_rolls.statistics'))
            ->get('/bil/jumbo-rolls/statistics')->assertForbidden();
    }

    /** Every section renders, at every range. */
    public function test_every_statistics_section_renders(): void
    {
        Livewire::actingAs($this->admin());
        $stats = new Statistics();

        foreach (array_keys($stats->sections()) as $section) {
            foreach (array_keys($stats->rangeOptions()) as $range) {
                Livewire::test(Statistics::class)
                    ->set('range', $range)
                    ->set('section', $section)
                    ->assertOk();
            }
        }
    }

    /**
     * The dashboard's stock figure is the Stock page's, not a second opinion.
     */
    public function test_statistics_stock_agrees_with_the_stock_page(): void
    {
        $page = DB::connection('bil')->query()
            ->fromSub((new \Modules\Bil\Livewire\JumboRolls\Stock())->positionQuery(), 's')
            ->count();

        Livewire::actingAs($this->admin());
        $html = Livewire::test(Statistics::class)->set('section', 'stock')->html();

        $this->assertStringContainsString(number_format($page), $html,
            'the dashboard reports a different number of reels than the Stock page');
    }

    public function test_exports_are_gated_and_bounded(): void
    {
        $admin = $this->admin();

        foreach (['factory-entrance', 'consumption', 'returns', 'stock'] as $slug) {
            $this->actingAs($admin)->get("/bil/jumbo-rolls/reports/{$slug}/print")->assertOk();
            $this->actingAs($admin)->get("/bil/jumbo-rolls/reports/{$slug}/download?format=csv")->assertOk();
        }

        $this->actingAs($admin)->get('/bil/jumbo-rolls/reports/nope/print')->assertNotFound();
        $this->actingAs($admin)->get('/bil/jumbo-rolls/reports/consumption/download?format=zip')->assertNotFound();
        $this->actingAs($admin)->get('/bil/jumbo-rolls/statistics/export?format=csv&section=overview')->assertOk();

        $this->actingAs($this->outsider('bil.jumbo_rolls.reports.consumption'))
            ->get('/bil/jumbo-rolls/reports/consumption/print')->assertForbidden();
    }

    public function test_the_pages_are_registered(): void
    {
        $pages = collect(config('pages.pages'));

        foreach (self::reports() as $label => [, $pageKey]) {
            $page = $pages->firstWhere('key', $pageKey);
            $this->assertNotNull($page, "{$label} report not declared in config/pages.php");
            $this->assertSame(['view', 'export'], $page['abilities'], "{$label} report should be read-only");
        }

        $stats = $pages->firstWhere('key', 'bil.jumbo_rolls.statistics');
        $this->assertNotNull($stats, 'statistics not declared in config/pages.php');
        $this->assertSame(['view', 'export'], $stats['abilities']);
    }
}
