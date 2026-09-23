<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\JumboRolls\FactoryExit;
use Modules\Bpl\Livewire\JumboRolls\Production\Hardroll;
use Modules\Bpl\Models\BplFactoryExit;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Bpl\Models\BplProduction;
use Modules\Bpl\Models\BplSoftrollFactoryExit;
use Modules\Bpl\Models\BplSoftrollProduction;
use Modules\Bpl\Support\RollResolver;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Jumbo Rolls → Factory Exit.
 *
 * Runs against the LIVE bpl database. Nothing here exits a real roll: every
 * roll it books out is one it created itself, tracked by id and hard-deleted in
 * tearDown along with its exit row. The real floor is only ever READ.
 */
class BplFactoryExitPageTest extends TestCase
{
    private array $hardIds = [];
    private array $softIds = [];
    private array $barcodes = [];

    protected function tearDown(): void
    {
        if ($this->barcodes) {
            BplFactoryExit::withTrashed()->whereIn('barcode', $this->barcodes)->forceDelete();
            BplSoftrollFactoryExit::withTrashed()->whereIn('barcode', $this->barcodes)->forceDelete();
        }
        if ($this->hardIds) {
            BplProduction::withTrashed()->whereIn('id', $this->hardIds)->forceDelete();
        }
        if ($this->softIds) {
            BplSoftrollProduction::withTrashed()->whereIn('id', $this->softIds)->forceDelete();
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u, 'no admin user in core.user');

        return $u;
    }

    /** A hardroll of this test's own, minted through the Production screen. */
    private function makeHardroll(): BplProduction
    {
        $product = BplProductHardroll::first();

        Livewire::test(Hardroll::class)
            ->call('create')
            ->set('dateofmanufacture', now()->format('Y-m-d'))
            ->set('papermachine', 'PM3')
            ->set('customer_id', 1)
            ->set('form_gradetype', $product->gradetype)
            ->set('product_id', $product->id)
            ->set('corediameter', '76')->set('joints', '0')
            ->set('weight', '900')->set('cart', 'A')
            ->call('save')
            ->assertHasNoErrors();

        $row = BplProduction::orderByDesc('id')->first();
        $this->hardIds[] = $row->id;
        $this->barcodes[] = $row->barcode;

        return $row;
    }

    /**
     * A softroll carrying a barcode that is already a hardroll's — the
     * pre-cut-over collision, which cannot be minted through the UI any more
     * because softrolls now print `S`.
     */
    private function collidingSoftroll(string $barcode): BplSoftrollProduction
    {
        $template = BplSoftrollProduction::orderByDesc('id')->first();

        $row = BplSoftrollProduction::create([
            'username' => 'phpunit',
            'softrollnumber' => 'TEST-' . substr(md5($barcode), 0, 10),
            'grade_id' => $template->grade_id,
            'product_id' => $template->product_id,
            'barcode' => $barcode,
            'brightness' => 78,
            'weight' => 3400,
            'grammage' => $template->grammage,
            'diameter' => $template->diameter,
            'status' => null,
            'dateofmanufacture' => now()->format('Y/m/d'),
            'papermachine' => 3,
        ]);

        $this->softIds[] = $row->id;

        return $row;
    }

    public function test_the_page_opens_with_the_paper_machine_exits(): void
    {
        $res = $this->actingAs($this->admin())->get('/bpl/jumbo-rolls/factory-exit');

        $res->assertOk();
        $res->assertSee('Factory Exit');
        // Both paper machines, from the outbound gates added by migration
        // 2026_09_16_100000.
        $res->assertSee('PM2 Exit');
        $res->assertSee('PM3 Exit');
    }

