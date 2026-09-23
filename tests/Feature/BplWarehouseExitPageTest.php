<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\JumboRolls\FactoryExit;
use Modules\Bpl\Livewire\JumboRolls\Production\Hardroll;
use Modules\Bpl\Livewire\JumboRolls\Production\Softroll;
use Modules\Bpl\Livewire\JumboRolls\WarehouseEntry;
use Modules\Bpl\Livewire\JumboRolls\WarehouseExit;
use Modules\Bpl\Models\BplFactoryExit;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Bpl\Models\BplProductSoftroll;
use Modules\Bpl\Models\BplProduction;
use Modules\Bpl\Models\BplSoftrollFactoryExit;
use Modules\Bpl\Models\BplSoftrollProduction;
use Modules\Bpl\Models\BplSoftrollWarehouseEntry;
use Modules\Bpl\Models\BplSoftrollWarehouseExit;
use Modules\Bpl\Models\BplWarehouseEntry;
use Modules\Bpl\Models\BplWarehouseExit;
use Modules\Bpl\Support\JumboRollStock;
use Modules\Bpl\Support\RollResolver;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Jumbo Rolls → Store Exit.
 *
 * Runs against the LIVE bpl database. Every roll it releases is one it minted,
 * exited, and received itself; the `bpl_hardroll_stock` / `bpl_softroll_stock` rows it
 * touches are snapshotted and restored exactly. This screen takes stock DOWN,
 * so a test that left a figure short would look precisely like the drift the
 * screen is built to avoid.
 */
class BplWarehouseExitPageTest extends TestCase
{
    private array $hardIds = [];
    private array $softIds = [];
    private array $barcodes = [];
    private array $stockSnapshots = [];

