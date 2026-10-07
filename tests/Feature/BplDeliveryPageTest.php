<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Logistics\Delivery;
use Modules\Bpl\Models\BplDelivery;
use Modules\Bpl\Support\DeliveryNumber;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Logistics → Delivery.
 *
 * LIVE bpl database. Everything this writes is tracked by id and removed in
 * tearDown, and every roll it sends out has its warehouse-exit status put back
 * to NULL, so the loading bay ends the run exactly as it started.
 */
class BplDeliveryPageTest extends TestCase
{
    private array $deliveryIds = [];
    private array $lineIds = [];
    private array $waybillIds = [];

    /** barcode => the status it had before the test stamped it. */
    private array $exitStatus = [];

    protected function tearDown(): void
    {
        $bpl = DB::connection('bpl');

        foreach ($this->exitStatus as $barcode => $status) {
            $bpl->table('bpl_warehouse_exit')->where('barcode', $barcode)->update(['status' => $status]);
        }
        if ($this->lineIds) {
            $bpl->table('bpl_delivery_details')->whereIn('id', $this->lineIds)->delete();
        }
        if ($this->waybillIds) {
            $bpl->table('bpl_delivery_barcode')->whereIn('id', $this->waybillIds)->delete();
        }
        if ($this->deliveryIds) {
            $bpl->table('bpl_delivery')->whereIn('id', $this->deliveryIds)->delete();
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    /** Barcodes sitting on the loading bay: released from the store, not yet delivered. */
    private function waiting(int $n): array
    {
        $rows = DB::connection('bpl')->table('bpl_warehouse_exit as sx')
            ->join('bpl_production as r', function ($j) {
                $j->on('r.barcode', '=', 'sx.barcode')->whereNull('r.deleted_at');
            })
            ->whereNull('sx.status')->whereNull('sx.deleted_at')
            ->whereNotNull('r.product_id')
            ->orderByDesc('sx.id')->limit($n)->pluck('sx.barcode')->all();

        $this->assertCount($n, $rows, 'not enough rolls waiting on the loading bay');

        foreach ($rows as $barcode) {
            $this->exitStatus[$barcode] = null;
        }

        return $rows;
    }

    /** Track whatever the save just wrote, so tearDown can take it out again. */
    private function trackNewRows(string $number): void
    {
        $bpl = DB::connection('bpl');
        $this->waybillIds = array_merge($this->waybillIds,
            $bpl->table('bpl_delivery_barcode')->where('delivery_barcode', $number)->pluck('id')->all());

        $lines = $bpl->table('bpl_delivery_details')->where('delivery_barcode', $number)->get(['id', 'bpl_delivery_id']);
        $this->lineIds = array_merge($this->lineIds, $lines->pluck('id')->all());
        $this->deliveryIds = array_merge($this->deliveryIds, $lines->pluck('bpl_delivery_id')->map(fn ($id) => (int) $id)->unique()->all());
    }

    private function load(array $barcodes, array $header = [])
    {
        $c = Livewire::test(Delivery::class)
            ->set('customerName', $header['customer'] ?? 'BHN')
            ->set('vehicleNumber', $header['vehicle'] ?? 'ZZ TEST 001')
            ->set('driver', $header['driver'] ?? 'ZZ Driver')
            ->set('loader', $header['loader'] ?? 'ZZ Loader')
            ->set('driverPhone', '08000000000')
            ->set('truckWeight', '16500');

        foreach ($barcodes as $barcode) {
            $c->set('scan', $barcode)->call('addScan');
        }

        return $c;
    }

    public function test_the_page_opens_under_logistics(): void
    {
        $this->actingAs($this->admin())->get('/bpl/logistics/delivery')
            ->assertOk()
            ->assertSee('New delivery')
            ->assertSee('Create waybill')
            ->assertSee('Logistics');
    }

    public function test_transporters_moved_to_logistics_and_the_old_url_is_gone(): void
    {
        $this->actingAs($this->admin());

        $this->get('/bpl/logistics/transporters')->assertOk()->assertSee('BPL Transporters');
        $this->get('/bpl/sales/transporters')->assertNotFound();

        // The grants moved with it, so whoever had the page still has it.
        $this->assertSame(0, DB::connection('core')->table('permissions')
            ->where('name', 'like', 'bpl.sales.transporters:%')->count());
        $this->assertGreaterThan(0, DB::connection('core')->table('permissions')
            ->where('name', 'like', 'bpl.logistics.transporters:%')->count());
    }

    /** A truck load writes the form, the waybill and a line per roll, and stamps each roll as gone. */
    public function test_a_load_creates_a_waybill_and_stamps_the_rolls(): void
    {
        Livewire::actingAs($this->admin());
        $barcodes = $this->waiting(3);

        $c = $this->load($barcodes);
        $this->assertCount(3, $c->get('items'));

        $c->call('save');
        $number = $c->get('lastWaybill');
        $this->assertNotSame('', $number, 'no waybill was created: ' . $c->get('scanError'));
        $this->trackNewRows($number);

        $this->assertSame(DeliveryNumber::prefix(now()->format('Y-m-d')), substr($number, 0, strlen(DeliveryNumber::prefix(now()->format('Y-m-d')))));

        $bpl = DB::connection('bpl');
        $waybill = $bpl->table('bpl_delivery_barcode')->where('delivery_barcode', $number)->first();
        $this->assertSame('pending', $waybill->status, 'a customer load waits for the weighbridge');
        $this->assertSame(now()->format('y-m-d'), $waybill->date);

        $this->assertSame(3, $bpl->table('bpl_delivery_details')->where('delivery_barcode', $number)->count());

        $form = BplDelivery::find($this->deliveryIds[0]);
        $this->assertSame('BHN', $form->customer_name);
        $this->assertSame('ZZ TEST 001', $form->vechile_number);
        $this->assertSame(now()->format('Y/m/d'), $form->date);

        foreach ($barcodes as $barcode) {
            $this->assertSame('yes', $bpl->table('bpl_warehouse_exit')->where('barcode', $barcode)->value('status'));
        }

        // The form is cleared for the next truck.
        $this->assertSame([], $c->get('items'));
    }

    /** The second load of a day is -002, not the table's row id (the legacy bug). */
    public function test_the_waybill_number_counts_the_days_loads(): void
    {
        Livewire::actingAs($this->admin());
        $today = now()->format('Y-m-d');
        $prefix = DeliveryNumber::prefix($today);
        $before = DB::connection('bpl')->table('bpl_delivery_barcode')
            ->where('delivery_barcode', 'like', $prefix . '-%')->count();

        $numberOne = $this->load($this->waiting(1))->call('save')->get('lastWaybill');
        $this->trackNewRows($numberOne);

        $numberTwo = $this->load($this->waiting(1))->call('save')->get('lastWaybill');
        $this->trackNewRows($numberTwo);

        $this->assertSame($prefix . '-' . str_pad((string) ($before + 1), 3, '0', STR_PAD_LEFT), $numberOne);
        $this->assertSame($prefix . '-' . str_pad((string) ($before + 2), 3, '0', STR_PAD_LEFT), $numberTwo);
    }

    /** A load to BIL has no truck paperwork and needs no weighbridge. */
    public function test_an_inter_company_load_is_complete_at_once(): void
    {
        Livewire::actingAs($this->admin());
        $barcodes = $this->waiting(1);

        $c = Livewire::test(Delivery::class)->set('customerName', BplDelivery::INTERNAL_CUSTOMER);
        $this->assertTrue($c->instance()->isInternal());

        $c->set('scan', $barcodes[0])->call('addScan')->call('save');
        $number = $c->get('lastWaybill');
        $this->assertNotSame('', $number, $c->get('scanError'));
        $this->trackNewRows($number);

        $bpl = DB::connection('bpl');
        $this->assertSame('completed', $bpl->table('bpl_delivery_barcode')->where('delivery_barcode', $number)->value('status'));
        $this->assertNull(BplDelivery::find($this->deliveryIds[0])->vechile_number);
    }

    public function test_a_roll_cannot_go_out_twice_or_be_scanned_twice(): void
    {
        Livewire::actingAs($this->admin());
        $barcodes = $this->waiting(1);

        $c = $this->load($barcodes);
        $c->set('scan', $barcodes[0])->call('addScan');
        $this->assertSame('Barcode already on this truck.', $c->get('scanError'));
        $this->assertCount(1, $c->get('items'));

        $c->call('save');
        $this->trackNewRows($c->get('lastWaybill'));

        // Now it is gone: scanning it again says so, and names the waybill.
        $again = Livewire::test(Delivery::class)->set('scan', $barcodes[0])->call('addScan');
        $this->assertStringContainsString('already gone out', $again->get('scanError'));
        $this->assertSame([], $again->get('items'));
    }

    /** What the scanner refuses, and why. */
    public function test_scans_that_are_refused_explain_themselves(): void
    {
        Livewire::actingAs($this->admin());
        $bpl = DB::connection('bpl');

        // Still in the warehouse: entered, never released.
        $inStore = $bpl->table('bpl_warehouse_entry as se')
            ->whereNull('se.deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_warehouse_exit as sx')
                ->whereColumn('sx.barcode', 'se.barcode')->whereNull('sx.deleted_at'))
            ->value('se.barcode');
        $this->assertNotNull($inStore);

        $softroll = $bpl->table('bpl_softroll_production')->whereNull('deleted_at')
            ->where('barcode', 'like', '%-S%')->value('barcode');

        $c = Livewire::test(Delivery::class);

        $c->set('scan', $inStore)->call('addScan');
        $this->assertStringContainsString('still in the warehouse', $c->get('scanError'));

        if ($softroll) {
            $c->set('scan', $softroll)->call('addScan');
            $this->assertStringContainsString('softroll', strtolower($c->get('scanError')));
        }

        $c->set('scan', 'ZZ-NOT-A-BARCODE')->call('addScan');
        $this->assertSame('Barcode not found in production.', $c->get('scanError'));

        $this->assertSame([], $c->get('items'));
    }

    /** Nothing is written when the header is incomplete or no roll is scanned. */
    public function test_the_header_and_at_least_one_roll_are_required(): void
    {
        Livewire::actingAs($this->admin());
        $bpl = DB::connection('bpl');
        $before = (int) $bpl->table('bpl_delivery')->max('id');

        Livewire::test(Delivery::class)->call('save')->assertHasErrors(['customerName']);

        // A customer load needs its truck, driver and loader.
        Livewire::test(Delivery::class)->set('customerName', 'BHN')->call('save')
            ->assertHasErrors(['vehicleNumber', 'driver', 'loader']);

        // Header complete, nothing scanned.
        $c = Livewire::test(Delivery::class)->set('customerName', 'BHN')
            ->set('vehicleNumber', 'ZZ TEST 002')->set('driver', 'ZZ D')->set('loader', 'ZZ L')
            ->call('save');
        $this->assertSame('Scan at least one roll before saving.', $c->get('scanError'));

        $this->assertSame($before, (int) $bpl->table('bpl_delivery')->max('id'), 'a delivery was written');
    }

    /** The waybill sheet prints the load grouped by product, and its rolls. */
    public function test_the_waybill_prints(): void
    {
        Livewire::actingAs($this->admin());
        $barcodes = $this->waiting(2);

        $number = $this->load($barcodes)->call('save')->get('lastWaybill');
        $this->trackNewRows($number);

        $html = $this->actingAs($this->admin())
            ->get('/bpl/logistics/delivery/' . $number . '/waybill')
            ->assertOk()
            ->assertSee('WAYBILL')
            ->assertSee($number)
            ->assertSee('BHN')
            ->assertSee('ZZ TEST 001')
            ->getContent();

        foreach ($barcodes as $barcode) {
            $this->assertStringContainsString($barcode, $html);
        }

        $this->get('/bpl/logistics/delivery/ZZ-NO-SUCH-WAYBILL/waybill')->assertNotFound();
    }

    /** The receiving mill's copy: one line per roll, and its import file. */
    public function test_the_processor_slip_prints_every_roll(): void
    {
        Livewire::actingAs($this->admin());
        $barcodes = $this->waiting(2);

        $number = $this->load($barcodes, ['customer' => 'BHN'])->call('save')->get('lastWaybill');
        $this->trackNewRows($number);

        $html = $this->actingAs($this->admin())
            ->get('/bpl/logistics/delivery/' . $number . '/processor-slip')
            ->assertOk()
            ->assertSee('Delivery Form')
            ->assertSee('Hardroll No.')
            ->assertSee('BHN')
            ->getContent();

        // Every roll on its own line — the waybill groups them by product.
        foreach ($barcodes as $barcode) {
            $this->assertStringContainsString($barcode, $html);
        }

        // BHN is a known processor, so the import file is offered…
        $this->assertStringContainsString('Download import file', $html);

        $sql = $this->get('/bpl/logistics/delivery/' . $number . '/import-sql')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="' . str_replace('/', '-', $number) . '.sql"')
            ->getContent();

        $this->assertSame(count($barcodes), substr_count($sql, 'INSERT INTO importdata'));
        $this->assertStringContainsString("'2'", $sql, 'BHN is company 2 in the processor system');
        $this->assertStringContainsString($barcodes[0], $sql);
    }

    /** A delivery to anyone but a known processor has nothing to import. */
    public function test_the_import_file_is_refused_for_an_unknown_customer(): void
    {
        Livewire::actingAs($this->admin());
        $barcodes = $this->waiting(1);

        $customer = DB::connection('bpl')->table('bpl_customers')
            ->whereNotIn('customername', array_keys(\Modules\Bpl\Support\Waybill::PROCESSOR_COMPANIES))
            ->where('customername', '<>', \Modules\Bpl\Models\BplDelivery::INTERNAL_CUSTOMER)
            ->value('customername');
        $this->assertNotNull($customer);

        $number = $this->load($barcodes, ['customer' => $customer])->call('save')->get('lastWaybill');
        $this->trackNewRows($number);

        // The slip still prints; only the import file is refused, and it says why.
        $html = $this->actingAs($this->admin())
            ->get('/bpl/logistics/delivery/' . $number . '/processor-slip')->assertOk()->getContent();
        $this->assertStringNotContainsString('Download import file', $html);

        $this->get('/bpl/logistics/delivery/' . $number . '/import-sql')->assertStatus(422);
    }

    public function test_the_list_shows_the_load_just_made(): void
    {
        Livewire::actingAs($this->admin());
        $barcodes = $this->waiting(2);

        $number = $this->load($barcodes)->call('save')->get('lastWaybill');
        $this->trackNewRows($number);

        $rows = Livewire::test(Delivery::class)->call('showList')->set('listSearch', $number)->instance()->deliveries;

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]->roll_count);
        $this->assertSame('pending', $rows[0]->status);
        $this->assertGreaterThan(0, (float) $rows[0]->total_weight);
    }
}