    /**
     * The point of the single screen: a pre-cut-over softroll barcode resolves
     * to the SOFTROLL, even though its machine segment says `M` and a hardroll
     * of the very same barcode exists.
     *
     * Read-only — it asserts against whatever is really on the floor.
     */
    public function test_a_legacy_softroll_barcode_resolves_to_the_softroll(): void
    {
        $backlog = BplSoftrollProduction::query()
            ->whereNull('status')->whereNull('deleted_at')
            ->whereRaw("SUBSTRING_INDEX(`barcode`, '-', -2) NOT LIKE 'S%'")
            ->first();

        if (! $backlog) {
            $this->markTestSkipped('the pre-cut-over softroll backlog has drained — this fallback can go');
        }

        // Its hardroll twin exists and has already left, which is why there is
        // no ambiguity to resolve.
        $twin = BplProduction::where('barcode', $backlog->barcode)->first();
        $this->assertNotNull($twin, 'expected a colliding hardroll');
        $this->assertNotNull($twin->status, 'the twin is still on the floor — this barcode IS ambiguous');

        $candidates = RollResolver::onFloor($backlog->barcode);

        $this->assertCount(1, $candidates);
        $this->assertSame(RollResolver::SOFTROLL, $candidates[0]['stream']);
        $this->assertEqualsWithDelta((float) $backlog->weight, $candidates[0]['weight'], 0.01);
    }

    /** Nothing on the floor is contested today, and the screen says how many could be. */
    public function test_the_backlog_is_reported(): void
    {
        $this->assertSame(
            BplSoftrollProduction::whereNull('status')->whereNull('deleted_at')
                ->whereRaw("SUBSTRING_INDEX(`barcode`, '-', -2) NOT LIKE 'S%'")->count(),
            RollResolver::softrollBacklog()
        );
    }

    /** A scan that matches nothing says which kind of nothing it is. */
    public function test_a_miss_is_explained(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(FactoryExit::class)->set('scan', 'NOT-A-ROLL')->call('addScan');
        $this->assertSame('Barcode not found in production.', $c->get('scanError'));
        $this->assertSame([], $c->get('items'));

        $gone = BplFactoryExit::whereNull('deleted_at')->orderByDesc('id')->first();
        $c = Livewire::test(FactoryExit::class)->set('scan', $gone->barcode)->call('addScan');
        $this->assertStringStartsWith('Entry already made for', $c->get('scanError'));
        $this->assertSame([], $c->get('items'));
    }

    /** A roll is booked out to the right table, and its production row flipped. */
    public function test_a_hardroll_exits(): void
    {
        Livewire::actingAs($this->admin());
        $roll = $this->makeHardroll();

        $c = Livewire::test(FactoryExit::class);
        $gate = $c->instance()->gates()->firstWhere('legacy_name', 'PM3');
        $this->assertNotNull($gate, 'no PM3 exit gate');

        $c->set('gateId', $gate->id)->set('scan', $roll->barcode)->call('addScan');
        $this->assertCount(1, $c->get('items'));
        $this->assertSame(RollResolver::HARDROLL, $c->get('items')[0]['stream']);

        $c->call('save');
        $this->assertSame([], $c->get('items'));

        $roll->refresh();
        $this->assertSame('Exited', $roll->status);

        $exit = BplFactoryExit::where('barcode', $roll->barcode)->first();
        $this->assertNotNull($exit);
        $this->assertSame(auth()->user()->username, $exit->user);
        $this->assertSame(now()->format('Y/m/d'), $exit->date);
        // The legacy location id for PM3, which the flat PHP app reads.
        $this->assertSame(
            (int) DB::connection('bpl')->table('bpl_stock_locations')->where('location', 'PM3')->where('type', 0)->value('id'),
            $exit->location_id
        );
        // Not written to the softroll side.
        $this->assertFalse(BplSoftrollFactoryExit::where('barcode', $roll->barcode)->exists());
    }

    /** The same barcode cannot be queued twice in one submit. */
    public function test_a_repeat_scan_is_refused(): void
    {
        Livewire::actingAs($this->admin());
        $roll = $this->makeHardroll();

        $c = Livewire::test(FactoryExit::class)
            ->set('scan', $roll->barcode)->call('addScan')
            ->set('scan', $roll->barcode)->call('addScan');

        $this->assertSame('Barcode already scanned.', $c->get('scanError'));
        $this->assertCount(1, $c->get('items'));
    }