    protected function tearDown(): void
    {
        foreach ($this->stockSnapshots as [$table, $locationId, $keyColumn, $key, $before]) {
            $q = DB::connection('bpl')->table($table)
                ->where('location_id', $locationId)->where($keyColumn, $key);

            if ($before === null) {
                $q->delete();
            } else {
                $q->update(['quantity' => $before->quantity, 'weight' => $before->weight]);
            }
        }

        if ($this->barcodes) {
            BplWarehouseExit::withTrashed()->whereIn('barcode', $this->barcodes)->forceDelete();
            BplSoftrollWarehouseExit::withTrashed()->whereIn('barcode', $this->barcodes)->forceDelete();
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

    private function storeGate(string $warehouseCode, string $component)
    {
        $gate = Livewire::test($component)->instance()->gates()
            ->first(fn ($g) => $g->warehouse?->code === $warehouseCode);
        $this->assertNotNull($gate, "no gate on {$warehouseCode} for {$component}");

        return $gate;
    }

    /**
     * A roll all the way through the pipeline and into a store, so it can be
     * released. Returns [roll row, resolver candidate, locationId].
     */
    private function rollInStore(string $warehouseCode = 'BPL-PM3S', bool $soft = false, string $weight = '900'): array
    {
        if ($soft) {
            Livewire::test(Softroll::class)->call('create')
                ->set('dateofmanufacture', now()->format('Y-m-d'))->set('papermachine', '3')
                ->set('product_id', BplProductSoftroll::first()->id)
                ->set('brightness', '78')->set('weight', $weight)
                ->call('save')->assertHasNoErrors();
            $roll = BplSoftrollProduction::orderByDesc('id')->first();
            $this->softIds[] = $roll->id;
        } else {
            $product = BplProductHardroll::first();
            Livewire::test(Hardroll::class)->call('create')
                ->set('dateofmanufacture', now()->format('Y-m-d'))
                ->set('papermachine', 'PM3')->set('customer_id', 1)
                ->set('form_gradetype', $product->gradetype)->set('product_id', $product->id)
                ->set('corediameter', '76')->set('joints', '0')
                ->set('weight', $weight)->set('cart', 'A')
                ->call('save')->assertHasNoErrors();
            $roll = BplProduction::orderByDesc('id')->first();
            $this->hardIds[] = $roll->id;
        }

        $this->barcodes[] = $roll->barcode;

        Livewire::test(FactoryExit::class)->set('scan', $roll->barcode)->call('addScan')->call('save');

        $inGate = $this->storeGate($warehouseCode, WarehouseEntry::class);
        $locationId = (int) $inGate->warehouse->legacy_location_id;

        $candidate = RollResolver::awaitingWarehouse($roll->barcode)[0];
        $this->snapshotStock($candidate, $locationId);

        Livewire::test(WarehouseEntry::class)->set('gateId', $inGate->id)
            ->set('scan', $roll->barcode)->call('addScan')->call('save');

        $held = RollResolver::inWarehouse($roll->barcode);
        $this->assertCount(1, $held, 'the roll did not land in a store');

        return [$roll, $held[0], $locationId];
    }

    public function test_the_page_opens_with_the_bpl_store_exits(): void
    {
        $res = $this->actingAs($this->admin())->get('/bpl/jumbo-rolls/warehouse-exit');

        $res->assertOk();
        $res->assertSee('Warehouse Exit');
        $res->assertSee('PM2 Store');
        $res->assertSee('PM3 Store');
        $res->assertSee('Waste Paper Store');
    }

    /** A roll that never reached a store cannot be released. */
    public function test_a_roll_not_in_a_store_is_refused(): void
    {
        Livewire::actingAs($this->admin());

        $product = BplProductHardroll::first();
        Livewire::test(Hardroll::class)->call('create')
            ->set('dateofmanufacture', now()->format('Y-m-d'))
            ->set('papermachine', 'PM3')->set('customer_id', 1)
            ->set('form_gradetype', $product->gradetype)->set('product_id', $product->id)
            ->set('corediameter', '76')->set('joints', '0')
            ->set('weight', '900')->set('cart', 'A')
            ->call('save')->assertHasNoErrors();
        $roll = BplProduction::orderByDesc('id')->first();
        $this->hardIds[] = $roll->id;
        $this->barcodes[] = $roll->barcode;

        $gate = $this->storeGate('BPL-PM3S', WarehouseExit::class);
        $c = Livewire::test(WarehouseExit::class)->set('gateId', $gate->id)
            ->set('scan', $roll->barcode)->call('addScan');

        $this->assertSame('That roll is not in a store.', $c->get('scanError'));
        $this->assertSame([], $c->get('items'));
    }

    public function test_an_unknown_barcode_is_refused(): void
    {
        Livewire::actingAs($this->admin());

        $gate = $this->storeGate('BPL-PM3S', WarehouseExit::class);
        $c = Livewire::test(WarehouseExit::class)->set('gateId', $gate->id)
            ->set('scan', 'NOT-A-ROLL')->call('addScan');

        $this->assertSame('Barcode not found in production.', $c->get('scanError'));
    }

    /** The job: the release row, the stock decrement, and the receipt status. */
    public function test_a_hardroll_is_released_and_stock_comes_off(): void
    {
        Livewire::actingAs($this->admin());

        [$roll, $held, $locationId] = $this->rollInStore('BPL-PM3S', false, '900');

        $before = DB::connection('bpl')->table('bpl_hardroll_stock')
            ->where('location_id', $locationId)->where('product_id', $held['product_id'])
            ->first(['quantity', 'weight']);

        $gate = $this->storeGate('BPL-PM3S', WarehouseExit::class);
        Livewire::test(WarehouseExit::class)->set('gateId', $gate->id)
            ->set('scan', $roll->barcode)->call('addScan')
            ->call('save');

        $release = BplWarehouseExit::where('barcode', $roll->barcode)->first();
        $this->assertNotNull($release, 'no release row');
        $this->assertSame(auth()->user()->username, $release->user);
        $this->assertSame($locationId, $release->location_id);
        $this->assertSame(now()->format('Y/m/d'), $release->date);
        $this->assertFalse(BplSoftrollWarehouseExit::where('barcode', $roll->barcode)->exists());

        // The receipt is stamped, so the roll stops counting as held.
        $this->assertSame('Exited', BplWarehouseEntry::where('barcode', $roll->barcode)->value('status'));
        $this->assertSame([], RollResolver::inWarehouse($roll->barcode));

        $after = DB::connection('bpl')->table('bpl_hardroll_stock')
            ->where('location_id', $locationId)->where('product_id', $held['product_id'])
            ->first(['quantity', 'weight']);

        $this->assertSame((int) $before->quantity - 1, (int) $after->quantity);
        $this->assertEqualsWithDelta((float) $before->weight - 900, (float) $after->weight, 0.01);
    }

    /** Softroll stock comes off the GRADE-keyed table. */
    public function test_a_softroll_is_released_from_grade_keyed_stock(): void
    {
        Livewire::actingAs($this->admin());

        [$roll, $held, $locationId] = $this->rollInStore('BPL-PM2S', true, '3400');

        $before = DB::connection('bpl')->table('bpl_softroll_stock')
            ->where('location_id', $locationId)->where('grade_id', $held['grade_id'])
            ->first(['quantity', 'weight']);

        $gate = $this->storeGate('BPL-PM2S', WarehouseExit::class);
        Livewire::test(WarehouseExit::class)->set('gateId', $gate->id)
            ->set('scan', $roll->barcode)->call('addScan')
            ->call('save');

        $this->assertTrue(BplSoftrollWarehouseExit::where('barcode', $roll->barcode)->exists());
        $this->assertFalse(BplWarehouseExit::where('barcode', $roll->barcode)->exists());
        $this->assertSame('Exited', BplSoftrollWarehouseEntry::where('barcode', $roll->barcode)->value('status'));

        $after = DB::connection('bpl')->table('bpl_softroll_stock')
            ->where('location_id', $locationId)->where('grade_id', $held['grade_id'])
            ->first(['quantity', 'weight']);

        $this->assertSame((int) $before->quantity - 1, (int) $after->quantity);
        $this->assertEqualsWithDelta((float) $before->weight - 3400, (float) $after->weight, 0.01);
    }

    /**
     * A roll can only be released from the store it is in, and the refusal
     * names where it actually is — the legacy only said "not in your store".
     */
    public function test_a_roll_in_another_store_is_named_not_just_refused(): void
    {
        Livewire::actingAs($this->admin());

        [$roll] = $this->rollInStore('BPL-PM3S', false, '900');

        $wrongGate = $this->storeGate('BPL-PM2S', WarehouseExit::class);
        $c = Livewire::test(WarehouseExit::class)->set('gateId', $wrongGate->id)
            ->set('scan', $roll->barcode)->call('addScan');

        $this->assertSame('That roll is in PM3 Store, not PM2 Store.', $c->get('scanError'));
        $this->assertSame([], $c->get('items'));
    }

    /**
     * Changing the store drops anything scanned from the previous one.
     *
     * Without this an operator who corrects a mis-picked store would take the
     * stock off a store the rolls were never in, and nothing afterwards would
     * say so.
     */
    public function test_changing_the_store_clears_rolls_from_the_old_one(): void
    {
        Livewire::actingAs($this->admin());

        [$roll] = $this->rollInStore('BPL-PM3S', false, '900');

        $right = $this->storeGate('BPL-PM3S', WarehouseExit::class);
        $other = $this->storeGate('BPL-PM2S', WarehouseExit::class);

        $c = Livewire::test(WarehouseExit::class)->set('gateId', $right->id)
            ->set('scan', $roll->barcode)->call('addScan');
        $this->assertCount(1, $c->get('items'));

        $c->set('gateId', $other->id);

        $this->assertSame([], $c->get('items'), 'the queue survived a store change');
        $this->assertStringContainsString('removed', $c->get('scanError'));
    }

    /** A roll already released cannot be released again. */
    public function test_a_roll_already_out_is_refused(): void
    {
        Livewire::actingAs($this->admin());

        [$roll] = $this->rollInStore('BPL-PM3S', false, '900');
        $gate = $this->storeGate('BPL-PM3S', WarehouseExit::class);

        Livewire::test(WarehouseExit::class)->set('gateId', $gate->id)
            ->set('scan', $roll->barcode)->call('addScan')->call('save');

        $c = Livewire::test(WarehouseExit::class)->set('gateId', $gate->id)
            ->set('scan', $roll->barcode)->call('addScan');

        $this->assertStringStartsWith('Entry already made for', $c->get('scanError'));
    }

    /**
     * A stock row already at zero must not block a release.
     *
     * `bpl_stock.quantity` is INT UNSIGNED and the server runs
     * STRICT_TRANS_TABLES, so an unguarded decrement raises "Out of range
     * value" and rolls the whole batch back. The count floors at zero; the
     * weight is allowed to go negative so the discrepancy stays visible.
     */
    public function test_a_zero_stock_row_does_not_block_the_release(): void
    {
        Livewire::actingAs($this->admin());

        [$roll, $held, $locationId] = $this->rollInStore('BPL-PM3S', false, '900');

        // Force the discrepancy: drain the row the release will decrement.
        DB::connection('bpl')->table('bpl_hardroll_stock')
            ->where('location_id', $locationId)->where('product_id', $held['product_id'])
            ->update(['quantity' => 0, 'weight' => 0]);

        $gate = $this->storeGate('BPL-PM3S', WarehouseExit::class);
        Livewire::test(WarehouseExit::class)->set('gateId', $gate->id)
            ->set('scan', $roll->barcode)->call('addScan')
            ->call('save');

        // The release went through rather than erroring…
        $this->assertTrue(BplWarehouseExit::where('barcode', $roll->barcode)->exists());

        $after = DB::connection('bpl')->table('bpl_hardroll_stock')
            ->where('location_id', $locationId)->where('product_id', $held['product_id'])
            ->first(['quantity', 'weight']);

        // …the count held at zero rather than wrapping…
        $this->assertSame(0, (int) $after->quantity);
        // …and the weight went negative, which is the visible signal.
        $this->assertEqualsWithDelta(-900, (float) $after->weight, 0.01);
    }

    /** apply() floors the count on a row that does not exist at all, too. */
    public function test_a_decrement_on_a_missing_stock_row_creates_it_without_erroring(): void
    {
        $roll = [
            'stream' => RollResolver::HARDROLL,
            'barcode' => 'ZZ-TEST-STOCK',
            'product_id' => 999999,
            'grade_id' => null,
        ];

        $this->stockSnapshots[] = ['bpl_hardroll_stock', 4, 'product_id', 999999, null];

        JumboRollStock::apply(4, $roll, -500.0);

        $row = DB::connection('bpl')->table('bpl_hardroll_stock')
            ->where('location_id', 4)->where('product_id', 999999)->first(['quantity', 'weight']);

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->quantity);
        $this->assertEqualsWithDelta(-500, (float) $row->weight, 0.01);
    }
}
