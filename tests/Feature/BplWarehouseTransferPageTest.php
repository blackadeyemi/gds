<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\JumboRolls\FactoryExit;
use Modules\Bpl\Livewire\JumboRolls\Production\Hardroll;
use Modules\Bpl\Livewire\JumboRolls\WarehouseEntry;
use Modules\Bpl\Livewire\JumboRolls\WarehouseTransfer;
use Modules\Bpl\Models\BplFactoryExit;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Bpl\Models\BplProduction;
use Modules\Bpl\Models\BplWarehouseEntry;
use Modules\Bpl\Models\BplWarehouseTransfer;
use Modules\Bpl\Support\RollResolver;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Jumbo Rolls → Warehouse Transfer.
 *
 * LIVE database: every roll moved is minted by the test, and every stock row
 * touched is snapshotted and restored exactly — this screen moves stock twice
 * per roll.
 */
class BplWarehouseTransferPageTest extends TestCase
{
    private array $hardIds = [];
    private array $barcodes = [];
    private array $stockSnapshots = [];

    protected function tearDown(): void
    {
        foreach ($this->stockSnapshots as [$locationId, $productId, $before]) {
            $q = DB::connection('bpl')->table('bpl_hardroll_stock')
                ->where('location_id', $locationId)->where('product_id', $productId);
            $before === null ? $q->delete() : $q->update(['quantity' => $before->quantity, 'weight' => $before->weight]);
        }
        if ($this->barcodes) {
            BplWarehouseTransfer::withTrashed()->whereIn('barcode', $this->barcodes)->forceDelete();
            BplWarehouseEntry::withTrashed()->whereIn('barcode', $this->barcodes)->forceDelete();
            BplFactoryExit::withTrashed()->whereIn('barcode', $this->barcodes)->forceDelete();
        }
        if ($this->hardIds) {
            BplProduction::withTrashed()->whereIn('id', $this->hardIds)->forceDelete();
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    private function gate(string $component, string $warehouseCode)
    {
        $gate = Livewire::test($component)->instance()->gates()
            ->first(fn ($g) => $g->warehouse?->code === $warehouseCode);
        $this->assertNotNull($gate, "no gate on {$warehouseCode}");

        return $gate;
    }

    private function snapshot(int $locationId, int $productId): ?object
    {
        $before = DB::connection('bpl')->table('bpl_hardroll_stock')
            ->where('location_id', $locationId)->where('product_id', $productId)
            ->first(['quantity', 'weight']);
        $this->stockSnapshots[] = [$locationId, $productId, $before];

        return $before;
    }

    private function held(int $locationId, int $productId): object
    {
        return DB::connection('bpl')->table('bpl_hardroll_stock')
            ->where('location_id', $locationId)->where('product_id', $productId)
            ->first(['quantity', 'weight']) ?? (object) ['quantity' => 0, 'weight' => 0];
    }

    /** Mint a hardroll and walk it into a warehouse. Returns [roll, locationId]. */
    private function rollIn(string $warehouseCode, string $weight = '900'): array
    {
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
        $this->barcodes[] = $roll->barcode;

        Livewire::test(FactoryExit::class)->set('scan', $roll->barcode)->call('addScan')->call('save');

        $gate = $this->gate(WarehouseEntry::class, $warehouseCode);
        $locationId = (int) $gate->warehouse->legacy_location_id;
        $this->snapshot($locationId, $roll->product_id);

        Livewire::test(WarehouseEntry::class)->set('gateId', $gate->id)
            ->set('scan', $roll->barcode)->call('addScan')->call('save');

        $this->assertCount(1, RollResolver::inWarehouse($roll->barcode));

        return [$roll, $locationId];
    }

    public function test_the_page_opens_without_a_source_picker(): void
    {
        $res = $this->actingAs($this->admin())->get('/bpl/jumbo-rolls/warehouse-transfer');

        $res->assertOk();
        $res->assertSee('Transfer to');
        // The legacy "Current Location" select is gone: the scan says where
        // the roll is.
        $res->assertDontSee('Current Location');
    }

    /** The job: receipt moved, stock off the source and onto the destination, and a log row. */
    public function test_a_roll_is_moved_with_both_stock_legs_and_a_log(): void
    {
        Livewire::actingAs($this->admin());

        [$roll, $from] = $this->rollIn('BPL-PM3S', '900');
        $toGate = $this->gate(WarehouseTransfer::class, 'BPL-PM2S');
        $to = (int) $toGate->warehouse->legacy_location_id;
        $this->snapshot($to, $roll->product_id);

        $fromBefore = $this->held($from, $roll->product_id);
        $toBefore = $this->held($to, $roll->product_id);

        $c = Livewire::test(WarehouseTransfer::class)->set('gateId', $toGate->id)
            ->set('scan', $roll->barcode)->call('addScan');

        // The source was read off the roll, not chosen.
        $this->assertSame($from, (int) $c->get('items')[0]['location_id']);

        $c->call('save');

        $this->assertSame($to, (int) BplWarehouseEntry::where('barcode', $roll->barcode)->value('location_id'));

        $fromAfter = $this->held($from, $roll->product_id);
        $toAfter = $this->held($to, $roll->product_id);
        $this->assertEqualsWithDelta((float) $fromBefore->weight - 900, (float) $fromAfter->weight, 0.01);
        $this->assertEqualsWithDelta((float) $toBefore->weight + 900, (float) $toAfter->weight, 0.01);
        $this->assertSame((int) $toBefore->quantity + 1, (int) $toAfter->quantity);

        $log = BplWarehouseTransfer::where('barcode', $roll->barcode)->first();
        $this->assertNotNull($log, 'the move was not recorded');
        $this->assertSame($from, $log->from_location_id);
        $this->assertSame($to, $log->to_location_id);
        $this->assertSame(RollResolver::HARDROLL, $log->stream);
        $this->assertEqualsWithDelta(900, $log->weight, 0.01);
    }

    /** A roll already in the destination is refused, by name. */
    public function test_a_roll_already_at_the_destination_is_refused(): void
    {
        Livewire::actingAs($this->admin());

        [$roll] = $this->rollIn('BPL-PM3S');
        $same = $this->gate(WarehouseTransfer::class, 'BPL-PM3S');

        $c = Livewire::test(WarehouseTransfer::class)->set('gateId', $same->id)
            ->set('scan', $roll->barcode)->call('addScan');

        $this->assertSame('That roll is already in PM3 Store.', $c->get('scanError'));
        $this->assertSame([], $c->get('items'));
    }

    /** Switching the destination to where a queued roll already is drops it. */
    public function test_changing_the_destination_drops_rolls_already_there(): void
    {
        Livewire::actingAs($this->admin());

        [$roll] = $this->rollIn('BPL-PM3S');
        $pm2 = $this->gate(WarehouseTransfer::class, 'BPL-PM2S');
        $pm3 = $this->gate(WarehouseTransfer::class, 'BPL-PM3S');

        $c = Livewire::test(WarehouseTransfer::class)->set('gateId', $pm2->id)
            ->set('scan', $roll->barcode)->call('addScan');
        $this->assertCount(1, $c->get('items'));

        $c->set('gateId', $pm3->id);
        $this->assertSame([], $c->get('items'));
        $this->assertStringContainsString('already in', $c->get('scanError'));
    }

    /** A roll not in any warehouse is not transferable. */
    public function test_a_roll_not_in_a_warehouse_is_refused(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(WarehouseTransfer::class)->set('scan', 'NOT-A-ROLL')->call('addScan');
        $this->assertSame('Barcode not found in production.', $c->get('scanError'));
    }
}
