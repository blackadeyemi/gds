<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\JumboRolls\FactoryExit;
use Modules\Bpl\Livewire\JumboRolls\Production\Hardroll;
use Modules\Bpl\Livewire\JumboRolls\Production\Softroll;
use Modules\Bpl\Livewire\JumboRolls\WarehouseEntry;
use Modules\Bpl\Models\BplFactoryExit;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Bpl\Models\BplProductSoftroll;
use Modules\Bpl\Models\BplProduction;
use Modules\Bpl\Models\BplSoftrollFactoryExit;
use Modules\Bpl\Models\BplSoftrollProduction;
use Modules\Bpl\Models\BplSoftrollWarehouseEntry;
use Modules\Bpl\Models\BplWarehouseEntry;
use Modules\Bpl\Support\RollResolver;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Jumbo Rolls → Store Entrance.
 *
 * Runs against the LIVE bpl database. Every roll it receives is one it minted
 * itself, and — because this screen MOVES STOCK — the `bpl_hardroll_stock` /
 * `bpl_softroll_stock` rows it touches are snapshotted before and restored after.
 * A test that left stock a few hundred kilos heavy would be indistinguishable
 * from the drift this screen is built to avoid.
 */
class BplWarehouseEntryPageTest extends TestCase
{
    private array $hardIds = [];
    private array $softIds = [];
    private array $barcodes = [];

    /** [table, location_id, keyColumn, key] => the row before the test, or null. */
    private array $stockSnapshots = [];

