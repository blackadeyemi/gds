<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bil\Livewire\JumboRolls\Returns;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BIL → Jumbo Rolls → Returns: the route, the `page:` middleware and the scan
 * validation. Read-only — nothing is returned. The write path (event written,
 * entrance flipped to 'return', floor stock decremented) is exercised against
 * live data in a rolled-back transaction rather than here.
 */
class JumboRollReturnsPageTest extends TestCase
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
            ->first(fn (User $u) => ! $u->canAccessPage('bil.jumbo_rolls.returns'));
        $this->assertNotNull($u, 'no non-admin user without the page');

        return $u;
    }

    protected function screen()
    {
        Livewire::actingAs($this->admin());

        return Livewire::test(Returns::class);
    }

    public function test_admin_can_open_the_page(): void
    {
        $res = $this->actingAs($this->admin())->get('/bil/jumbo-rolls/returns');

        $res->assertOk();
        $res->assertSee('Returns');
        $res->assertSee('Returning to');
        $res->assertSee('Reason');
    }

    public function test_a_user_without_the_page_is_refused(): void
    {
        $this->actingAs($this->outsider())
            ->get('/bil/jumbo-rolls/returns')
            ->assertForbidden();
    }

    /** An untouched reel on a factory floor goes back whole, at its full weight. */
    public function test_a_whole_reel_can_be_returned(): void
    {
        $barcode = DB::connection('bil')->table('factory_entrance_reel')
            ->where('is_deleted', 0)->whereNull('status')->orderByDesc('id')->value('barcode');

        if (! $barcode) {
            $this->markTestSkipped('no untouched reel on a factory floor right now');
        }

        $items = $this->screen()
            ->set('scan', strtolower($barcode))   // scanners are not case-fussy
            ->call('addScan')
            ->assertSet('scanError', '')
            ->get('items');

        $this->assertCount(1, $items);
        $this->assertSame($barcode, $items[0]['barcode']);
        $this->assertSame(Returns::WHOLE, $items[0]['state']);

        $made = (float) DB::connection('bil')->table('bpl_production')
            ->where('barcode', $barcode)->value('weight');
        $this->assertSame($made, $items[0]['weight'], 'a whole reel goes back at its production weight');
    }

    /** A reel already put on a machine is no longer returnable as a whole reel. */
    public function test_a_consumed_reel_cannot_be_returned(): void
    {
        $barcode = DB::connection('bil')->table('factory_usage_reel')
            ->where('is_deleted', 0)->orderByDesc('id')->value('barcode');
        $this->assertNotNull($barcode, 'no consumption rows to test against');

        $this->screen()
            ->set('scan', $barcode)
            ->call('addScan')
            ->assertSet('scanError', 'Barcode does not exist, or is already in use.')
            ->assertSet('items', []);
    }

    public function test_something_already_returned_is_refused(): void
    {
        $barcode = DB::connection('bil')->table('factory_event')
            ->where('event', 'return')->orderByDesc('id')->value('barcode');

        if (! $barcode) {
            $this->markTestSkipped('nothing has been returned yet');
        }

        $this->screen()
            ->set('scan', $barcode)
            ->call('addScan')
            ->assertSet('scanError', 'This has already been returned.')
            ->assertSet('items', []);
    }

    public function test_an_unknown_barcode_is_refused(): void
    {
        $this->screen()
            ->set('scan', 'NOT-A-REEL')
            ->call('addScan')
            ->assertSet('scanError', 'Barcode does not exist, or is already in use.')
            ->assertSet('items', []);
    }

    public function test_the_same_barcode_cannot_be_scanned_twice(): void
    {
        $barcode = DB::connection('bil')->table('factory_entrance_reel')
            ->where('is_deleted', 0)->whereNull('status')->value('barcode');

        if (! $barcode) {
            $this->markTestSkipped('no untouched reel on a factory floor right now');
        }

        $this->screen()
            ->set('scan', $barcode)->call('addScan')
            ->set('scan', $barcode)->call('addScan')
            ->assertSet('scanError', 'Barcode already scanned.')
            ->assertCount('items', 1);
    }

    /** The reason is optional, and long enough to be useful. */
    public function test_the_reason_is_optional(): void
    {
        $c = $this->screen();

        $this->assertSame('', $c->get('reason'), 'the reason starts empty');
        $this->assertGreaterThanOrEqual(255, Returns::REASON_MAX);

        // The column that stores it exists and takes a null.
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::connection('bil')->hasColumn('factory_event', 'reason'),
            'factory_event has no reason column'
        );
    }

    /**
     * The return carries its own date, defaulting to today, and the column
     * behind it is complete — the backfill dated every historic event from the
     * timestamp it already held.
     */
    public function test_the_return_carries_a_date(): void
    {
        $c = $this->screen();

        $this->assertSame(now()->format('Y-m-d'), $c->get('dateIso'), 'the date should default to today');
        $this->assertTrue($c->instance()->canBackdate(), 'an admin can set the date of return');

        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::connection('bil')->hasColumn('factory_event', 'date'),
            'factory_event has no date column'
        );

        $undated = DB::connection('bil')->table('factory_event')
            ->whereNull('date')->where('timestamp', '>', 0)->count();
        $this->assertSame(0, $undated, 'historic events were left without a date');
    }

    public function test_the_page_is_registered_and_shift_gated(): void
    {
        $page = collect(config('pages.pages'))->firstWhere('key', 'bil.jumbo_rolls.returns');

        $this->assertNotNull($page, 'page not declared in config/pages.php');
        $this->assertSame(['view', 'backdate', 'bypass-shift'], $page['abilities']);

        $this->assertSame('bil.jumbo_rolls.returns', (new Returns())->shiftKey());
        $this->assertNotNull(
            collect(config('shifts.contexts'))->firstWhere('key', 'bil.jumbo_rolls.returns'),
            'shift context not declared in config/shifts.php'
        );
    }
}
