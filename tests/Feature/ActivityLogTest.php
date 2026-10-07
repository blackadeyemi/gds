<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Core\Livewire\ActivityLog;
use Modules\Core\Livewire\Admin\Roles;
use Modules\Core\Livewire\NotificationBell;
use Modules\Core\Models\Activity;
use Modules\Core\Models\ActivityNotification;
use Modules\Core\Models\User;
use Modules\Core\Support\Activity\ActivityRecorder;
use Modules\Core\Support\Activity\SqlWrite;
use Modules\Bil\Livewire\RawMaterials\Statistics as RawMaterialsStatistics;
use Tests\TestCase;

/** A scratch table of the test's own, so nothing real is written. */
class ActivityProbe extends Model
{
    protected $connection = 'core';
    protected $table = 'activity_probe';
    public $timestamps = false;
    protected $guarded = [];
}

/**
 * The activity log and its notifications, end to end against the live core DB.
 *
 * Every write goes to `core.activity_probe`, a table this test creates and
 * drops. The log rows it causes are found by id above a baseline AND by
 * pointing at the probe table (or the probe's fake username), never by date —
 * see tests that once deleted real rows by date.
 */
class ActivityLogTest extends TestCase
{
    private const FAKE_LOGIN = 'zz-activity-probe-nouser';

    private int $baseline = 0;

    private ActivityRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('core')->dropIfExists('activity_probe');
        Schema::connection('core')->create('activity_probe', function ($t) {
            $t->engine = 'InnoDB';
            $t->increments('id');
            $t->string('barcode', 40);
            $t->decimal('weight', 10, 2)->nullable();
            $t->string('username', 60)->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        $this->baseline = (int) DB::connection('core')->table('activities')->max('id');
        $this->recorder = ActivityRecorder::instance();
        $this->recorder->reset();
        $this->recorder->forceEnabled(true);
    }

    protected function tearDown(): void
    {
        $this->recorder->forceEnabled(null);
        $this->recorder->reset();

        $mine = DB::connection('core')->table('activities as a')
            ->where('a.id', '>', $this->baseline)
            ->where(function ($w) {
                $w->whereExists(fn ($q) => $q->from('activity_items as i')
                    ->whereColumn('i.activity_id', 'a.id')
                    ->where('i.subject_type', 'core.activity_probe'))
                  ->orWhere(fn ($q) => $q->where('a.action', 'auth')->where('a.refs', self::FAKE_LOGIN));
            })
            ->pluck('a.id');
        DB::connection('core')->table('activities')->whereIn('id', $mine)->delete(); // items + notifications cascade

        Schema::connection('core')->dropIfExists('activity_probe');

        parent::tearDown();
    }

    private function admins()
    {
        return User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->orderBy('userid')->get();
    }

    private function rawMaterialsUser(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('name', 'Raw Materials User'))->orderBy('userid')->first();
        $this->assertNotNull($u);
        $this->assertTrue($u->canAccessPage('bil.raw_materials.statistics'));

        return $u;
    }

    private function noAccessUser(): User
    {
        $u = User::with('roles')->get()->first(fn ($x) => ! $x->isAdmin() && $x->accessiblePageKeys() === []);
        $this->assertNotNull($u, 'no user without grants to test with');

        return $u;
    }

    /** Run $fn as if it were a Livewire method on $component, then flush. */
    private function asCall(object $component, string $method, callable $fn): ?Activity
    {
        $before = (int) DB::connection('core')->table('activities')->max('id');
        $this->recorder->beginCall($component, $method);
        try {
            $fn();
        } finally {
            $this->recorder->endCall();
        }

        return Activity::with('items')->where('id', '>', $before)->orderByDesc('id')->first();
    }

