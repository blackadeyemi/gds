<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\JumboRolls\WaitingArea;
use Modules\Bpl\Models\BplWaitingAreaEntry;
use Modules\Bpl\Models\BplWaitingAreaExit;
use Modules\Bpl\Models\BplWarehouseEntry;
use Modules\Bpl\Support\RollResolver;
use Modules\Core\Models\User;
use Modules\Core\Models\WarehouseGate;
use Tests\TestCase;

/**
 * BPL → Jumbo Rolls → Waiting Area (the legacy "BPL Quarantine" screens).
 *
 * LIVE bpl database: every waiting-area row this writes is hard-deleted in
 * tearDown, the stock rows it moves are restored to the figures they had, and
 * the warehouse-entry status it stamps is put back.
 */
class BplWaitingAreaPageTest extends TestCase
{
    private array $barcodes = [];

    /** [location_id, product_id] => quantity+weight before the test touched it. */
    private array $stockBefore = [];

    /** barcode => the receipt status it had. */
    private array $receiptStatus = [];

    protected function tearDown(): void
    {
        $bpl = DB::connection('bpl');

        if ($this->barcodes) {
            $bpl->table('bpl_waiting_area_entry')->whereIn('barcode', $this->barcodes)->delete();
            $bpl->table('bpl_waiting_area_exit')->whereIn('barcode', $this->barcodes)->delete();
        }

        foreach ($this->receiptStatus as $barcode => $status) {
            $bpl->table('bpl_warehouse_entry')->where('barcode', $barcode)->update(['status' => $status]);
        }

        foreach ($this->stockBefore as $key => $row) {
            [$locationId, $productId] = explode('|', $key);

            $bpl->table('bpl_hardroll_stock')
                ->where('location_id', $locationId)->where('product_id', $productId)
                ->update(['quantity' => $row['quantity'], 'weight' => $row['weight']]);
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    /** A hardroll that has left a store — what the waiting area may hold. */
    private function releasedRoll(): array
    {
        $row = DB::connection('bpl')->table('bpl_warehouse_exit as sx')
            ->join('bpl_production as r', function ($j) {
                $j->on('r.barcode', '=', 'sx.barcode')->whereNull('r.deleted_at');
            })
            ->whereNull('sx.deleted_at')
            ->whereNotNull('r.product_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_waiting_area_entry as w')
                ->whereColumn('w.barcode', 'sx.barcode')->whereNull('w.deleted_at'))
            ->orderByDesc('sx.id')
            ->first(['sx.barcode', 'sx.location_id', 'r.product_id', 'r.weight']);

        $this->assertNotNull($row, 'no released roll to hold');

        $this->barcodes[] = $row->barcode;
        $this->receiptStatus[$row->barcode] = DB::connection('bpl')->table('bpl_warehouse_entry')
            ->where('barcode', $row->barcode)->value('status');

        return [(string) $row->barcode, (int) $row->location_id, (int) $row->product_id, (float) $row->weight];
    }

    /** The gate this user may use on a store, on the side asked for. */
    private function gateFor(int $locationId, string $direction): int
    {
        $gate = WarehouseGate::query()
            ->whereHas('warehouse', fn ($q) => $q->where('legacy_location_id', $locationId))
            ->where('direction', $direction)
            ->first();

        $this->assertNotNull($gate, 'no ' . $direction . ' gate on location ' . $locationId);

        return (int) $gate->id;
    }

    private function stock(int $locationId, int $productId): array
    {
        $row = DB::connection('bpl')->table('bpl_hardroll_stock')
            ->where('location_id', $locationId)->where('product_id', $productId)
            ->first(['quantity', 'weight']);

        if ($row && ! isset($this->stockBefore[$locationId . '|' . $productId])) {
            $this->stockBefore[$locationId . '|' . $productId] = ['quantity' => $row->quantity, 'weight' => $row->weight];
        }

        return $row ? ['quantity' => (int) $row->quantity, 'weight' => (float) $row->weight] : ['quantity' => 0, 'weight' => 0.0];
    }

    public function test_the_page_opens(): void
    {
        $this->actingAs($this->admin())->get('/bpl/jumbo-rolls/waiting-area')
            ->assertOk()
            ->assertSee('Waiting Area')
            ->assertSee('Hold rolls')
            ->assertSee('Release rolls');
    }

    /** Only a roll that has left a store may be held — the legacy's own rule. */
    public function test_only_a_roll_that_left_a_store_can_be_held(): void
    {
        Livewire::actingAs($this->admin());
        [$barcode, $locationId] = $this->releasedRoll();

        $c = Livewire::test(WaitingArea::class)->set('gateId', $this->gateFor($locationId, 'in'))
            ->set('scan', $barcode)->call('addScan');
        $this->assertCount(1, $c->get('items'), $c->get('scanError'));

        // A roll still sitting in a store is refused, and says why.
        $inStore = DB::connection('bpl')->table('bpl_warehouse_entry as se')
            ->whereNull('se.deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_warehouse_exit as sx')
                ->whereColumn('sx.barcode', 'se.barcode')->whereNull('sx.deleted_at'))
            ->value('se.barcode');
        $this->assertNotNull($inStore);

        $c->set('scan', $inStore)->call('addScan');
        $this->assertStringContainsString('has not left a store', $c->get('scanError'));

        $c->set('scan', 'ZZ-NOT-A-ROLL')->call('addScan');
        $this->assertSame('Barcode not found in production.', $c->get('scanError'));
    }