    protected function tearDown(): void
    {
        foreach ($this->stockSnapshots as $snap) {
            [$table, $locationId, $keyColumn, $key, $before] = $snap;

            $q = DB::connection('bpl')->table($table)
                ->where('location_id', $locationId)->where($keyColumn, $key);

            if ($before === null) {
                $q->delete();
            } else {
                $q->update(['quantity' => $before->quantity, 'weight' => $before->weight]);
            }
        }

        if ($this->barcodes) {
            BplWarehouseEntry::withTrashed()->whereIn('barcode', $this->barcodes)->forceDelete();
            BplSoftrollWarehouseEntry::withTrashed()->whereIn('barcode', $this->barcodes)->forceDelete();
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

    /** Remember a stock row's figures so tearDown can put them back exactly. */
    private function snapshotStock(array $roll, int $locationId): ?object
    {
        $isSoft = $roll['stream'] === RollResolver::SOFTROLL;
        $table = $isSoft ? 'bpl_softroll_stock' : 'bpl_hardroll_stock';
        $keyColumn = $isSoft ? 'grade_id' : 'product_id';
        $key = $isSoft ? $roll['grade_id'] : $roll['product_id'];

        $before = DB::connection('bpl')->table($table)
            ->where('location_id', $locationId)->where($keyColumn, $key)
            ->first(['quantity', 'weight']);

        $this->stockSnapshots[] = [$table, $locationId, $keyColumn, $key, $before];

        return $before;
    }

    private function makeHardroll(string $weight = '900'): BplProduction
    {
        $product = BplProductHardroll::first();

        Livewire::test(Hardroll::class)->call('create')
            ->set('dateofmanufacture', now()->format('Y-m-d'))
            ->set('papermachine', 'PM3')->set('customer_id', 1)
            ->set('form_gradetype', $product->gradetype)->set('product_id', $product->id)
            ->set('corediameter', '76')->set('joints', '0')
            ->set('weight', $weight)->set('cart', 'A')
            ->call('save')->assertHasNoErrors();

        $row = BplProduction::orderByDesc('id')->first();
        $this->hardIds[] = $row->id;
        $this->barcodes[] = $row->barcode;

        return $row;
    }

    private function makeSoftroll(string $weight = '3400'): BplSoftrollProduction
    {
        Livewire::test(Softroll::class)->call('create')
            ->set('dateofmanufacture', now()->format('Y-m-d'))
            ->set('papermachine', '3')
            ->set('product_id', BplProductSoftroll::first()->id)
            ->set('brightness', '78')->set('weight', $weight)
            ->call('save')->assertHasNoErrors();

        $row = BplSoftrollProduction::orderByDesc('id')->first();
        $this->softIds[] = $row->id;
        $this->barcodes[] = $row->barcode;

        return $row;
    }

    /** Book a roll out of the factory, so Store Entrance will take it. */
    private function exit(string $barcode): void
    {
        Livewire::test(FactoryExit::class)->set('scan', $barcode)->call('addScan')->call('save');
    }

    private function storeGate(string $warehouseCode)
    {
        $gate = Livewire::test(WarehouseEntry::class)->instance()->gates()
            ->first(fn ($g) => $g->warehouse?->code === $warehouseCode);
        $this->assertNotNull($gate, "no inbound gate on {$warehouseCode}");

        return $gate;
    }

    public function test_the_page_opens_with_the_bpl_stores(): void
    {
        $res = $this->actingAs($this->admin())->get('/bpl/jumbo-rolls/warehouse-entry');

        $res->assertOk();
        $res->assertSee('Warehouse Entry');
        $res->assertSee('PM2 Store');
        $res->assertSee('PM3 Store');
        $res->assertSee('Waste Paper Store');
    }

    /** A roll still on the factory floor cannot be received — the legacy rule. */
    public function test_a_roll_that_has_not_left_the_factory_is_refused(): void
    {
        Livewire::actingAs($this->admin());
        $roll = $this->makeHardroll();

        $c = Livewire::test(WarehouseEntry::class)->set('scan', $roll->barcode)->call('addScan');

        $this->assertSame('That roll has not been booked out of the factory yet.', $c->get('scanError'));
        $this->assertSame([], $c->get('items'));
    }

    public function test_an_unknown_barcode_is_refused(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(WarehouseEntry::class)->set('scan', 'NOT-A-ROLL')->call('addScan');

        $this->assertSame('Barcode not found in production.', $c->get('scanError'));
    }

    /**
     * The whole job: a hardroll is received, its row written to the hardroll
     * table, and the store's stock goes up by exactly one roll and its weight.
     */
    public function test_a_hardroll_is_received_and_moves_stock(): void
    {
        Livewire::actingAs($this->admin());

        $roll = $this->makeHardroll('900');
        $this->exit($roll->barcode);

        $candidates = RollResolver::awaitingWarehouse($roll->barcode);
        $this->assertCount(1, $candidates);
        $this->assertSame(RollResolver::HARDROLL, $candidates[0]['stream']);

        $gate = $this->storeGate('BPL-PM3S');
        $locationId = (int) $gate->warehouse->legacy_location_id;
        $before = $this->snapshotStock($candidates[0], $locationId);

        Livewire::test(WarehouseEntry::class)
            ->set('gateId', $gate->id)
            ->set('scan', $roll->barcode)->call('addScan')
            ->call('save');

        $receipt = BplWarehouseEntry::where('barcode', $roll->barcode)->first();
        $this->assertNotNull($receipt, 'no receipt row');
        $this->assertSame(auth()->user()->username, $receipt->user);
        $this->assertSame($locationId, $receipt->location_id);
        $this->assertSame(now()->format('Y/m/d'), $receipt->date);
        $this->assertNull($receipt->status, 'a fresh receipt means the roll is IN the store');
        // Not written to the softroll side.
        $this->assertFalse(BplSoftrollWarehouseEntry::where('barcode', $roll->barcode)->exists());

        $after = DB::connection('bpl')->table('bpl_hardroll_stock')
            ->where('location_id', $locationId)->where('product_id', $candidates[0]['product_id'])
            ->first(['quantity', 'weight']);

        $this->assertNotNull($after);
        $this->assertSame((int) ($before->quantity ?? 0) + 1, (int) $after->quantity);
        $this->assertEqualsWithDelta((float) ($before->weight ?? 0) + 900, (float) $after->weight, 0.01);
    }

    /** Softroll stock is keyed by GRADE, not product — a legacy shape worth pinning. */
    public function test_a_softroll_is_received_into_grade_keyed_stock(): void
    {
        Livewire::actingAs($this->admin());

        $roll = $this->makeSoftroll('3400');
        $this->exit($roll->barcode);

        $candidates = RollResolver::awaitingWarehouse($roll->barcode);
        $this->assertCount(1, $candidates);
        $this->assertSame(RollResolver::SOFTROLL, $candidates[0]['stream']);
        $this->assertSame($roll->grade_id, $candidates[0]['grade_id']);
        $this->assertNull($candidates[0]['product_id']);

        $gate = $this->storeGate('BPL-PM2S');
        $locationId = (int) $gate->warehouse->legacy_location_id;
        $before = $this->snapshotStock($candidates[0], $locationId);

        Livewire::test(WarehouseEntry::class)
            ->set('gateId', $gate->id)
            ->set('scan', $roll->barcode)->call('addScan')
            ->call('save');

        $this->assertTrue(BplSoftrollWarehouseEntry::where('barcode', $roll->barcode)->exists());
        $this->assertFalse(BplWarehouseEntry::where('barcode', $roll->barcode)->exists());

        $after = DB::connection('bpl')->table('bpl_softroll_stock')
            ->where('location_id', $locationId)->where('grade_id', $roll->grade_id)
            ->first(['quantity', 'weight']);

        $this->assertNotNull($after);
        $this->assertSame((int) ($before->quantity ?? 0) + 1, (int) $after->quantity);
        $this->assertEqualsWithDelta((float) ($before->weight ?? 0) + 3400, (float) $after->weight, 0.01);
    }

    /** A roll already in a store cannot be received again. */
    public function test_a_roll_already_in_a_store_is_refused(): void
    {
        Livewire::actingAs($this->admin());

        $roll = $this->makeHardroll();
        $this->exit($roll->barcode);

        $gate = $this->storeGate('BPL-PM3S');
        $this->snapshotStock(RollResolver::awaitingWarehouse($roll->barcode)[0], (int) $gate->warehouse->legacy_location_id);

        Livewire::test(WarehouseEntry::class)->set('gateId', $gate->id)
            ->set('scan', $roll->barcode)->call('addScan')->call('save');

        $c = Livewire::test(WarehouseEntry::class)->set('scan', $roll->barcode)->call('addScan');

        $this->assertStringStartsWith('Entry already made for', $c->get('scanError'));
        $this->assertSame([], $c->get('items'));
    }

    /**
     * A barcode awaiting a store in both streams is put to the operator, and
     * only the chosen one is received.
     *
     * Synthetic, because the UI cannot mint a colliding pair any more — but the
     * data can still produce one: 1,637 barcodes exist in both factory-exit
     * tables.
     */
    public function test_an_ambiguous_barcode_asks_the_operator(): void
    {
        Livewire::actingAs($this->admin());

        $hard = $this->makeHardroll('900');
        $this->exit($hard->barcode);

        // A softroll of the same barcode, already out of the factory.
        $template = BplSoftrollProduction::orderByDesc('id')->first();
        $soft = BplSoftrollProduction::create([
            'username' => 'phpunit',
            'softrollnumber' => 'TEST-' . substr(md5($hard->barcode), 0, 10),
            'grade_id' => $template->grade_id, 'product_id' => $template->product_id,
            'barcode' => $hard->barcode, 'brightness' => 78, 'weight' => 3400,
            'grammage' => $template->grammage, 'diameter' => $template->diameter,
            'status' => 'Exited', 'dateofmanufacture' => now()->format('Y/m/d'), 'papermachine' => 3,
        ]);
        $this->softIds[] = $soft->id;
        BplSoftrollFactoryExit::create([
            'user' => 'phpunit', 'barcode' => $hard->barcode, 'location_id' => 2,
            'date' => now()->format('Y/m/d'), 'created_at' => now(),
        ]);

        $this->assertCount(2, RollResolver::awaitingWarehouse($hard->barcode));

        $gate = $this->storeGate('BPL-PM3S');
        $locationId = (int) $gate->warehouse->legacy_location_id;

        $c = Livewire::test(WarehouseEntry::class)->set('gateId', $gate->id)
            ->set('scan', $hard->barcode)->call('addScan');

        $this->assertSame([], $c->get('items'));
        $this->assertSame('', $c->get('scanError'));
        $this->assertCount(2, $c->get('ambiguous'));

        $chosen = collect($c->get('ambiguous'))->firstWhere('stream', RollResolver::SOFTROLL);
        $before = $this->snapshotStock($chosen, $locationId);

        $c->call('chooseStream', RollResolver::SOFTROLL);
        $this->assertCount(1, $c->get('items'));
        $c->call('save');

        // Only the softroll was received; the hardroll is still waiting.
        $this->assertTrue(BplSoftrollWarehouseEntry::where('barcode', $hard->barcode)->exists());
        $this->assertFalse(BplWarehouseEntry::where('barcode', $hard->barcode)->exists());

        $after = DB::connection('bpl')->table('bpl_softroll_stock')
            ->where('location_id', $locationId)->where('grade_id', $chosen['grade_id'])
            ->first(['quantity', 'weight']);
        $this->assertEqualsWithDelta((float) ($before->weight ?? 0) + 3400, (float) $after->weight, 0.01);
    }

    /**
     * Re-receiving a roll whose receipt was deleted updates the row in place.
     * `barcode` is UNIQUE, so a second insert would fail.
     */
    public function test_a_deleted_receipt_is_reused_rather_than_duplicated(): void
    {
        Livewire::actingAs($this->admin());

        $roll = $this->makeHardroll('900');
        $this->exit($roll->barcode);

        $gate = $this->storeGate('BPL-PM3S');
        $locationId = (int) $gate->warehouse->legacy_location_id;
        $candidate = RollResolver::awaitingWarehouse($roll->barcode)[0];
        $before = $this->snapshotStock($candidate, $locationId);

        $receive = fn () => Livewire::test(WarehouseEntry::class)->set('gateId', $gate->id)
            ->set('scan', $roll->barcode)->call('addScan')->call('save');

        $receive();
        $first = BplWarehouseEntry::where('barcode', $roll->barcode)->firstOrFail();

        // What the legacy delete does: soft-delete the receipt and take the
        // weight back off the store.
        $first->delete();
        DB::connection('bpl')->table('bpl_hardroll_stock')
            ->where('location_id', $locationId)->where('product_id', $candidate['product_id'])
            ->update(['quantity' => DB::raw('`quantity` - 1'), 'weight' => DB::raw('`weight` - 900')]);

        $receive();

        $this->assertSame(1, BplWarehouseEntry::withTrashed()->where('barcode', $roll->barcode)->count());
        $again = BplWarehouseEntry::where('barcode', $roll->barcode)->firstOrFail();
        $this->assertSame($first->id, $again->id, 'a second receipt row was inserted');
        $this->assertNull($again->deleted_at);

        // And the stock is up by exactly one roll, not two.
        $after = DB::connection('bpl')->table('bpl_hardroll_stock')
            ->where('location_id', $locationId)->where('product_id', $candidate['product_id'])
            ->first(['quantity', 'weight']);
        $this->assertSame((int) ($before->quantity ?? 0) + 1, (int) $after->quantity);
        $this->assertEqualsWithDelta((float) ($before->weight ?? 0) + 900, (float) $after->weight, 0.01);
    }
}