    public function test_a_save_is_one_activity_with_every_change_it_made(): void
    {
        $this->actingAs($admin = $this->admins()->first());

        $a = $this->asCall(new RawMaterialsStatistics, 'save', function () {
            $p = ActivityProbe::create(['barcode' => 'PROBE-1', 'weight' => 10]);
            $p->update(['weight' => 12.5]);
            DB::connection('core')->table('activity_probe')->insert(['barcode' => 'PROBE-2', 'weight' => 3]);
            DB::connection('core')->table('activity_probe')->where('barcode', 'PROBE-2')->update(['weight' => 4]);
            DB::connection('core')->table('activity_probe')->where('barcode', 'PROBE-2')->delete();
        });

        $this->assertNotNull($a);
        $this->assertSame((int) $admin->userid, (int) $a->user_id);
        $this->assertSame('bil.raw_materials.statistics', $a->page_key);
        $this->assertSame('BIL / Raw Materials', $a->module);
        $this->assertSame('Saved', $a->verb);
        $this->assertSame('mixed', $a->action);
        $this->assertSame(5, (int) $a->item_count);
        $this->assertStringContainsString('PROBE-1', $a->refs);
        $this->assertStringContainsString('PROBE-2', $a->refs);

        // The model writes are logged once each, from the model event (with ids
        // and before/after), not a second time from their queries.
        $model = $a->items->where('source', 'model')->values();
        $this->assertCount(2, $model);
        $this->assertSame('created', $model[0]->op);
        $this->assertSame('updated', $model[1]->op);
        $this->assertSame($model[0]->subject_id, $model[1]->subject_id);
        $this->assertEquals(10, $model[1]->changes['before']['weight']);
        $this->assertEquals(12.5, $model[1]->changes['after']['weight']);

        $query = $a->items->where('source', 'query')->values();
        $this->assertSame(['created', 'updated', 'deleted'], $query->pluck('op')->all());
        $this->assertNotNull($query[0]->subject_id, 'a single-row insert carries its new id');
        $this->assertSame(['barcode' => 'PROBE-2'], $query[1]->changes['where']);
        $this->assertEquals(4, $query[1]->changes['after']['weight']);
    }

