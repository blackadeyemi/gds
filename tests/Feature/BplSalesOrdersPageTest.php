<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Sales\Orders;
use Modules\Bpl\Models\BplCustomer;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Bpl\Models\BplSalesOrder;
use Modules\Bpl\Support\SalesOrderNumber;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Sales → Orders.
 *
 * LIVE bpl database: every order (and customer) this writes is tracked by id
 * and hard-deleted in tearDown. Real orders are only read.
 */
class BplSalesOrdersPageTest extends TestCase
{
    private array $orderIds = [];
    private array $customerIds = [];

    protected function tearDown(): void
    {
        $bpl = DB::connection('bpl');
        if ($this->orderIds) {
            $bpl->table('bpl_sales_items')->whereIn('order_id', $this->orderIds)->delete();
            $bpl->table('bpl_sales')->whereIn('id', $this->orderIds)->delete();
        }
        if ($this->customerIds) {
            $bpl->table('bpl_customers')->whereIn('id', $this->customerIds)->delete();
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    /** A real customer that can be numbered. */
    private function customer(): BplCustomer
    {
        foreach (BplCustomer::whereNotNull('customerlabel')->where('customerlabel', '<>', '')->orderBy('id')->get() as $c) {
            if (SalesOrderNumber::problemWith($c) === null) {
                return $c;
            }
        }
        $this->fail('no numberable customer');
    }

    private function products(int $n): array
    {
        return BplProductHardroll::orderBy('id')->limit($n)->pluck('id')->all();
    }

    /** Place an order through the page; returns the new order. */
    private function place(BplCustomer $customer, array $lines)
    {
        $before = (int) BplSalesOrder::withTrashed()->max('id');

        $c = Livewire::test(Orders::class)->set('customerid', $customer->id);
        $uids = array_keys($c->get('rows'));
        $c->set('addCount', max(1, count($lines) - 1));
        if (count($lines) > 1) {
            $c->call('addRows');
        }
        $uids = array_keys($c->get('rows'));
        foreach (array_values($lines) as $i => [$pid, $w]) {
            $c->set('rows.' . $uids[$i] . '.productid', $pid)->set('rows.' . $uids[$i] . '.weight', (string) $w);
        }
        $c->call('save')->assertHasNoErrors();

        $order = BplSalesOrder::where('id', '>', $before)->where('customerid', $customer->id)->latest('id')->first();
        $this->assertNotNull($order, 'no order was written');
        $this->orderIds[] = $order->id;

        return $order;
    }

    public function test_the_page_opens_on_the_new_order_form(): void
    {
        $this->actingAs($this->admin())->get('/bpl/sales/orders')
            ->assertOk()
            ->assertSee('New sales order')
            ->assertSee('Place order')
            ->assertSee('Sales order list');
    }

    public function test_the_product_list_is_served_once_as_json(): void
    {
        $json = $this->actingAs($this->admin())->getJson('/bpl/sales/orders/products')->assertOk()->json();

        $this->assertSame(BplProductHardroll::count(), count($json));
        $this->assertSame(['value', 'label', 'grade'], array_keys($json[0]));

        // …and NOT embedded in the page itself.
        $html = $this->get('/bpl/sales/orders')->getContent();
        $this->assertStringNotContainsString(e($json[0]['label']) . '","grade"', $html);
    }

    /** The ref is the customer's next number and the order number is built from it. */
    public function test_placing_an_order_generates_ref_and_number(): void
    {
        Livewire::actingAs($this->admin());
        $customer = $this->customer();
        [$p1, $p2] = $this->products(2);

        $max = (int) DB::connection('bpl')->table('bpl_sales')->where('customerid', $customer->id)->max(DB::raw('CAST(ref AS UNSIGNED)'));
        $expectedRef = str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);

        $order = $this->place($customer, [[$p1, 1250.5], [$p2, 800]]);

        $this->assertSame($expectedRef, $order->ref);
        $this->assertSame(SalesOrderNumber::compose($customer, $expectedRef), $order->orderno);
        $this->assertStringStartsWith('BPL/', $order->orderno);
        $this->assertSame('Belpapyrus', $order->company);
        $this->assertSame(now()->format('Y/m/d'), $order->date);
        $this->assertSame([1250.5, 800.0], $order->items()->orderBy('id')->pluck('weight')->map(fn ($w) => (float) $w)->all());

        // The next order for the same customer takes the next number.
        $next = $this->place($customer, [[$p1, 10]]);
        $this->assertSame((int) $expectedRef + 1, (int) $next->ref);
    }

    public function test_the_form_previews_the_number_before_saving(): void
    {
        Livewire::actingAs($this->admin());
        $customer = $this->customer();

        $preview = Livewire::test(Orders::class)->set('customerid', $customer->id)->instance()->numberPreview;

        $this->assertFalse($preview['final']);
        $this->assertNull($preview['problem']);
        $this->assertStringStartsWith('BPL/', $preview['number']);
    }

    public function test_a_customer_without_a_label_cannot_be_numbered(): void
    {
        Livewire::actingAs($this->admin());
        $id = DB::connection('bpl')->table('bpl_customers')->insertGetId([
            'customername' => 'ZZ Test Unlabelled', 'customerlabel' => '', 'customercountry' => 'Ghana',
            'customeraddress' => '', 'port' => '',
        ]);
        $this->customerIds[] = $id;
        // Not "no orders for this customer id": the id is re-used, and legacy
        // order 464 already names a customer 165 that was deleted.
        $before = (int) BplSalesOrder::withTrashed()->max('id');

        Livewire::test(Orders::class)->set('customerid', $id)
            ->set('rows.1.productid', $this->products(1)[0])->set('rows.1.weight', '100')
            ->call('save')
            ->assertHasErrors('customerid');

        $this->assertSame(0, BplSalesOrder::withTrashed()->where('id', '>', $before)->count());
    }

    public function test_a_product_may_appear_once_and_needs_a_weight(): void
    {
        Livewire::actingAs($this->admin());
        $customer = $this->customer();
        $p = $this->products(1)[0];

        Livewire::test(Orders::class)->set('customerid', $customer->id)
            ->set('addCount', 1)->call('addRows')
            ->set('rows.1.productid', $p)->set('rows.1.weight', '100')
            ->set('rows.2.productid', $p)->set('rows.2.weight', '50')
            ->call('save')
            ->assertHasErrors('rows.2.productid');

        Livewire::test(Orders::class)->set('customerid', $customer->id)
            ->set('rows.1.productid', $p)->set('rows.1.weight', '0')
            ->call('save')
            ->assertHasErrors('rows.1.weight');
    }

    /** Moving an unlocked order to another customer renumbers it under that customer. */
    public function test_changing_the_customer_renumbers_the_order(): void
    {
        Livewire::actingAs($this->admin());
        $a = $this->customer();
        $b = BplCustomer::whereKeyNot($a->id)->whereNotNull('customerlabel')->where('customerlabel', '<>', '')->get()
            ->first(fn ($c) => SalesOrderNumber::problemWith($c) === null);
        $this->assertNotNull($b);

        $order = $this->place($a, [[$this->products(1)[0], 500]]);

        Livewire::test(Orders::class)->call('editOrder', $order->id)
            ->assertSet('orderno', $order->orderno)
            ->set('customerid', $b->id)
            ->call('save')->assertHasNoErrors();

        $order->refresh();
        $this->assertSame($b->id, (int) $order->customerid);
        $this->assertSame(SalesOrderNumber::compose($b, $order->ref), $order->orderno);

        // An edit that keeps the customer keeps the number.
        $number = $order->orderno;
        Livewire::test(Orders::class)->call('editOrder', $order->id)
            ->set('rows.1.weight', '650')->call('save')->assertHasNoErrors();
        $this->assertSame($number, $order->fresh()->orderno);
        $this->assertSame(650.0, (float) $order->items()->value('weight'));
    }

    /** Proforma'd: customer is fixed, priced lines stay, and the order cannot be deleted. */
    public function test_an_order_with_a_proforma_is_protected(): void
    {
        Livewire::actingAs($this->admin());

        $orderId = (int) DB::connection('bpl')->table('bpl_proforma_items as pi')
            ->join('bpl_sales_items as i', 'i.id', '=', 'pi.order_item_id')
            ->join('bpl_proforma as p', 'p.order_id', '=', 'i.order_id')
            ->join('bpl_sales as s', 's.id', '=', 'i.order_id')
            ->whereNull('p.deleted_at')->whereNull('s.deleted_at')
            ->value('i.order_id');
        $this->assertGreaterThan(0, $orderId, 'no proforma-priced order to test against');

        $before = BplSalesOrder::find($orderId)->only(['customerid', 'orderno', 'company']);
        $itemsBefore = DB::connection('bpl')->table('bpl_sales_items')->where('order_id', $orderId)->count();

        $c = Livewire::test(Orders::class)->call('editOrder', $orderId);
        $this->assertTrue($c->instance()->orderLocked);

        // A priced row cannot be removed.
        $pricedUid = collect($c->get('rows'))->search(fn ($r) => $c->instance()->rowPriced($r));
        $this->assertNotFalse($pricedUid);
        $c->call('removeRow', $pricedUid);
        $this->assertArrayHasKey($pricedUid, $c->get('rows'));

        // Nor can the customer be changed behind the disabled field.
        $other = BplCustomer::whereKeyNot($before['customerid'])->value('id');
        $c->set('customerid', $other)->call('save');
        $this->assertSame($before, BplSalesOrder::find($orderId)->only(['customerid', 'orderno', 'company']));

        Livewire::test(Orders::class)->call('deleteOrder', $orderId);
        $this->assertNotNull(BplSalesOrder::find($orderId), 'a proforma\'d order was deleted');
        $this->assertSame($itemsBefore, DB::connection('bpl')->table('bpl_sales_items')->where('order_id', $orderId)->count());
    }

    public function test_the_list_finds_an_order_and_deletes_it_softly(): void
    {
        Livewire::actingAs($this->admin());
        $order = $this->place($this->customer(), [[$this->products(1)[0], 42.25]]);

        $c = Livewire::test(Orders::class)->call('showList')->set('listSearch', $order->orderno);
        $rows = $c->instance()->orders;
        $this->assertCount(1, $rows);
        $this->assertSame($order->orderno, $rows[0]->number);
        $this->assertSame(42.25, (float) $rows[0]->total_weight);

        $c->call('deleteOrder', $order->id);
        $this->assertNull(BplSalesOrder::find($order->id));
        $this->assertNotNull(BplSalesOrder::withTrashed()->find($order->id)?->deleted_at);
    }
}