    /** Holding writes the row and puts the weight back on that store's stock. */
    public function test_holding_a_roll_adds_it_back_to_store_stock(): void
    {
        Livewire::actingAs($this->admin());
        [$barcode, $locationId, $productId, $weight] = $this->releasedRoll();

        $before = $this->stock($locationId, $productId);

        Livewire::test(WaitingArea::class)
            ->set('gateId', $this->gateFor($locationId, 'in'))
            ->set('scan', $barcode)->call('addScan')
            ->call('save');

        $entry = BplWaitingAreaEntry::where('barcode', $barcode)->firstOrFail();
        $this->assertSame($locationId, (int) $entry->location_id);
        $this->assertSame(now()->format('Y/m/d'), $entry->date);
        $this->assertNull($entry->status);

        $after = $this->stock($locationId, $productId);
        $this->assertSame($before['quantity'] + 1, $after['quantity']);
        $this->assertEqualsWithDelta($before['weight'] + $weight, $after['weight'], 0.01);

        // …and it is now standing in the waiting area.
        $held = Livewire::test(WaitingArea::class)->call('switchMode', 'held')
            ->set('heldSearch', $barcode)->instance()->held;
        $this->assertCount(1, $held);
        $this->assertSame('hardroll', $held[0]->stream);
        $this->assertNotNull(RollResolver::inWaitingArea($barcode));
    }

    /** A roll cannot be held twice, and the message says since when. */
    public function test_a_held_roll_cannot_be_held_again(): void
    {
        Livewire::actingAs($this->admin());
        [$barcode, $locationId, $productId] = $this->releasedRoll();
        $this->stock($locationId, $productId);

        $gate = $this->gateFor($locationId, 'in');

        Livewire::test(WaitingArea::class)->set('gateId', $gate)
            ->set('scan', $barcode)->call('addScan')->call('save');

        $c = Livewire::test(WaitingArea::class)->set('gateId', $gate)
            ->set('scan', $barcode)->call('addScan');

        $this->assertStringContainsString('already in a waiting area', $c->get('scanError'));
        $this->assertSame([], $c->get('items'));
    }

