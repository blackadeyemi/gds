<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bil\Livewire\JumboRolls\Consumption;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BIL → Jumbo Rolls → Consumption: the route, the `page:` middleware, the
 * factory → line → machine cascade and the scan validation (including slices).
 * Read-only — nothing is consumed.
 */
class JumboRollConsumptionPageTest extends TestCase
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
            ->first(fn (User $u) => ! $u->canAccessPage('bil.jumbo_rolls.consumption'));
        $this->assertNotNull($u, 'no non-admin user without the page');

        return $u;
    }

    /**
     * A whole reel currently on a factory floor, with its product's slicing.
     *
     * @param  int|null  $slices  1 for an unsliced reel, >1 for a sliced one
     */
    protected function reelOnFloor(?int $slices = null)
    {
        $q = DB::connection('bil')->table('factory_entrance_reel as f')
            ->join('bpl.bpl_production as prod', 'prod.barcode', '=', 'f.barcode')
            ->join('bpl.bpl_products_hardroll as p', 'p.id', '=', 'prod.product_id')
            ->where('f.is_deleted', 0)
            ->whereNull('f.status')
            ->orderByDesc('f.id');

        if ($slices === 1) {
            $q->where('p.slice', '<=', 1);
        } elseif ($slices !== null) {
            $q->where('p.slice', '>', 1);
        }

        return $q->first(['f.barcode', 'prod.weight', 'p.slice', 'p.productname']);
    }

    /** A component already placed on Gambini → NAPKIN → OMET 4. */
    protected function placed()
    {
        Livewire::actingAs($this->admin());
        $c = Livewire::test(Consumption::class);

        $factory = $c->instance()->factories()->firstWhere('code', 'Gambini');
        $this->assertNotNull($factory, 'the Gambini factory is missing');
        $c->set('factoryId', $factory->id);

        $line = $c->instance()->lines()->firstWhere('name', 'NAPKIN');
        $this->assertNotNull($line, 'the NAPKIN line is missing');
        $c->set('lineId', $line->id);

        $machine = $c->instance()->machines()->firstWhere('name', 'OMET 4');
        $this->assertNotNull($machine, 'the OMET 4 machine is missing');
        $c->set('projectId', $machine->id);

        $this->assertTrue($c->instance()->placed());

        return $c;
    }

    public function test_admin_can_open_the_page(): void
    {
        $res = $this->actingAs($this->admin())->get('/bil/jumbo-rolls/consumption');

        $res->assertOk();
        $res->assertSee('Consumption');
        $res->assertSee('Product On Line');
        $res->assertSee('Machine');
    }

    public function test_a_user_without_the_page_is_refused(): void
    {
        $this->actingAs($this->outsider())
            ->get('/bil/jumbo-rolls/consumption')
            ->assertForbidden();
    }

    /** Only this company's factories, and the cascade narrows at each step. */
    public function test_the_factory_line_machine_cascade(): void
    {
        Livewire::actingAs($this->admin());
        $c = Livewire::test(Consumption::class);

        $companyIds = DB::connection('core')->table('companies')
            ->where('code', config('bil.company_code'))->pluck('id')->all();

        $factories = $c->instance()->factories();
        $this->assertNotEmpty($factories);
        foreach ($factories as $f) {
            $this->assertContains($f->company_id, $companyIds, $f->name . ' is not a BIL factory');
        }

        // Nothing downstream until a factory is chosen.
        $this->assertCount(0, $c->instance()->lines());
        $this->assertCount(0, $c->instance()->machines());
        $this->assertFalse($c->instance()->placed());

        $c = $this->placed();
        $this->assertContains('OMET 1', $c->instance()->machines()->pluck('name')->all());
    }

    /** Changing the factory clears the line, machine and anything scanned. */
    public function test_changing_the_factory_resets_the_cascade(): void
    {
        $c = $this->placed();
        $other = $c->instance()->factories()->firstWhere('code', 'Bil-1');

        $c->set('factoryId', $other->id)
            ->assertSet('lineId', null)
            ->assertSet('projectId', null)
            ->assertSet('items', []);
    }

    /** Product On Line is read from Conversion Setup, not typed here. */
    public function test_product_on_line_comes_from_conversion_setup(): void
    {
        $c = $this->placed();
        $lineId = $c->instance()->machine()->line_id;

        $setup = DB::connection('bil')->table('conversion_setup')->where('line_id', $lineId)->first();
        $expected = ($setup && $setup->productname !== 'None') ? $setup->productname : '';

        $this->assertSame($expected, $c->instance()->productOnLine());
    }

    public function test_nothing_can_be_scanned_before_a_machine_is_chosen(): void
    {
        Livewire::actingAs($this->admin());

        Livewire::test(Consumption::class)
            ->set('scan', 'ANYTHING')
            ->call('addScan')
            ->assertSet('scanError', 'Select a factory, line and machine first.')
            ->assertSet('items', []);
    }

    public function test_an_unknown_barcode_is_rejected(): void
    {
        $this->placed()
            ->set('scan', 'NOT-A-REEL')
            ->call('addScan')
            ->assertSet('scanError', 'Barcode not found in entrance.')
            ->assertSet('items', []);
    }

    /** A sliced reel must be scanned one slice at a time. */
    public function test_a_sliced_reel_needs_a_valid_slice_number(): void
    {
        $reel = $this->reelOnFloor(5);
        if (! $reel) {
            $this->markTestSkipped('no sliced reel is currently on a factory floor');
        }

        $c = $this->placed();

        $c->set('scan', $reel->barcode)->call('addScan')
            ->assertSet('scanError', 'Barcode does not exist, slice not included.')
            ->assertSet('items', []);

        $c->set('scan', $reel->barcode . '-' . ((int) $reel->slice + 1))->call('addScan')
            ->assertSet('scanError', 'Barcode does not exist, invalid included slice.')
            ->assertSet('items', []);

        $c->set('scan', $reel->barcode . '-0')->call('addScan')
            ->assertSet('scanError', 'Barcode does not exist, invalid included slice.')
            ->assertSet('items', []);

        // A valid slice weighs the reel's weight divided by the slice count.
        $items = $c->set('scan', $reel->barcode . '-1')->call('addScan')
            ->assertSet('scanError', '')
            ->get('items');

        $this->assertCount(1, $items);
        $this->assertSame(round($reel->weight / $reel->slice, 2), $items[0]['weight']);
        $this->assertSame($reel->productname, $items[0]['productname']);
    }

    /** An unsliced reel is scanned whole, at its full weight. */
    public function test_an_unsliced_reel_is_scanned_whole(): void
    {
        $reel = $this->reelOnFloor(1);
        if (! $reel) {
            $this->markTestSkipped('no unsliced reel is currently on a factory floor');
        }

        $items = $this->placed()
            ->set('scan', strtolower($reel->barcode))   // scanners are not case-fussy
            ->call('addScan')
            ->assertSet('scanError', '')
            ->get('items');

        $this->assertCount(1, $items);
        $this->assertSame($reel->barcode, $items[0]['barcode']);
        $this->assertSame((float) $reel->weight, $items[0]['weight']);
    }

    /** A reel already consumed cannot be consumed again. */
    public function test_an_already_consumed_barcode_is_rejected(): void
    {
        $barcode = DB::connection('bil')->table('factory_usage_reel')
            ->where('is_deleted', 0)->orderByDesc('id')->value('barcode');
        $this->assertNotNull($barcode, 'no jumbo usage rows to test against');

        $c = $this->placed()->set('scan', $barcode)->call('addScan')->assertSet('items', []);

        $this->assertStringStartsWith('Entry already made for', $c->get('scanError'));
    }

    /** A reel sent back to the paper machine is refused. */
    public function test_a_returned_reel_is_rejected(): void
    {
        $barcode = DB::connection('bil')->table('factory_entrance_reel')
            ->where('is_deleted', 0)->where('status', 'return')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('factory_usage_reel as u')
                ->whereColumn('u.barcode', 'factory_entrance_reel.barcode')->where('u.is_deleted', 0))
            ->orderByDesc('id')->value('barcode');

        if (! $barcode) {
            $this->markTestSkipped('no returned reel to test against');
        }

        $this->placed()
            ->set('scan', $barcode)
            ->call('addScan')
            ->assertSet('scanError', 'Jumbo roll has been returned.')
            ->assertSet('items', []);
    }

    public function test_the_page_is_registered_and_shift_gated(): void
    {
        $page = collect(config('pages.pages'))->firstWhere('key', 'bil.jumbo_rolls.consumption');
        $this->assertNotNull($page, 'page not declared in config/pages.php');
        $this->assertSame(['view', 'backdate', 'bypass-shift'], $page['abilities']);
        $this->assertArrayNotHasKey('gates', $page, 'consumption picks a machine, not a gate');

        $this->assertSame('bil.jumbo_rolls.consumption', (new Consumption())->shiftKey());
        $this->assertNotNull(
            collect(config('shifts.contexts'))->firstWhere('key', 'bil.jumbo_rolls.consumption'),
            'shift context not declared in config/shifts.php'
        );
    }
}
