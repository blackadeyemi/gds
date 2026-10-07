<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Logistics\Delivery;
use Modules\Bpl\Livewire\Logistics\Weighbridge;
use Modules\Bpl\Models\BplWaybill;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Logistics → Weighbridge.
 *
 * LIVE bpl database: each test makes its own delivery through the Delivery
 * screen, and tearDown removes it and puts its rolls back on the loading bay.
 * The 1,222 real waybills are only read.
 */
class BplWeighbridgePageTest extends TestCase
{
    private array $waybills = [];
    private array $barcodes = [];

    protected function tearDown(): void
    {
        $bpl = DB::connection('bpl');

        if ($this->waybills) {
            $deliveryIds = $bpl->table('bpl_delivery_details')->whereIn('delivery_barcode', $this->waybills)
                ->pluck('bpl_delivery_id')->map(fn ($id) => (int) $id)->unique()->all();

            $bpl->table('bpl_delivery_details')->whereIn('delivery_barcode', $this->waybills)->delete();
            $bpl->table('bpl_delivery_barcode')->whereIn('delivery_barcode', $this->waybills)->delete();
            if ($deliveryIds) {
                $bpl->table('bpl_delivery')->whereIn('id', $deliveryIds)->delete();
            }
        }
        if ($this->barcodes) {
            $bpl->table('bpl_warehouse_exit')->whereIn('barcode', $this->barcodes)->update(['status' => null]);
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    /** Make a real delivery through the Delivery screen; returns its waybill row. */
    private function deliver(int $rolls = 2, float $truckWeight = 16000): BplWaybill
    {
        $barcodes = DB::connection('bpl')->table('bpl_warehouse_exit as sx')
            ->join('bpl_production as r', function ($j) {
                $j->on('r.barcode', '=', 'sx.barcode')->whereNull('r.deleted_at');
            })
            ->whereNull('sx.status')->whereNull('sx.deleted_at')->whereNotNull('r.product_id')
            ->orderByDesc('sx.id')->limit($rolls)->pluck('sx.barcode')->all();
        $this->assertCount($rolls, $barcodes);
        $this->barcodes = array_merge($this->barcodes, $barcodes);

        $c = Livewire::test(Delivery::class)
            ->set('customerName', 'BHN')->set('vehicleNumber', 'ZZ WB 001')
            ->set('driver', 'ZZ Driver')->set('loader', 'ZZ Loader')
            ->set('truckWeight', (string) $truckWeight);

        foreach ($barcodes as $barcode) {
            $c->set('scan', $barcode)->call('addScan');
        }
        $c->call('save');

        $number = $c->get('lastWaybill');
        $this->assertNotSame('', $number, $c->get('scanError'));
        $this->waybills[] = $number;

        return BplWaybill::where('delivery_barcode', $number)->firstOrFail();
    }

    private function rollWeight(string $number): float
    {
        return (float) DB::connection('bpl')->table('bpl_delivery_details as d')
            ->join('bpl_production as p', 'p.barcode', '=', 'd.barcode')
            ->where('d.delivery_barcode', $number)->sum('p.weight');
    }

    public function test_the_page_opens_and_lists_the_load_awaiting_a_weight(): void
    {
        Livewire::actingAs($this->admin());
        $waybill = $this->deliver();

        $this->actingAs($this->admin())->get('/bpl/logistics/weighbridge')
            ->assertOk()->assertSee('BPL Weighbridge')->assertSee('Awaiting weight');

        $rows = Livewire::test(Weighbridge::class)->set('search', $waybill->delivery_barcode)->viewData('rows');
        $this->assertSame(1, $rows->total());

        $row = $rows->collect()->first();
        $this->assertSame('BHN', $row->customer_name);
        $this->assertSame(2, (int) $row->roll_count);
        $this->assertSame(16000.0, (float) $row->truck_weight);
        $this->assertEqualsWithDelta($this->rollWeight($waybill->delivery_barcode), (float) $row->roll_weight, 0.01);
    }

    /** The weight completes the waybill and lands on the delivery. */
    public function test_weighing_a_load_completes_the_waybill(): void
    {
        Livewire::actingAs($this->admin());
        $waybill = $this->deliver();
        $full = 16000 + $this->rollWeight($waybill->delivery_barcode);

        Livewire::test(Weighbridge::class)->call('edit', $waybill->id)
            ->assertSet('waybill', $waybill->delivery_barcode)
            ->assertSet('truckWeight', '16000')
            ->set('fullWeight', (string) $full)
            ->call('save')->assertHasNoErrors();

        $waybill->refresh();
        $this->assertSame('completed', $waybill->status);
        $this->assertEqualsWithDelta($full, (float) $waybill->full_weight, 0.01);
    }

    /** A corrected tare is written back to the delivery form. */
    public function test_the_empty_weight_can_be_corrected_here(): void
    {
        Livewire::actingAs($this->admin());
        $waybill = $this->deliver();
        $rolls = $this->rollWeight($waybill->delivery_barcode);

        Livewire::test(Weighbridge::class)->call('edit', $waybill->id)
            ->set('truckWeight', '15500')
            ->set('fullWeight', (string) (15500 + $rolls))
            ->call('save')->assertHasNoErrors();

        $deliveryId = DB::connection('bpl')->table('bpl_delivery_details')
            ->where('delivery_barcode', $waybill->delivery_barcode)->value('bpl_delivery_id');

        $this->assertSame(15500.0,
            (float) DB::connection('bpl')->table('bpl_delivery')->where('id', $deliveryId)->value('truck_weight'));
    }

    /** The legacy check, kept: a net short of the rolls by more than 150 kg is refused. */
    public function test_a_net_short_of_the_rolls_is_refused(): void
    {
        Livewire::actingAs($this->admin());
        $waybill = $this->deliver();
        $rolls = $this->rollWeight($waybill->delivery_barcode);

        $c = Livewire::test(Weighbridge::class)->call('edit', $waybill->id)
            ->set('fullWeight', (string) (16000 + $rolls - 500))
            ->call('save')->assertHasErrors('fullWeight');
        $this->assertStringContainsString('short of the', $c->errors()->first('fullWeight'));
        $this->assertSame('pending', $waybill->fresh()->status);

        // 150 kg short is still accepted — the tolerance is inclusive.
        Livewire::test(Weighbridge::class)->call('edit', $waybill->id)
            ->set('fullWeight', (string) (16000 + $rolls - 150))
            ->call('save')->assertHasNoErrors();
        $this->assertSame('completed', $waybill->fresh()->status);
    }

    public function test_a_loaded_truck_must_outweigh_the_empty_one(): void
    {
        Livewire::actingAs($this->admin());
        $waybill = $this->deliver();

        Livewire::test(Weighbridge::class)->call('edit', $waybill->id)
            ->set('fullWeight', '15000')
            ->call('save')->assertHasErrors('fullWeight');

        $this->assertSame('pending', $waybill->fresh()->status);
        $this->assertSame(0.0, (float) $waybill->fresh()->full_weight);
    }

    /** Cancelling an unweighed load puts its rolls back and removes the delivery. */
    public function test_cancelling_a_pending_load_returns_its_rolls(): void
    {
        Livewire::actingAs($this->admin());
        $waybill = $this->deliver(2);
        $number = $waybill->delivery_barcode;
        $barcodes = DB::connection('bpl')->table('bpl_delivery_details')
            ->where('delivery_barcode', $number)->pluck('barcode')->all();
        $deliveryId = (int) DB::connection('bpl')->table('bpl_delivery_details')
            ->where('delivery_barcode', $number)->value('bpl_delivery_id');

        Livewire::test(Weighbridge::class)->set('confirmingDelete', $waybill->id)->call('deleteConfirmed');

        $bpl = DB::connection('bpl');
        $this->assertNull(BplWaybill::find($waybill->id));
        $this->assertSame(0, $bpl->table('bpl_delivery_details')->where('delivery_barcode', $number)->count());
        $this->assertSame(0, $bpl->table('bpl_delivery')->where('id', $deliveryId)->count());

        foreach ($barcodes as $barcode) {
            $this->assertNull($bpl->table('bpl_warehouse_exit')->where('barcode', $barcode)->value('status'),
                'a cancelled roll is still stamped as delivered');
        }
    }

    /** A weighed load is not cancelled by accident — it has to be reopened first. */
    public function test_a_weighed_load_cannot_be_cancelled(): void
    {
        Livewire::actingAs($this->admin());
        $waybill = $this->deliver();
        $rolls = $this->rollWeight($waybill->delivery_barcode);

        Livewire::test(Weighbridge::class)->call('edit', $waybill->id)
            ->set('fullWeight', (string) (16000 + $rolls))->call('save');

        $c = Livewire::test(Weighbridge::class);
        $this->assertStringStartsWith('Already weighed', $c->instance()->deleteGuard($waybill->fresh()));

        $c->set('confirmingDelete', $waybill->id)->call('deleteConfirmed');
        $this->assertNotNull(BplWaybill::find($waybill->id), 'a weighed load was cancelled');

        // Reopening puts it back in the queue.
        Livewire::test(Weighbridge::class)->call('reopen', $waybill->id);
        $this->assertSame('pending', $waybill->fresh()->status);
    }
}
