<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Livewire\Settings\ActivitySettings;
use Modules\Core\Models\User;
use Modules\Core\Support\Activity\PageContext;
use Modules\Core\Support\Activity\Retention;
use Modules\Core\Support\Settings;
use Tests\TestCase;

/**
 * Settings → Activity Log: the retention period, Admin only.
 *
 * Writes the real `core.app_settings` row for activity.retention_days, so the
 * row as it was before the test is put back exactly afterwards.
 */
class ActivitySettingsTest extends TestCase
{
    private ?object $before = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->before = DB::connection('core')->table('app_settings')->where('key', Retention::KEY)->first();
        Settings::flush();
    }

    protected function tearDown(): void
    {
        $t = DB::connection('core')->table('app_settings');
        $t->where('key', Retention::KEY)->delete();
        if ($this->before) {
            DB::connection('core')->table('app_settings')->insert((array) $this->before);
        }
        Settings::flush();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->orderBy('userid')->first();
    }

    private function nonAdmin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('name', 'Raw Materials User'))->orderBy('userid')->first();
        $this->assertFalse($u->isAdmin());

        return $u;
    }

    public function test_only_an_admin_can_open_it_or_see_it_in_the_menu(): void
    {
        $this->actingAs($this->admin())->get('/settings/activity-log')->assertOk()
            ->assertSee('Keep entries for (days)');
        $this->actingAs($this->admin())->get('/')->assertSee(route('settings.activity-log'), false);

        $this->actingAs($this->nonAdmin())->get('/settings/activity-log')->assertForbidden();
        $this->actingAs($this->nonAdmin())->get('/')->assertDontSee(route('settings.activity-log'), false);

        // A Livewire update does not pass through the route middleware; the
        // component refuses on its own.
        $this->actingAs($this->nonAdmin());
        Livewire::test(ActivitySettings::class)->assertForbidden();
    }

    public function test_it_is_not_a_grantable_page(): void
    {
        $this->assertFalse(collect(config('pages.pages'))->contains(fn ($p) => str_contains($p['key'], 'activity')));
        $this->assertSame(0, DB::connection('core')->table('permissions')->where('name', 'like', '%activity%')->count());

        // Its own changes file under Settings, which is what sends them to the
        // other Admins' bells.
        $this->assertSame(['label' => 'Activity Log', 'module' => 'Settings'], PageContext::page('settings.activity-log'));
    }

    public function test_an_admin_sets_the_days_within_bounds_and_can_go_back_to_the_default(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ActivitySettings::class)
            ->set('days', Retention::min() - 1)->call('save')->assertHasErrors('days')
            ->set('days', Retention::max() + 1)->call('save')->assertHasErrors('days')
            ->set('days', 'abc')->call('save')->assertHasErrors('days')
            ->set('days', 400)->call('save')->assertHasNoErrors()->assertSet('days', 400);

        Settings::flush();
        $this->assertSame(400, Retention::days());
        $this->assertSame((string) $this->admin()->username, Settings::meta(Retention::KEY)->updated_by_name);

        $this->artisan('gds:prune-activity')->expectsOutputToContain('(400 days)');

        Livewire::test(ActivitySettings::class)->call('useDefault')->assertSet('days', Retention::defaultDays());
        Settings::flush();
        $this->assertFalse(Settings::isOverridden(Retention::KEY));
        $this->assertSame((int) config('activity.retention_days'), Retention::days());
    }

    public function test_it_shows_what_a_setting_would_delete_before_saving(): void
    {
        $this->actingAs($this->admin());

        $page = Livewire::test(ActivitySettings::class)->set('days', Retention::min());
        $impact = $page->instance()->impact;

        $this->assertSame(Retention::min(), $impact['days']);
        $this->assertSame(Retention::dueCount(Retention::min()), $impact['due']);
        $this->assertTrue($impact['cutoff']->equalTo(now()->subDays(Retention::min())->startOfDay()));

        $this->assertNull($page->set('days', 5)->instance()->impact, 'no preview for a value it would refuse');
    }
}
