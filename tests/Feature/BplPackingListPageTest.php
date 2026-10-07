<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Sales\PackingLists;
use Modules\Bpl\Models\BplPackingList;
use Modules\Bpl\Models\BplProforma;
use Modules\Bpl\Models\BplProformaItem;
use Modules\Bpl\Support\PackingList;
use Modules\Bpl\Support\ProformaInvoice;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Sales → Packing Lists.
 *
 * LIVE bpl database: each test raises its own proforma against a real order
 * that has none, packs it, and tearDown removes the packing lists, the prices
 * and the proforma again. The 409 real packing lists are only read.
 */
class BplPackingListPageTest extends TestCase
{
    private array $orderIds = [];

    protected function tearDown(): void
    {
        if ($this->orderIds) {
            $bpl = DB::connection('bpl');

            $bpl->table('bpl_packing_list')->whereIn('order_id', $this->orderIds)->delete();

            $itemIds = $bpl->table('bpl_sales_items')->whereIn('order_id', $this->orderIds)->pluck('id')->all();
            if ($itemIds) {
                BplProformaItem::whereIn('order_item_id', $itemIds)->delete();
            }
            $bpl->table('bpl_proforma')->whereIn('order_id', $this->orderIds)->delete();
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    /**
     * An order with lines, no proforma and no packing list — given a proforma
     * of its own so it can be packed.
     */
    private function packableOrder(int $containers = 2): int
    {
        $bpl = DB::connection('bpl');

        $orderId = (int) $bpl->table('bpl_sales as s')
            ->whereNull('s.deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('bpl_sales_items as i')->whereColumn('i.order_id', 's.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_proforma as pf')->whereColumn('pf.order_id', 's.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_packing_list as pl')->whereColumn('pl.order_id', 's.id'))
            ->orderByDesc('s.id')->value('s.id');

        $this->assertGreaterThan(0, $orderId, 'no free order to pack');
        $this->orderIds[] = $orderId;

        BplProforma::create([
            'order_id' => $orderId,
            'freight' => 'Inland Transport',
            'container' => $containers,
            'freight_price' => 1000,
            'terms' => 'FOB',
            'shipment' => 'Truck',
            'payment_term_id' => (int) $bpl->table('bpl_payment_terms')->whereNull('deleted_at')->value('id'),
            'currency_id' => (int) $bpl->table('currencies')->where('code', 'USD')->value('id'),
            'account_id' => (int) $bpl->table('bpl_accounts as a')->join('bpl_banks as b', 'b.id', '=', 'a.beneficiary')
                ->whereNull('a.deleted_at')->value('a.id'),
            'date' => now()->format('Y/m/d'),
            'created_at' => now(),
        ]);

        foreach (ProformaInvoice::lines($orderId) as $line) {
            BplProformaItem::create(['order_item_id' => $line['order_item_id'], 'price' => 900]);
        }

        return $orderId;
    }

    /** Pack an order: half of every line into each of two containers. */
    private function pack(int $orderId, float $share = 0.5)
    {
        $c = Livewire::test(PackingLists::class)->call('startNew')->set('orderId', $orderId);

        foreach ([0, 1] as $i) {
            $c->set('containers.' . $i . '.name', 'ZZTU 00' . ($i + 1));

            foreach (ProformaInvoice::lines($orderId) as $line) {
                $c->set('containers.' . $i . '.goods.' . $line['order_item_id'] . '.rolls', '5')
                    ->set('containers.' . $i . '.goods.' . $line['order_item_id'] . '.weight',
                        (string) round($line['weight'] * $share, 2));
            }
        }

        return $c->call('save');
    }

    public function test_the_page_opens_under_sales(): void
    {
        $this->actingAs($this->admin())->get('/bpl/sales/packing-lists')
            ->assertOk()
            ->assertSee('Packing Lists')
            ->assertSee('Gross');
    }

    /** The order's containers are written as the legacy JSON, and the number keeps its placeholder. */
    public function test_a_packing_list_is_built_from_the_proforma(): void
    {
        Livewire::actingAs($this->admin());
        $orderId = $this->packableOrder();

        $c = Livewire::test(PackingLists::class)->call('startNew')->set('orderId', $orderId);
        $this->assertCount(2, $c->get('containers'), 'the proforma quoted two containers');

        $this->pack($orderId)->assertHasNoErrors();

        $packing = BplPackingList::where('order_id', $orderId)->firstOrFail();
        $this->assertCount(2, $packing->containers);
        $this->assertSame('ZZTU 001', $packing->containers[0]['name']);
        $this->assertSame((string) BplPackingList::DEFAULT_TARE, $packing->containers[0]['weight']);

        // Goods carry the proforma's description, which is what the invoice matches on.
        $first = ProformaInvoice::lines($orderId)[0];
        $this->assertSame($first['fullname'], $packing->containers[0]['goods'][0]['description']);

        // The number keeps the customer-id placeholder, and prints as the label.
        $customerId = (int) DB::connection('bpl')->table('bpl_sales')->where('id', $orderId)->value('customerid');
        $this->assertStringContainsString(':' . $customerId, $packing->number);
        $label = DB::connection('bpl')->table('bpl_customers')->where('id', $customerId)->value('customerlabel');
        $this->assertStringNotContainsString(':', PackingList::label($packing->number, $customerId, $label));

        // Totals: net is what was loaded, gross adds both tares.
        $ordered = (float) DB::connection('bpl')->table('bpl_sales_items')->where('order_id', $orderId)->sum('weight');
        $this->assertEqualsWithDelta($ordered, $packing->netWeight(), 0.05);
        $this->assertEqualsWithDelta($ordered + 2000, $packing->grossWeight(), 0.05);
    }

    public function test_nothing_may_ship_that_was_not_ordered(): void
    {
        Livewire::actingAs($this->admin());
        $orderId = $this->packableOrder();

        // 60% into each container is 120% of the order.
        $c = $this->pack($orderId, 0.6);
        $this->assertStringContainsString('more than the', $c->get('formError'));
        $this->assertNull(BplPackingList::where('order_id', $orderId)->first());

        // And a line with nothing on it is refused too.
        $c = Livewire::test(PackingLists::class)->call('startNew')->set('orderId', $orderId)
            ->set('containers.0.name', 'ZZTU 001')->set('containers.1.name', 'ZZTU 002')
            ->call('save');
        $this->assertStringContainsString('is empty', $c->errors()->first() ?: $c->get('formError'));
        $this->assertNull(BplPackingList::where('order_id', $orderId)->first());
    }

    public function test_containers_need_a_number_and_may_not_repeat(): void
    {
        Livewire::actingAs($this->admin());
        $orderId = $this->packableOrder();
        $line = ProformaInvoice::lines($orderId)[0];

        $c = Livewire::test(PackingLists::class)->call('startNew')->set('orderId', $orderId)
            ->set('containers.0.goods.' . $line['order_item_id'] . '.rolls', '5')
            ->set('containers.0.goods.' . $line['order_item_id'] . '.weight', '100')
            ->call('save')->assertHasErrors('containers.0.name');

        $c->set('containers.0.name', 'ZZTU 001')->set('containers.1.name', 'ZZTU 001')
            ->set('containers.1.goods.' . $line['order_item_id'] . '.rolls', '5')
            ->set('containers.1.goods.' . $line['order_item_id'] . '.weight', '100')
            ->call('save')->assertHasErrors('containers.1.name');

        $this->assertNull(BplPackingList::where('order_id', $orderId)->first());
    }

    public function test_an_order_takes_one_packing_list_and_the_proforma_is_then_off_the_list(): void
    {
        Livewire::actingAs($this->admin());
        $orderId = $this->packableOrder();
        $this->pack($orderId)->assertHasNoErrors();

        $offered = Livewire::test(PackingLists::class)->instance()->openProformas->pluck('id')->all();
        $this->assertNotContains($orderId, $offered);
    }

    /** Editing loads the containers back, matching goods to their order line. */
    public function test_a_packing_list_can_be_reopened_and_changed(): void
    {
        Livewire::actingAs($this->admin());
        $orderId = $this->packableOrder();
        $this->pack($orderId)->assertHasNoErrors();
        $packing = BplPackingList::where('order_id', $orderId)->firstOrFail();

        $line = ProformaInvoice::lines($orderId)[0];

        $c = Livewire::test(PackingLists::class)->call('edit', $packing->id)
            ->assertSet('orderId', $orderId);

        $this->assertSame('ZZTU 001', $c->get('containers.0.name'));
        $this->assertNotSame('', $c->get('containers.0.goods.' . $line['order_item_id'] . '.weight'));

        $c->set('containers.0.name', 'ZZTU 999')->call('save')->assertHasNoErrors();
        $this->assertSame('ZZTU 999', $packing->fresh()->containers[0]['name']);
    }

    /** Splitting makes a packing list per shipment and marks the parent. */
    public function test_a_packing_list_splits_into_partial_shipments(): void
    {
        Livewire::actingAs($this->admin());
        $orderId = $this->packableOrder();
        $this->pack($orderId)->assertHasNoErrors();
        $packing = BplPackingList::where('order_id', $orderId)->firstOrFail();

        Livewire::test(PackingLists::class)
            ->call('openSplit', $packing->id)
            ->set('splitCount', 2)
            ->set('splitAssignment.0', 1)
            ->set('splitAssignment.1', 2)
            ->call('saveSplit');

        $all = BplPackingList::where('order_id', $orderId)->orderBy('id')->get();
        $this->assertCount(3, $all, 'the parent plus two partial shipments');
        $this->assertSame('1', (string) $all->first()->split);
        $this->assertSame($packing->number . '(A)', $all[1]->number);
        $this->assertSame($packing->number . '(B)', $all[2]->number);
        $this->assertCount(1, $all[1]->containers);
        $this->assertCount(1, $all[2]->containers);

        // A partial shipment is never edited, and the split parent is not either.
        Livewire::test(PackingLists::class)->call('edit', $all[1]->id)->assertSet('mode', 'list');
        Livewire::test(PackingLists::class)->call('edit', $packing->id)->assertSet('mode', 'list');
    }

    /** A parent still carrying partials stays; deleting the last one clears the split flag. */
    public function test_deleting_respects_the_partial_shipments(): void
    {
        Livewire::actingAs($this->admin());
        $orderId = $this->packableOrder();
        $this->pack($orderId)->assertHasNoErrors();
        $parent = BplPackingList::where('order_id', $orderId)->firstOrFail();

        Livewire::test(PackingLists::class)->call('openSplit', $parent->id)
            ->set('splitAssignment.0', 1)->set('splitAssignment.1', 2)->call('saveSplit');

        $partials = BplPackingList::where('order_id', $orderId)->whereKeyNot($parent->id)->get();

        Livewire::test(PackingLists::class)->call('deletePacking', $parent->id);
        $this->assertNotNull(BplPackingList::find($parent->id), 'a parent with partials was deleted');

        foreach ($partials as $partial) {
            Livewire::test(PackingLists::class)->call('deletePacking', $partial->id);
        }

        $this->assertSame(1, BplPackingList::where('order_id', $orderId)->count());
        $this->assertSame('0', (string) $parent->fresh()->split, 'the split flag should be cleared');
    }

    /** Both documents print, and the invoice charges what shipped. */
    public function test_the_packing_list_and_final_invoice_print(): void
    {
        Livewire::actingAs($this->admin());
        $orderId = $this->packableOrder();
        $this->pack($orderId)->assertHasNoErrors();
        $packing = BplPackingList::where('order_id', $orderId)->firstOrFail();

        $this->actingAs($this->admin())->get('/bpl/sales/packing-lists/' . $packing->id . '/print')
            ->assertOk()
            ->assertSee('PACKING LIST')
            ->assertSee('ZZTU 001')
            ->assertSee('Truck No');   // Inland Transport carries on a truck

        // Final invoice: the goods are priced at 900/tonne on what shipped.
        $shipped = $packing->netWeight();
        $expected = round(900 * $shipped / 1000, 2) + round(2 * 1000, 2);

        $this->get('/bpl/sales/packing-lists/' . $packing->id . '/invoice')
            ->assertOk()
            ->assertSee('FINAL INVOICE')
            ->assertSee(number_format($expected, 2))
            ->assertSee(number_format($packing->rolls()));
    }
}