    /**
     * When a barcode really is ambiguous — a hardroll AND a softroll of that
     * code both still standing — the screen asks instead of guessing, and
     * exits only the one chosen.
     *
     * The pair is synthetic because it can no longer arise from the UI:
     * softrolls have printed `S` since 2026-09-09. It can still arise in the
     * data, though, because deleting a hardroll's exit puts it back on the
     * floor.
     */
    public function test_an_ambiguous_barcode_asks_the_operator(): void
    {
        Livewire::actingAs($this->admin());

        $hard = $this->makeHardroll();
        $soft = $this->collidingSoftroll($hard->barcode);

        $this->assertCount(2, RollResolver::onFloor($hard->barcode));

        $c = Livewire::test(FactoryExit::class)->set('scan', $hard->barcode)->call('addScan');

        // Held, not queued, and no error — this is a question, not a failure.
        $this->assertSame([], $c->get('items'));
        $this->assertSame('', $c->get('scanError'));
        $this->assertCount(2, $c->get('ambiguous'));
        $this->assertEqualsCanonicalizing(
            [RollResolver::HARDROLL, RollResolver::SOFTROLL],
            array_column($c->get('ambiguous'), 'stream')
        );

        $c->call('chooseStream', RollResolver::SOFTROLL);
        $this->assertSame([], $c->get('ambiguous'));
        $this->assertCount(1, $c->get('items'));
        $this->assertSame(RollResolver::SOFTROLL, $c->get('items')[0]['stream']);

        $c->call('save');

        // Only the softroll left; the hardroll of the same barcode is untouched.
        $this->assertSame('Exited', $soft->fresh()->status);
        $this->assertNull($hard->fresh()->status);
        $this->assertTrue(BplSoftrollFactoryExit::where('barcode', $hard->barcode)->exists());
        $this->assertFalse(BplFactoryExit::where('barcode', $hard->barcode)->exists());
    }

    /** Cancelling an ambiguous scan queues nothing. */
    public function test_an_ambiguous_scan_can_be_abandoned(): void
    {
        Livewire::actingAs($this->admin());

        $hard = $this->makeHardroll();
        $this->collidingSoftroll($hard->barcode);

        $c = Livewire::test(FactoryExit::class)
            ->set('scan', $hard->barcode)->call('addScan')
            ->call('cancelAmbiguous');

        $this->assertSame([], $c->get('ambiguous'));
        $this->assertSame([], $c->get('items'));
    }

    /**
     * Re-exiting a roll whose exit was deleted updates the row in place.
     * `barcode` is UNIQUE on both exit tables, so a second insert would fail —
     * the legacy Bpl\Movement::save() contract.
     */
    public function test_a_deleted_exit_is_reused_rather_than_duplicated(): void
    {
        Livewire::actingAs($this->admin());
        $roll = $this->makeHardroll();

        Livewire::test(FactoryExit::class)->set('scan', $roll->barcode)->call('addScan')->call('save');
        $first = BplFactoryExit::where('barcode', $roll->barcode)->firstOrFail();

        // What the legacy delete does: soft-delete the exit, put the reel back.
        $first->delete();
        BplProduction::whereKey($roll->id)->update(['status' => null]);

        Livewire::test(FactoryExit::class)->set('scan', $roll->barcode)->call('addScan')->call('save');

        $this->assertSame(1, BplFactoryExit::withTrashed()->where('barcode', $roll->barcode)->count());
        $again = BplFactoryExit::where('barcode', $roll->barcode)->firstOrFail();
        $this->assertSame($first->id, $again->id, 'a second row was inserted');
        $this->assertNull($again->deleted_at);
        $this->assertSame('Exited', $roll->fresh()->status);
    }
}
