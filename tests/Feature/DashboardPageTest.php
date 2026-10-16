<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Core\Support\Attention;
use Modules\Core\Livewire\DashboardStats;
use Modules\Core\Models\User;
use Modules\Core\Support\DashboardCatalog;
use Tests\TestCase;

/**
 * Home dashboard: profile on top, then one card per module the user's role
 * grants statistics or reports for. Read-only against the live databases.
 */
class DashboardPageTest extends TestCase
{
    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    /** A non-admin holding only the Raw Materials statistics + reports. */
    private function rawMaterialsUser(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('name', 'Raw Materials User'))->first();
        $this->assertNotNull($u, 'no Raw Materials User to test with');

        return $u;
    }

    public function test_the_profile_and_every_module_render_for_an_admin(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin)->get('/')->assertOk()
            ->assertSee('@' . $admin->username)
            ->assertSee('All companies')
            ->assertSee('Belimpex')
            ->assertSee('Belpapyrus');

        foreach (['Raw Materials', 'Finished Goods', 'Machines', 'Logistics', 'Finance'] as $module) {
            $res->assertSee($module);
        }
    }

    public function test_a_role_sees_only_the_modules_it_is_granted(): void
    {
        $user = $this->rawMaterialsUser();
        $catalog = DashboardCatalog::for($user);

        $this->assertSame(['BIL'], array_keys($catalog));
        $this->assertSame(['Raw Materials'], array_column($catalog['BIL'], 'name'));
        $this->assertSame('bil.raw_materials.statistics', $catalog['BIL'][0]['stats']);

        $this->actingAs($user)->get('/')->assertOk()
            ->assertSee('Raw Materials User')
            ->assertDontSee('Belpapyrus')
            ->assertDontSee('Finished Goods');
    }

    public function test_a_role_with_no_grants_gets_the_empty_state(): void
    {
        $user = User::with('roles')->get()->first(fn ($u) => ! $u->isAdmin()
            && DashboardCatalog::for($u) === [] && DashboardCatalog::workFor($u) === []
            && Attention::itemsFor($u) === []);
        $this->assertNotNull($user);

        $this->actingAs($user)->get('/')->assertOk()
            ->assertSee('Nothing to show yet')
            ->assertDontSee('Your work')
            ->assertDontSee('Needs attention');
    }

    /** "Your work" lists the screens a role holds, never reports or statistics. */
    public function test_your_work_lists_only_granted_working_screens(): void
    {
        $user = $this->rawMaterialsUser();
        $work = DashboardCatalog::workFor($user);

        $this->assertNotEmpty($work);
        foreach ($work as $group) {
            foreach ($group['pages'] as $p) {
                $this->assertStringNotContainsString('/reports/', $p['url']);
                $this->assertStringNotContainsString('/statistics', $p['url']);
            }
        }

        $this->actingAs($user)->get('/')->assertOk()
            ->assertSee('Your work')
            ->assertSee(route('bil.raw-materials.warehouse-entry'), false);
    }

    /** An approval queue shows only to roles that can approve, not merely view. */
    public function test_attention_items_follow_the_ability_they_are_gated_on(): void
    {
        $admin = $this->admin();
        $user = $this->rawMaterialsUser();

        $all = array_column(Attention::itemsFor($admin), 'key');
        $this->assertContains('bil.rm.returns_pending', $all);
        $this->assertContains('bpl.finance.overdue', $all);

        foreach (Attention::itemsFor($user) as $item) {
            $this->assertTrue($user->canDo($item['page'], $item['ability'] ?? 'view'));
        }
        if (! $user->canDo('bil.raw_materials.factory_returns', 'approve')) {
            $this->assertNotContains('bil.rm.returns_pending', array_column(Attention::itemsFor($user), 'key'));
        }
    }

    /** Every count runs, and its card opens a real page. */
    public function test_every_attention_item_resolves(): void
    {
        foreach (Attention::itemsFor($this->admin()) as $item) {
            Cache::forget('dashboard:attention:' . $item['key']);
            $r = Attention::resolve($item);

            $this->assertNotNull($r, $item['key'] . ' failed');
            $this->assertGreaterThanOrEqual(0, $r['count']);
            $this->assertArrayHasKey($r['tone'], Attention::TONES);
        }
    }

    public function test_the_attention_strip_renders(): void
    {
        $this->actingAs($this->admin());

        Livewire::withoutLazyLoading()->test(\Modules\Core\Livewire\DashboardAttention::class)
            ->assertOk()
            ->assertSee('Overdue shipments');
    }

    /** Cards deep-link: ?view= opens a named report view, ?mode=held the held list. */
    public function test_attention_links_open_the_list_they_counted(): void
    {
        $this->actingAs($this->admin());

        Livewire::withQueryParams(['view' => 'overdue'])
            ->test(\Modules\Bpl\Livewire\Finance\Reports\Receivables::class)
            ->assertSet('view', 'overdue');

        Livewire::withQueryParams(['view' => 'not-a-view'])
            ->test(\Modules\Bpl\Livewire\Finance\Reports\Receivables::class)
            ->assertSet('view', 'default');

        Livewire::withQueryParams(['mode' => 'held'])
            ->test(\Modules\Bpl\Livewire\JumboRolls\WaitingArea::class)
            ->assertSet('mode', 'held');
    }

    /** The card renders the module's own Overview tiles. */
    public function test_the_stats_card_shows_the_overview(): void
    {
        $this->actingAs($this->admin());

        Livewire::withoutLazyLoading()
            ->test(DashboardStats::class, ['pageKey' => 'bil.raw_materials.statistics'])
            ->assertSee('Overview')
            ->assertSee('Last 30 days');
    }

    /** The page key is client-visible: a user cannot ask for a module they lack. */
    public function test_the_stats_card_refuses_a_module_the_user_cannot_view(): void
    {
        $this->actingAs($this->rawMaterialsUser());

        Livewire::withoutLazyLoading()
            ->test(DashboardStats::class, ['pageKey' => 'bpl.finance.statistics'])
            ->assertForbidden();
    }

    /** A range a module lacks becomes its closest one; ties go to the longer window. */
    public function test_an_unoffered_range_falls_to_the_closest_one(): void
    {
        $rm = new \Modules\Bil\Livewire\RawMaterials\Statistics;   // 7d … 12m
        $bplSales = new \Modules\Bpl\Livewire\Sales\Statistics;    // 90d … all
        $logistics = new \Modules\Bpl\Livewire\Logistics\Statistics; // 30d … all

        $this->assertSame('30d', $rm->useDashboardRange(null));    // own default
        $this->assertSame('90d', $rm->useDashboardRange('90d'));
        $this->assertSame('12m', $rm->useDashboardRange('all'));
        $this->assertSame('12m', $bplSales->useDashboardRange(null));
        $this->assertSame('90d', $bplSales->useDashboardRange('7d'));
        $this->assertSame('90d', $bplSales->useDashboardRange('30d'));
        $this->assertSame('30d', $logistics->useDashboardRange('7d'));
        $this->assertSame('all', $logistics->useDashboardRange('all'));
    }

    public function test_the_chosen_range_reaches_the_cards_and_the_statistics_links(): void
    {
        $this->actingAs($this->admin());

        Livewire::withQueryParams(['range' => '7d'])->test(\Modules\Core\Livewire\Dashboard::class)
            ->assertSee('Figures for')
            ->assertSee('/bil/raw-materials/statistics?range=7d', false)
            ->assertSee('/bpl/sales/statistics?range=90d', false);

        Livewire::withoutLazyLoading()
            ->test(DashboardStats::class, ['pageKey' => 'bpl.sales.statistics', 'range' => '7d'])
            ->assertSee('Last 90 days')
            ->assertSee('closest available');

        Livewire::withoutLazyLoading()
            ->test(DashboardStats::class, ['pageKey' => 'bil.raw_materials.statistics', 'range' => '90d'])
            ->assertSee('Last 90 days')
            ->assertDontSee('closest available');
    }

    /** Only registered statistics pages resolve — never an arbitrary key. */
    public function test_only_statistics_pages_resolve_to_a_class(): void
    {
        $this->assertNotNull(DashboardCatalog::statisticsClass('bpl.sales.statistics'));
        $this->assertNull(DashboardCatalog::statisticsClass('bpl.sales.reports.orders'));
        $this->assertNull(DashboardCatalog::statisticsClass('nope.statistics'));
    }
}
