<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Sales\Proformas;
use Modules\Bpl\Models\BplAccount;
use Modules\Bpl\Models\BplProforma;
use Modules\Bpl\Models\BplProformaItem;
use Modules\Bpl\Models\BplSalesOrder;
use Modules\Bpl\Support\ProformaInvoice;
use Modules\Bpl\Support\ProformaMilestones;
use Modules\Core\Models\Action;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Sales → Proforma Invoices.
 *
 * LIVE bpl database: each test raises its own proforma against a real order
 * that has none, and tearDown removes the proforma and its prices. The 418
 * real proformas are only read.
 */
class BplProformaPageTest extends TestCase
{
    /** order_id => the proforma row this test created. */
    private array $orderIds = [];

    /** core.actions rows this test wrote. */
    private array $actionIds = [];

    protected function tearDown(): void
    {
        if ($this->orderIds) {
            $itemIds = DB::connection('bpl')->table('bpl_sales_items')
                ->whereIn('order_id', $this->orderIds)->pluck('id')->all();

            if ($itemIds) {
                BplProformaItem::whereIn('order_item_id', $itemIds)->delete();
            }
            DB::connection('bpl')->table('bpl_proforma')->whereIn('order_id', $this->orderIds)->delete();

            foreach ($this->orderIds as $orderId) {
                foreach ([ProformaMilestones::APPROVED, ProformaMilestones::PRODUCED, ProformaMilestones::LOADED] as $stage) {
                    Action::where('request', ProformaMilestones::requestFor($stage, $orderId))->delete();
                }
            }
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    /** A real order with lines and no proforma. */
    private function freeOrder(): BplSalesOrder
    {
        $id = DB::connection('bpl')->table('bpl_sales as s')
            ->whereNull('s.deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('bpl_sales_items as i')->whereColumn('i.order_id', 's.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_proforma as pf')->whereColumn('pf.order_id', 's.id'))
            ->orderByDesc('s.id')->value('s.id');

        $this->assertNotNull($id, 'no order without a proforma to test against');

        return BplSalesOrder::findOrFail($id);
    }

    private function refs(): array
    {
        $bpl = DB::connection('bpl');

        return [
            'term' => (int) $bpl->table('bpl_payment_terms')->whereNull('deleted_at')->value('id'),
            'currency' => (int) $bpl->table('currencies')->where('code', 'USD')->value('id'),
            'account' => (int) $bpl->table('bpl_accounts as a')->join('bpl_banks as b', 'b.id', '=', 'a.beneficiary')
                ->whereNull('a.deleted_at')->value('a.id'),
        ];
    }

    /** Raise a proforma through the page; returns [component, order]. */
    private function raise(float $price = 900, float $freightPrice = 2750): array
    {
        $order = $this->freeOrder();
        $this->orderIds[] = $order->id;
        $o = $this->refs();

        $c = Livewire::test(Proformas::class)->call('create')
            ->set('order_id', $order->id)
            ->set('freight', 'Inland Transport')
            ->set('container', '2')
            ->set('freight_price', (string) $freightPrice)
            ->set('terms', 'FOB')
            ->set('shipment', 'Truck')
            ->set('payment_term_id', $o['term'])
            ->set('currency_id', $o['currency'])
            ->set('account_id', $o['account']);

        foreach (ProformaInvoice::lines($order->id) as $line) {
            $c->set('prices.' . $line['order_item_id'], (string) $price);
        }

        $c->call('save')->assertHasNoErrors();

        return [$c, $order];
    }

    public function test_the_page_opens_under_sales(): void
    {
        $this->actingAs($this->admin())->get('/bpl/sales/proformas')
            ->assertOk()
            ->assertSee('BPL Proforma Invoices')
            ->assertSee('Lines unpriced');
    }

    /** The list leads with the newest document date, not the newest row. */
    public function test_the_list_is_sorted_by_document_date_descending(): void
    {
        Livewire::actingAs($this->admin());

        $dates = Livewire::test(Proformas::class)->viewData('rows')->collect()->pluck('date')->all();

        $sorted = $dates;
        rsort($sorted, SORT_STRING);
        $this->assertSame($sorted, $dates, 'the first page is not in date order');
    }

    public function test_a_proforma_is_raised_against_an_order(): void
    {
        Livewire::actingAs($this->admin());
        [$c, $order] = $this->raise(900, 2750);

        $proforma = BplProforma::find($order->id);
        $this->assertNotNull($proforma);
        $this->assertSame('Inland Transport', $proforma->freight);
        $this->assertSame(2, (int) $proforma->container);
        $this->assertSame(5500.0, $proforma->freightCharge());
        $this->assertSame(now()->format('Y/m/d'), $proforma->date);

        // Every line of the order got its price.
        $lines = ProformaInvoice::lines($order->id);
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertSame(900.0, $line['price']);
            $this->assertEqualsWithDelta(900 * $line['weight'] / 1000, $line['amount'], 0.01);
        }
    }

    /** Price is per tonne against a weight in kilos. */
    public function test_the_amount_is_price_per_tonne(): void
    {
        Livewire::actingAs($this->admin());
        [, $order] = $this->raise(1000, 0);

        $weight = (float) DB::connection('bpl')->table('bpl_sales_items')->where('order_id', $order->id)->sum('weight');

        $row = Livewire::test(Proformas::class)->set('search', $order->orderno)->viewData('rows')->collect()->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta($weight, (float) $row->goods_value, 0.05, 'goods value should be weight in tonnes × 1000');
    }

    public function test_every_line_needs_a_price(): void
    {
        Livewire::actingAs($this->admin());
        $order = $this->freeOrder();
        $o = $this->refs();

        $c = Livewire::test(Proformas::class)->call('create')
            ->set('order_id', $order->id)
            ->set('freight', 'Inland Transport')->set('container', '1')->set('freight_price', '100')
            ->set('terms', 'FOB')->set('shipment', 'Truck')
            ->set('payment_term_id', $o['term'])->set('currency_id', $o['currency'])->set('account_id', $o['account'])
            ->call('save');

        $first = ProformaInvoice::lines($order->id)[0]['order_item_id'];
        $c->assertHasErrors('prices.' . $first);
        $this->assertNull(BplProforma::withTrashed()->find($order->id), 'an unpriced proforma was written');

        // A zero price is refused too.
        foreach (ProformaInvoice::lines($order->id) as $line) {
            $c->set('prices.' . $line['order_item_id'], '0');
        }
        $c->call('save')->assertHasErrors('prices.' . $first);
        $this->assertNull(BplProforma::withTrashed()->find($order->id));
    }

    public function test_an_order_takes_only_one_proforma(): void
    {
        Livewire::actingAs($this->admin());
        [, $order] = $this->raise();
        $o = $this->refs();

        Livewire::test(Proformas::class)->call('create')
            ->set('order_id', $order->id)
            ->set('freight', 'Inland Transport')->set('container', '1')->set('freight_price', '100')
            ->set('terms', 'FOB')->set('shipment', 'Truck')
            ->set('payment_term_id', $o['term'])->set('currency_id', $o['currency'])->set('account_id', $o['account'])
            ->call('save')->assertHasErrors('order_id');

        // …and it is no longer offered as an open order.
        $offered = Livewire::test(Proformas::class)->instance()->openOrders->pluck('id')->all();
        $this->assertNotContains($order->id, $offered);
    }

    public function test_editing_keeps_the_order_and_updates_prices(): void
    {
        Livewire::actingAs($this->admin());
        [, $order] = $this->raise(900);

        $c = Livewire::test(Proformas::class)->call('edit', $order->id)
            ->assertSet('order_id', $order->id)
            ->assertSet('freight', 'Inland Transport')
            ->set('freight_price', '3600')
            ->set('nxp', 'ZZ-NXP-001');

        foreach (ProformaInvoice::lines($order->id) as $line) {
            $c->set('prices.' . $line['order_item_id'], '1250');
        }
        $c->call('save')->assertHasNoErrors();

        $proforma = BplProforma::find($order->id);
        $this->assertSame(3600.0, (float) $proforma->freight_price);
        $this->assertSame('ZZ-NXP-001', $proforma->nxp);
        $this->assertSame(1250.0, ProformaInvoice::lines($order->id)[0]['price']);
    }

    /** Deleting hides it; saving over it, or the restore action, brings it back. */
    public function test_deleting_is_soft_and_restorable(): void
    {
        Livewire::actingAs($this->admin());
        [, $order] = $this->raise();

        Livewire::test(Proformas::class)->set('confirmingDelete', $order->id)->call('deleteConfirmed');

        $this->assertNull(BplProforma::find($order->id));
        $this->assertNotNull(BplProforma::withTrashed()->find($order->id)->deleted_at);

        Livewire::test(Proformas::class)->call('switchView', 'deleted')->call('restore', $order->id);
        $this->assertNotNull(BplProforma::find($order->id));
    }

    /** The printed sheet: the maths, the bank block and the words. */
    public function test_the_proforma_sheet_prints(): void
    {
        Livewire::actingAs($this->admin());
        [, $order] = $this->raise(1000, 500);

        $html = $this->actingAs($this->admin())
            ->get('/bpl/sales/proformas/' . $order->id . '/print')
            ->assertOk()
            ->assertSee('PROFORMA INVOICE')
            ->assertSee($order->orderno)
            ->assertSee('FOB price in USD')
            ->assertSee('BANK DETAILS')
            ->getContent();

        // Goods + freight, spelled out.
        $weight = (float) DB::connection('bpl')->table('bpl_sales_items')->where('order_id', $order->id)->sum('weight');
        $grand = round($weight + (2 * 500), 2);
        $this->assertStringContainsString(number_format($grand, 2), $html);
        $this->assertStringContainsString('Say USD', $html);
        $this->assertStringContainsString(strtolower(substr(ProformaInvoice::inWords($grand, 'USD'), 0, 6)), strtolower($html));

        // A deleted proforma has no sheet.
        BplProforma::find($order->id)->delete();
        $this->get('/bpl/sales/proformas/' . $order->id . '/print')->assertNotFound();
    }

    /** The account list follows the currency: you cannot point a EUR customer at a USD account. */
    public function test_the_accounts_offered_match_the_chosen_currency(): void
    {
        Livewire::actingAs($this->admin());
        $bpl = DB::connection('bpl');
        $usd = (int) $bpl->table('currencies')->where('code', 'USD')->value('id');
        $eur = (int) $bpl->table('currencies')->where('code', 'EUR')->value('id');

        $c = Livewire::test(Proformas::class)->call('create')->set('currency_id', $usd);

        $offered = $c->instance()->accounts;
        $this->assertNotEmpty($offered);
        foreach ($offered as $a) {
            $this->assertTrue($a->currency_id === null || (int) $a->currency_id === $usd,
                'a ' . $a->currency . ' account was offered for a USD proforma');
        }

        // A euro account is on the euro list and off the dollar one.
        $euroAccount = BplAccount::where('currency_id', $eur)->value('id');
        $this->assertNotNull($euroAccount);
        $this->assertNotContains($euroAccount, $offered->pluck('id')->all());
        $this->assertContains($euroAccount,
            $c->set('currency_id', $eur)->instance()->accounts->pluck('id')->all());
    }

    /** Switching currency drops an account that cannot take it, rather than leaving it selected. */
    public function test_changing_the_currency_clears_a_mismatched_account(): void
    {
        Livewire::actingAs($this->admin());
        $bpl = DB::connection('bpl');
        $usd = (int) $bpl->table('currencies')->where('code', 'USD')->value('id');
        $eur = (int) $bpl->table('currencies')->where('code', 'EUR')->value('id');
        $usdAccount = (int) BplAccount::where('currency_id', $usd)->value('id');

        Livewire::test(Proformas::class)->call('create')
            ->set('currency_id', $usd)
            ->set('account_id', $usdAccount)
            ->set('currency_id', $eur)
            ->assertSet('account_id', null);

        // An account with no currency of its own survives the change.
        $anyAccount = BplAccount::whereNull('currency_id')->value('id');
        if ($anyAccount) {
            Livewire::test(Proformas::class)->call('create')
                ->set('currency_id', $usd)->set('account_id', $anyAccount)
                ->set('currency_id', $eur)
                ->assertSet('account_id', $anyAccount);
        }
    }

    /** An old proforma still shows the account it was raised with, whatever its currency. */
    public function test_editing_keeps_an_account_the_currency_would_now_exclude(): void
    {
        Livewire::actingAs($this->admin());
        $bpl = DB::connection('bpl');

        // 43 live proformas are in EUR against a USD account; open one.
        $orderId = (int) $bpl->table('bpl_proforma as pf')
            ->join('bpl_accounts as a', 'a.id', '=', 'pf.account_id')
            ->whereNull('pf.deleted_at')
            ->whereNotNull('a.currency_id')
            ->whereColumn('a.currency_id', '!=', 'pf.currency_id')
            ->value('pf.order_id');

        if (! $orderId) {
            $this->markTestSkipped('no mismatched proforma in the data');
        }

        $c = Livewire::test(Proformas::class)->call('edit', $orderId);
        $this->assertContains((int) $c->get('account_id'), $c->instance()->accounts->pluck('id')->map(fn ($i) => (int) $i)->all());
    }

    /** The 927 legacy milestone rows are all proforma steps, and gds reads the same ones. */
    public function test_the_existing_milestones_are_read_from_core_actions(): void
    {
        $counts = [
            ProformaMilestones::APPROVED => Action::where('request', 'like', 'pfi_customer%')->count(),
            ProformaMilestones::PRODUCED => Action::where('request', 'like', 'pfi_production%')->count(),
            ProformaMilestones::LOADED => Action::where('request', 'like', 'pfi_loading%')->count(),
        ];

        $this->assertGreaterThan(0, array_sum($counts), 'no milestones in the data at all');

        // Pick a real approved order and check it reads back.
        $request = Action::where('request', 'like', 'pfi_customer%')->value('request');
        $orderId = (int) substr($request, strlen('pfi_customer'));

        $stages = ProformaMilestones::forOrder($orderId);
        $this->assertArrayHasKey(ProformaMilestones::APPROVED, $stages);
        $this->assertNotSame('', $stages[ProformaMilestones::APPROVED]['user']);
    }

    /** Marking a step writes the row the legacy screens read; undoing removes it. */
    public function test_the_stages_can_be_marked_and_taken_back(): void
    {
        Livewire::actingAs($this->admin());
        [, $order] = $this->raise();

        $c = Livewire::test(Proformas::class)->call('openDetails', $order->id)
            ->assertSet('showDetails', true);

        $this->assertSame([], $c->instance()->progress);

        $c->call('markStage', ProformaMilestones::PRODUCED)->call('markStage', ProformaMilestones::LOADED);

        $stages = ProformaMilestones::forOrder($order->id);
        $this->assertArrayHasKey(ProformaMilestones::PRODUCED, $stages);
        $this->assertArrayHasKey(ProformaMilestones::LOADED, $stages);

        // The row is exactly the one the legacy screens join on.
        $this->assertNotNull(Action::where('request', 'pfi_production' . $order->id)->first());
        $this->assertSame(auth()->user()->username, Action::where('request', 'pfi_loading' . $order->id)->value('user'));

        // Marking twice does not duplicate.
        $c->call('markStage', ProformaMilestones::PRODUCED);
        $this->assertSame(1, Action::where('request', 'pfi_production' . $order->id)->count());

        $c->call('unmarkStage', ProformaMilestones::PRODUCED);
        $this->assertSame(0, Action::where('request', 'pfi_production' . $order->id)->count());
    }

    /** An approval an NXP number was issued against cannot be taken back. */
    public function test_an_approval_is_locked_once_the_nxp_is_issued(): void
    {
        Livewire::actingAs($this->admin());
        [, $order] = $this->raise();

        $c = Livewire::test(Proformas::class)->call('openDetails', $order->id)
            ->call('markStage', ProformaMilestones::APPROVED);
        $this->assertSame(1, Action::where('request', 'pfi_customer' . $order->id)->count());

        BplProforma::where('order_id', $order->id)->update(['nxp' => 'ZZ-NXP-LOCK']);

        Livewire::test(Proformas::class)->call('openDetails', $order->id)
            ->call('unmarkStage', ProformaMilestones::APPROVED);

        $this->assertSame(1, Action::where('request', 'pfi_customer' . $order->id)->count(),
            'the approval was taken back after the NXP was issued');
    }

    /** The list shows each proforma's progress without asking per row. */
    public function test_the_grid_shows_the_progress_of_each_row(): void
    {
        Livewire::actingAs($this->admin());
        [, $order] = $this->raise();
        ProformaMilestones::mark(ProformaMilestones::PRODUCED, $order->id, 'tester');

        $c = Livewire::test(Proformas::class)->set('search', $order->orderno);
        $this->assertArrayHasKey($order->id, $c->instance()->milestones);
        $this->assertArrayHasKey(ProformaMilestones::PRODUCED, $c->instance()->milestones[$order->id]);
        $this->assertStringContainsString('Produced', $c->html());
    }

    /** The list carries the scanning columns; the rest of the proforma is in Details. */
    public function test_the_list_leaves_the_detail_to_the_details_card(): void
    {
        Livewire::actingAs($this->admin());
        [, $order] = $this->raise();

        $c = Livewire::test(Proformas::class)->set('search', $order->orderno);
        $labels = array_column($c->instance()->views()['default']['columns'], 0);

        $this->assertSame(['Number', 'Date', 'Customer', 'Goods', 'Freight', 'Lines', 'Progress'], $labels);

        // Shipment, terms, payment, NXP and the currency code moved out…
        foreach (['Shipment', 'Terms', 'Payment', 'NXP', 'Cur'] as $moved) {
            $this->assertNotContains($moved, $labels, $moved . ' should have moved to Details');
        }

        // …into the details card, with the priced lines and the account.
        $details = $c->call('openDetails', $order->id)->html();
        foreach (['Shipment', 'Terms of delivery', 'Payment', 'NXP number', 'Currency',
            'Receiving account', 'Progress'] as $fact) {
            $this->assertStringContainsString($fact, $details, $fact . ' should be in the details card');
        }
        $this->assertStringContainsString(ProformaInvoice::lines($order->id)[0]['fullname'], $details);

        // Searching still finds a proforma by a field the list no longer shows.
        BplProforma::where('order_id', $order->id)->update(['nxp' => 'ZZ-NXP-SEARCH']);
        $found = Livewire::test(Proformas::class)->set('search', 'ZZ-NXP-SEARCH')->viewData('rows');
        $this->assertSame(1, $found->total(), 'search should still cover the hidden columns');
    }

    /** currencies.symbol holds HTML entities; neither the grid nor the sheet may print them raw. */
    public function test_currency_symbols_are_shown_as_characters(): void
    {
        Livewire::actingAs($this->admin());
        [, $order] = $this->raise(1000, 500);

        $this->assertSame('$', ProformaInvoice::symbol('&#36;'));
        $this->assertSame('₦', ProformaInvoice::symbol('&#8358;'));

        $grid = Livewire::test(Proformas::class)->set('search', $order->orderno)->html();
        $this->assertStringNotContainsString('&amp;#36;', $grid, 'the grid is printing the entity itself');
        $this->assertStringContainsString('$', $grid);

        $sheet = $this->actingAs($this->admin())
            ->get('/bpl/sales/proformas/' . $order->id . '/print')->getContent();
        $this->assertStringNotContainsString('&amp;#36;', $sheet);
    }

    /** GBP was missing from the legacy's word list and would have crashed the sheet. */
    public function test_amounts_can_be_spelled_in_every_currency_offered(): void
    {
        $codes = DB::connection('bpl')->table('currencies')->pluck('code');

        foreach ($codes as $code) {
            $said = ProformaInvoice::inWords(1234.56, $code);
            // Capitalised the way the legacy sheet did — ucwords leaves the
            // half after a hyphen alone, and the printed documents read so.
            $this->assertStringContainsString('One Thousand Two Hundred And Thirty-four', $said);
            $this->assertStringContainsString('Fifty-six', $said);
        }
    }
}