    public function test_a_rolled_back_change_is_not_logged(): void
    {
        $this->actingAs($this->admins()->first());

        $a = $this->asCall(new RawMaterialsStatistics, 'save', function () {
            try {
                DB::connection('core')->transaction(function () {
                    ActivityProbe::create(['barcode' => 'PROBE-ROLLBACK']);
                    DB::connection('core')->table('activity_probe')->insert(['barcode' => 'PROBE-ROLLBACK-2']);
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
            }
        });

        $this->assertNull($a);
        $this->assertSame(0, ActivityProbe::count());
    }

    public function test_a_committed_inner_change_survives_and_a_rolled_back_one_does_not(): void
    {
        $this->actingAs($this->admins()->first());

        $a = $this->asCall(new RawMaterialsStatistics, 'save', function () {
            DB::connection('core')->transaction(function () {
                ActivityProbe::create(['barcode' => 'PROBE-KEEP']);
                try {
                    DB::connection('core')->transaction(function () {
                        ActivityProbe::create(['barcode' => 'PROBE-DROP']);
                        throw new \RuntimeException('inner');
                    });
                } catch (\RuntimeException) {
                }
            });
        });

        $this->assertNotNull($a);
        $this->assertSame(['PROBE-KEEP'], $a->items->pluck('ref')->all());
    }

    public function test_nothing_is_logged_from_the_console_or_without_a_user(): void
    {
        $this->recorder->forceEnabled(null); // back to "not in the console"
        $this->actingAs($this->admins()->first());
        $this->assertNull($this->asCall(new RawMaterialsStatistics, 'save',
            fn () => ActivityProbe::create(['barcode' => 'PROBE-CLI'])));

        $this->recorder->forceEnabled(true);
        auth()->logout();
        $this->assertNull($this->asCall(new RawMaterialsStatistics, 'save',
            fn () => ActivityProbe::create(['barcode' => 'PROBE-ANON'])));
    }

    public function test_activity_is_visible_to_whoever_can_open_its_page(): void
    {
        $this->actingAs($this->admins()->first());
        $a = $this->asCall(new RawMaterialsStatistics, 'save',
            fn () => ActivityProbe::create(['barcode' => 'PROBE-VIS']));

        $this->assertTrue($a->isVisibleTo($this->rawMaterialsUser()));
        $this->assertTrue(Activity::visibleTo($this->rawMaterialsUser())->whereKey($a->id)->exists());
        $this->assertFalse($a->isVisibleTo($this->noAccessUser()));
        $this->assertFalse(Activity::visibleTo($this->noAccessUser())->whereKey($a->id)->exists());

        // The page lists it for one and not the other, and refuses to open it.
        $this->actingAs($this->rawMaterialsUser());
        Livewire::test(ActivityLog::class)->set('search', 'PROBE-VIS')->assertSee('PROBE-VIS')
            ->call('openActivity', $a->id)->assertSet('openId', $a->id);

        $this->actingAs($this->noAccessUser());
        Livewire::test(ActivityLog::class)->set('search', 'PROBE-VIS')->assertDontSee('PROBE-VIS')
            ->call('openActivity', $a->id)->assertSet('openId', null);
    }

    public function test_the_person_who_entered_a_record_hears_when_someone_else_changes_it(): void
    {
        $owner = $this->rawMaterialsUser();
        $this->actingAs($owner);
        $created = $this->asCall(new RawMaterialsStatistics, 'save',
            fn () => ActivityProbe::create(['barcode' => 'PROBE-OWN', 'weight' => 1]));
        $this->assertSame(0, ActivityNotification::where('activity_id', $created->id)->count(), 'no one hears about a create');

        $admin = $this->admins()->first();
        $this->actingAs($admin);
        $changed = $this->asCall(new RawMaterialsStatistics, 'save',
            fn () => ActivityProbe::where('barcode', 'PROBE-OWN')->first()->update(['weight' => 2]));

        $n = ActivityNotification::where('activity_id', $changed->id)->get();
        $this->assertSame([(int) $owner->userid], $n->pluck('user_id')->map(fn ($x) => (int) $x)->all());
        $this->assertSame('your_record', $n[0]->reason);
        $this->assertStringContainsString('PROBE-OWN', $n[0]->note);

        // A legacy row names its creator in a username column instead.
        $legacy = ActivityProbe::create(['barcode' => 'PROBE-LEGACY', 'username' => $owner->username]);
        $this->recorder->reset(); // that create happened "before the log"
        $deleted = $this->asCall(new RawMaterialsStatistics, 'deleteConfirmed', fn () => $legacy->delete());
        $this->assertSame('Deleted', $deleted->verb);
        $this->assertSame([(int) $owner->userid],
            ActivityNotification::where('activity_id', $deleted->id)->pluck('user_id')->map(fn ($x) => (int) $x)->all());
        $this->assertSame('PROBE-LEGACY', $deleted->items[0]->changes['attributes']['barcode'], 'a delete keeps what was deleted');
    }

    public function test_admins_hear_about_admin_pages_only_and_never_their_own_changes(): void
    {
        $admins = $this->admins();
        $this->assertGreaterThan(1, $admins->count());
        $actor = $admins->first();
        $this->actingAs($actor);

        $a = $this->asCall(new Roles, 'save', fn () => ActivityProbe::create(['barcode' => 'PROBE-ADMIN']));
        $to = ActivityNotification::where('activity_id', $a->id)->pluck('user_id')->map(fn ($x) => (int) $x);

        $this->assertNotContains((int) $actor->userid, $to->all());
        $this->assertSame($admins->count() - 1, $to->count());
        $this->assertSame(['admin'], ActivityNotification::where('activity_id', $a->id)->distinct()->pluck('reason')->all());

        // …and an admin is not told about an ordinary page's changes, even
        // about a record they entered.
        $this->actingAs($this->rawMaterialsUser());
        $b = $this->asCall(new RawMaterialsStatistics, 'save',
            fn () => ActivityProbe::where('barcode', 'PROBE-ADMIN')->first()->update(['weight' => 9]));
        $this->assertSame(0, ActivityNotification::where('activity_id', $b->id)->count());
    }

    public function test_an_approval_request_reaches_the_approvers_unless_they_muted_the_module(): void
    {
        $approver = $this->rawMaterialsUser();
        config(['activity.approvals' => [[
            'page' => 'bil.raw_materials.statistics', 'methods' => ['save'],
            'ability' => 'view', 'label' => 'Probe to approve',
        ]]]);

        $this->actingAs($this->admins()->first());
        $a = $this->asCall(new RawMaterialsStatistics, 'save', fn () => ActivityProbe::create(['barcode' => 'PROBE-APPROVE']));
        $n = ActivityNotification::where('activity_id', $a->id)->where('user_id', $approver->userid)->first();
        $this->assertNotNull($n);
        $this->assertSame('approval', $n->reason);
        $this->assertSame('Probe to approve', $n->note);
        $this->assertSame(0, ActivityNotification::where('activity_id', $a->id)
            ->whereIn('user_id', $this->admins()->pluck('userid'))->count(), 'admins get admin notices only');

        // Muted: the activity is still logged, the bell stays quiet.
        DB::connection('core')->table('activity_mutes')->insert(['user_id' => $approver->userid, 'module' => 'BIL / Raw Materials']);
        try {
            $b = $this->asCall(new RawMaterialsStatistics, 'save', fn () => ActivityProbe::create(['barcode' => 'PROBE-MUTED']));
            $this->assertNotNull($b);
            $this->assertSame(0, ActivityNotification::where('activity_id', $b->id)->where('user_id', $approver->userid)->count());
        } finally {
            DB::connection('core')->table('activity_mutes')
                ->where('user_id', $approver->userid)->where('module', 'BIL / Raw Materials')->delete();
        }
    }

    public function test_the_bell_counts_and_clears_unread(): void
    {
        $owner = $this->rawMaterialsUser();
        $this->actingAs($owner);
        $this->asCall(new RawMaterialsStatistics, 'save', fn () => ActivityProbe::create(['barcode' => 'PROBE-BELL']));
        $this->actingAs($this->admins()->first());
        $a = $this->asCall(new RawMaterialsStatistics, 'save',
            fn () => ActivityProbe::where('barcode', 'PROBE-BELL')->first()->update(['weight' => 5]));

        $this->actingAs($owner);
        $unread = ActivityNotification::unreadCount((int) $owner->userid);
        $this->assertGreaterThan(0, $unread);

        Livewire::test(NotificationBell::class)
            ->assertSee('PROBE-BELL')
            ->call('open', ActivityNotification::where('activity_id', $a->id)->value('id'))
            ->assertRedirect(route('activity', ['view' => 'notifications', 'open' => $a->id]));

        $this->assertNotNull(ActivityNotification::where('activity_id', $a->id)->value('read_at'));
        $this->assertSame($unread - 1, ActivityNotification::unreadCount((int) $owner->userid));
    }

    public function test_repeated_failed_sign_ins_alert_the_admins(): void
    {
        $threshold = (int) config('activity.failed_logins.threshold');

        for ($i = 1; $i <= $threshold; $i++) {
            $this->post('/login', ['username' => self::FAKE_LOGIN, 'password' => 'wrong-' . $i]);
        }

        $failed = Activity::where('id', '>', $this->baseline)->where('action', 'auth')->where('refs', self::FAKE_LOGIN)->orderBy('id')->get();
        $this->assertCount($threshold, $failed);
        $this->assertSame('Sign-in failed', $failed->last()->verb);

        $this->assertSame(0, ActivityNotification::whereIn('activity_id', $failed->slice(0, -1)->pluck('id'))->count());
        $alerted = ActivityNotification::where('activity_id', $failed->last()->id)->pluck('user_id')->map(fn ($x) => (int) $x)->sort()->values()->all();
        $this->assertSame($this->admins()->pluck('userid')->map(fn ($x) => (int) $x)->sort()->values()->all(), $alerted);

        // Sign-ins are admin-only reading.
        $this->assertFalse($failed->last()->isVisibleTo($this->rawMaterialsUser()));
    }

    public function test_the_activity_page_and_bell_render_for_everyone(): void
    {
        foreach ([$this->admins()->first(), $this->rawMaterialsUser(), $this->noAccessUser()] as $user) {
            $this->actingAs($user)->get('/activity')->assertOk()
                ->assertSee('All activity')->assertSee('My notifications')->assertSee('notif-bell', false);
        }

        // Read-only for everyone, Admin included.
        $this->actingAs($this->admins()->first());
        $page = Livewire::test(ActivityLog::class);
        $this->assertFalse($page->instance()->mayDo('edit'));
        $this->assertFalse($page->instance()->mayDo('delete'));
        $this->assertTrue($page->instance()->mayDo('export'));
    }

    public function test_a_grid_shows_the_history_of_the_record_being_edited(): void
    {
        $grid = new \Modules\Core\Livewire\Admin\Companies;
        $this->assertSame('core.companies', $grid->historySubject());

        // History is read by subject; plant one item against a company id that
        // cannot exist, and read it back through the grid.
        $this->actingAs($admin = $this->admins()->first());
        $a = $this->asCall($grid, 'save', fn () => ActivityProbe::create(['barcode' => 'PROBE-HISTORY']));
        DB::connection('core')->table('activity_items')->insert([
            'activity_id' => $a->id, 'op' => 'updated', 'subject_type' => 'core.companies',
            'subject_id' => '-424242', 'ref' => 'PROBE-HISTORY',
            'changes' => json_encode(['before' => ['name' => 'Old'], 'after' => ['name' => 'New']]), 'source' => 'model',
        ]);

        $grid->editingId = -424242;
        $history = $grid->recordHistory();
        $this->assertCount(1, $history);
        $this->assertSame([['Name', 'Old', 'New']], $history[0]->rows());
        $this->assertSame($admin->username, $history[0]->activity->username);

        $this->actingAs($this->noAccessUser());
        $this->assertCount(0, $grid->recordHistory(), 'not shown to someone who cannot open the page it was made on');
    }

    public function test_a_soft_delete_through_the_builder_reads_as_a_delete(): void
    {
        $this->actingAs($this->admins()->first());
        $id = DB::connection('core')->table('activity_probe')->insertGetId(['barcode' => 'PROBE-SOFT']);
        $this->recorder->reset();

        $a = $this->asCall(new RawMaterialsStatistics, 'deleteConfirmed', fn () => DB::connection('core')
            ->table('activity_probe')->where('id', $id)->update(['deleted_at' => now()]));

        $this->assertSame('deleted', $a->action);
        $this->assertSame('deleted', $a->items[0]->op);
        $this->assertSame((string) $id, $a->items[0]->subject_id);
    }

    public function test_the_sql_reader(): void
    {
        $p = SqlWrite::parse('insert into `t` (`barcode`, `weight`) values (?, ?), (?, ?)', ['A', 1, 'B', 2], 'bil');
        $this->assertSame(['op' => 'created', 'table' => 'bil.t', 'columns' => ['barcode' => 'A', 'weight' => 1], 'rows' => 2, 'where' => []], $p);

        $p = SqlWrite::parse('update `core`.`x` set `status` = ?, `n` = `n` + ? where `barcode` = ? and `id` in (?, ?)', ['ok', 1, 'B1', 7, 8], 'bil');
        $this->assertSame('core.x', $p['table']);
        $this->assertSame(['status' => 'ok'], $p['columns']);
        $this->assertSame(['barcode' => 'B1', 'id' => [7, 8]], $p['where']);

        $p = SqlWrite::parse("delete from `return_approval` where `barcode` = ? and note = 'a?b'", ['X'], 'bil');
        $this->assertSame(['barcode' => 'X'], $p['where']);

        $p = SqlWrite::parse('INSERT INTO `s` (`a`, `b`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `a` = `a` + ?', [1, 2, 3], 'bil');
        $this->assertSame(['a' => 1, 'b' => 2], $p['columns']);

        $this->assertNull(SqlWrite::parse('select * from t where id = ?', [1], 'bil'));
    }
}