    /** Releasing takes the weight off again and stamps the receipt, as legacy did. */
    public function test_releasing_reverses_the_stock_and_stamps_the_receipt(): void
    {
        Livewire::actingAs($this->admin());
        [$barcode, $locationId, $productId, $weight] = $this->releasedRoll();

        $before = $this->stock($locationId, $productId);

        Livewire::test(WaitingArea::class)->set('gateId', $this->gateFor($locationId, 'in'))
            ->set('scan', $barcode)->call('addScan')->call('save');

        $c = Livewire::test(WaitingArea::class)->call('switchMode', 'out')
            ->set('gateId', $this->gateFor($locationId, 'out'))
            ->set('scan', $barcode)->call('addScan');
        $this->assertCount(1, $c->get('items'), $c->get('scanError'));

        $c->call('save');

        $this->assertNotNull(BplWaitingAreaExit::where('barcode', $barcode)->first());
        $this->assertSame(BplWaitingAreaEntry::RELEASED,
            BplWaitingAreaEntry::where('barcode', $barcode)->value('status'));

        // Back where it started.
        $after = $this->stock($locationId, $productId);
        $this->assertSame($before['quantity'], $after['quantity']);
        $this->assertEqualsWithDelta($before['weight'], $after['weight'], 0.01);

        // The receipt is stamped the way Warehouse Exit stamps one.
        $this->assertSame('Exited', BplWarehouseEntry::where('barcode', $barcode)->value('status'));

        // …and it is out of the waiting area.
        $this->assertSame([], RollResolver::inWaitingArea($barcode));
        $this->assertSame(0, Livewire::test(WaitingArea::class)->call('switchMode', 'held')
            ->set('heldSearch', $barcode)->instance()->held->count());
    }

    /** A roll is released from the store it is standing at, not another one. */
    public function test_a_roll_is_released_from_the_store_it_is_held_at(): void
    {
        Livewire::actingAs($this->admin());
        [$barcode, $locationId, $productId] = $this->releasedRoll();
        $this->stock($locationId, $productId);

        Livewire::test(WaitingArea::class)->set('gateId', $this->gateFor($locationId, 'in'))
            ->set('scan', $barcode)->call('addScan')->call('save');

        // Another store's outbound gate.
        $otherGate = WarehouseGate::query()
            ->whereHas('warehouse', fn ($q) => $q->where('module', 'jumbo-rolls')
                ->whereNotNull('legacy_location_id')->where('legacy_location_id', '!=', $locationId))
            ->where('direction', 'out')->first();

        if (! $otherGate) {
            $this->markTestSkipped('only one jumbo roll store has an outbound gate');
        }

        $c = Livewire::test(WaitingArea::class)->call('switchMode', 'out')
            ->set('gateId', $otherGate->id)
            ->set('scan', $barcode)->call('addScan');

        $this->assertStringContainsString('being held at', $c->get('scanError'));
        $this->assertSame([], $c->get('items'));
        $this->assertNull(BplWaitingAreaExit::where('barcode', $barcode)->first());
    }

    /** Switching sides clears what was scanned for the other one. */
    public function test_switching_sides_clears_the_queue(): void
    {
        Livewire::actingAs($this->admin());
        [$barcode, $locationId] = $this->releasedRoll();

        $c = Livewire::test(WaitingArea::class)->set('gateId', $this->gateFor($locationId, 'in'))
            ->set('scan', $barcode)->call('addScan');
        $this->assertCount(1, $c->get('items'));

        $c->call('switchMode', 'out');
        $this->assertSame([], $c->get('items'));
        $this->assertSame('out', $c->get('mode'));
    }

    /** The legacy tables are still readable under their old names. */
    public function test_the_legacy_quarantine_names_still_work(): void
    {
        $bpl = DB::connection('bpl');

        foreach (['bpl_quarantine' => 'bpl_waiting_area_entry',
            'bpl_quarantine_storeexit' => 'bpl_waiting_area_exit'] as $old => $new) {
            $this->assertSame(
                $bpl->table($new)->count(),
                $bpl->table($old)->count(),
                $old . ' should be a view onto ' . $new
            );
        }

        // …on the bil schema too, which is where the legacy app reads them.
        $this->assertSame(
            $bpl->table('bpl_waiting_area_entry')->count(),
            DB::connection('bil')->table('bpl_quarantine')->count()
        );
    }
}
