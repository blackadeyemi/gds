<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bil\Livewire\JumboRolls\FactoryEntrance;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BIL → Jumbo Rolls → Factory Entrance: the route, the `page:` middleware, the
 * gate list and the scan validation. Read-only — no entrance is saved.
 */
class JumboRollFactoryEntrancePageTest extends TestCase
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
            ->first(fn (User $u) => ! $u->canAccessPage('bil.jumbo_rolls.factory_entrance'));
        $this->assertNotNull($u, 'no non-admin user without the page');

        return $u;
    }

    public function test_admin_can_open_the_page(): void
    {
        $res = $this->actingAs($this->admin())->get('/bil/jumbo-rolls/factory-entrance');

        $res->assertOk();
        $res->assertSee('Factory Entrance');
        $res->assertSee('Entrance Location');
        $res->assertSee('Hardroll No.');
    }

    public function test_a_user_without_the_page_is_refused(): void
    {
        $this->actingAs($this->outsider())
            ->get('/bil/jumbo-rolls/factory-entrance')
            ->assertForbidden();
    }

    /** Reels arrive at BIL factories, never at the paper machines that made them. */
    public function test_only_inbound_gates_of_this_company_are_offered(): void
    {
        $this->actingAs($this->admin());
        $gates = (new FactoryEntrance())->gates();

        $this->assertNotEmpty($gates, 'admin should see every usable BIL entrance gate');

        $companyIds = DB::connection('core')->table('companies')
            ->where('code', config('bil.company_code'))->pluck('id')->all();

        foreach ($gates as $gate) {
            $this->assertContains($gate->direction, ['in', 'both']);
            $this->assertContains($gate->factory->company_id, $companyIds, $gate->name . ' is not a BIL gate');
        }
    }

    public function test_an_unknown_barcode_is_rejected(): void
    {
        Livewire::actingAs($this->admin());

        Livewire::test(FactoryEntrance::class)
            ->set('scan', 'NOT-A-REEL')
            ->call('addScan')
            ->assertSet('scanError', 'Barcode not found in production.')
            ->assertSet('items', []);
    }

    /** A reel already on a factory floor cannot be entered a second time. */
    public function test_a_reel_already_entered_is_rejected(): void
    {
        $barcode = DB::connection('bil')->table('factory_entrance_reel')
            ->where('is_deleted', 0)->orderByDesc('id')->value('barcode');
        $this->assertNotNull($barcode, 'no jumbo entrance rows to test against');

        Livewire::actingAs($this->admin());

        $component = Livewire::test(FactoryEntrance::class)
            ->set('scan', $barcode)
            ->call('addScan')
            ->assertSet('items', []);

        $this->assertStringStartsWith('Entry already made for', $component->get('scanError'));
    }

    /** A reel in BPL production for this company is accepted, with its details. */
    public function test_a_reel_awaiting_entrance_is_accepted(): void
    {
        $reel = DB::connection('bpl')->table('bpl_production as p')
            ->where('p.customer_id', (int) config('bil.jumbo_roll_customer_id'))
            ->whereNull('p.deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('bil.factory_entrance_reel as f')->whereColumn('f.barcode', 'p.barcode'))
            ->orderByDesc('p.id')
            ->first(['p.barcode', 'p.hardrollnumber', 'p.weight', 'p.product_id']);

        if (! $reel) {
            $this->markTestSkipped('every produced reel has already been entered');
        }

        Livewire::actingAs($this->admin());

        $items = Livewire::test(FactoryEntrance::class)
            ->set('scan', strtolower($reel->barcode))   // scanners are not case-fussy
            ->call('addScan')
            ->assertSet('scanError', '')
            ->get('items');

        $this->assertCount(1, $items);
        $this->assertSame(strtoupper($reel->barcode), $items[0]['barcode']);
        $this->assertSame($reel->hardrollnumber, $items[0]['hardrollnumber']);
        $this->assertSame((int) $reel->product_id, $items[0]['product_id']);
        $this->assertSame((float) $reel->weight, $items[0]['weight']);
    }

    /** Scanning the same reel twice into one submit is caught. */
    public function test_the_same_barcode_cannot_be_scanned_twice(): void
    {
        $barcode = DB::connection('bil')->table('factory_entrance_reel')
            ->where('is_deleted', 0)->orderByDesc('id')->value('barcode');

        Livewire::actingAs($this->admin());

        // Force a pending row without touching the database, then re-scan it.
        Livewire::test(FactoryEntrance::class)
            ->set('items', [['barcode' => $barcode, 'hardrollnumber' => 'X', 'productname' => 'X', 'product_id' => 1, 'weight' => 1.0]])
            ->set('scan', $barcode)
            ->call('addScan')
            ->assertSet('scanError', 'Barcode already scanned.');
    }

    /**
     * Receiving a reel closes its BPL exit (`bpl_factoryexit.received_at`).
     *
     * The Stock page does not show outstanding exits, but this is the record of
     * which ones are still open — so it has to stay true, or the count is stale
     * the moment someone reports on it.
     */
    public function test_receiving_a_reel_closes_its_bpl_exit(): void
    {
        $stillOpen = DB::connection('bil')->table('bpl_factoryexit as x')
            ->join('factory_entrance_reel as f', function ($j) {
                $j->on('f.barcode', '=', 'x.barcode')->where('f.is_deleted', 0);
            })
            ->whereNull('x.deleted_at')
            ->whereNull('x.received_at')
            ->count();

        $this->assertSame(0, $stillOpen, 'reels already received at a BIL gate still have an open BPL exit');
    }

    public function test_the_page_is_registered_and_shift_gated(): void
    {
        $page = collect(config('pages.pages'))->firstWhere('key', 'bil.jumbo_rolls.factory_entrance');
        $this->assertNotNull($page, 'page not declared in config/pages.php');
        $this->assertSame('factory', $page['gates']);
        $this->assertSame(['view', 'backdate', 'bypass-shift'], $page['abilities']);

        $this->assertSame('bil.jumbo_rolls.factory_entrance', (new FactoryEntrance())->shiftKey());
        $this->assertNotNull(
            collect(config('shifts.contexts'))->firstWhere('key', 'bil.jumbo_rolls.factory_entrance'),
            'shift context not declared in config/shifts.php'
        );
    }
}
