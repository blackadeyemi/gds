<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Finance\WaybillPayments;
use Modules\Bpl\Models\BplTransporter;
use Modules\Bpl\Models\BplWaybillPayment;
use Modules\Bpl\Models\BplWaybillPaymentHistory;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Finance → Waybill Payments.
 *
 * LIVE bpl database: every payment record and history row this writes is
 * tracked by id and hard-deleted in tearDown. The 16 real records are only
 * read.
 */
class BplWaybillPaymentsPageTest extends TestCase
{
    private array $paymentIds = [];

    protected function tearDown(): void
    {
        if ($this->paymentIds) {
            BplWaybillPaymentHistory::whereIn('waybill_payment_id', $this->paymentIds)->delete();
            BplWaybillPayment::whereIn('id', $this->paymentIds)->delete();
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    /** A waybill with no payment record yet. */
    private function freeWaybill(): string
    {
        $number = DB::connection('bpl')->table('bpl_delivery_barcode as w')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_waybill_payment as p')
                ->whereColumn('p.barcode', 'w.delivery_barcode'))
            ->orderByDesc('w.id')->value('w.delivery_barcode');

        $this->assertNotNull($number);

        return $number;
    }

    private function transporter(): BplTransporter
    {
        return BplTransporter::orderBy('id')->firstOrFail();
    }

    /** Raise a record through the page and track it. */
    private function raise(float $amount = 500000, array $first = []): BplWaybillPayment
    {
        $number = $this->freeWaybill();

        $c = Livewire::test(WaybillPayments::class)->call('create')
            ->set('barcode', $number)
            ->set('transporter_id', $this->transporter()->id)
            ->set('amount', (string) $amount);

        if ($first !== []) {
            $c->set('firstAmount', (string) $first['amount'])
                ->set('firstDate', $first['date'] ?? now()->format('Y-m-d'))
                ->set('firstNote', $first['note'] ?? 'ZZ-VOUCHER');
        }

        $c->call('save')->assertHasNoErrors();

        $payment = BplWaybillPayment::where('barcode', $number)->firstOrFail();
        $this->paymentIds[] = $payment->id;

        return $payment;
    }

    public function test_the_page_opens_under_finance(): void
    {
        $this->actingAs($this->admin())->get('/bpl/finance/waybill-payments')
            ->assertOk()
            ->assertSee('BPL Waybill Payments')
            ->assertSee('Outstanding')
            ->assertSee('Transporter');
    }

    /** The repaired status column: it used to say "Paid" for everything. */
    public function test_every_existing_record_has_a_status_its_amounts_agree_with(): void
    {
        foreach (BplWaybillPayment::all() as $p) {
            $this->assertSame(
                BplWaybillPayment::statusFor((float) $p->amount_paid, (float) $p->balance),
                $p->status,
                'waybill ' . $p->barcode . ' carries a status its amounts do not support'
            );
        }
    }

    /** What has been paid is the sum of the history rows, for every legacy record. */
    public function test_paid_matches_the_receipts_on_every_record(): void
    {
        foreach (BplWaybillPayment::all() as $p) {
            $this->assertEqualsWithDelta(
                (float) BplWaybillPaymentHistory::where('waybill_payment_id', $p->id)->sum('amount'),
                (float) $p->amount_paid,
                0.01,
                'waybill ' . $p->barcode . ' has drifted from its receipts'
            );
        }
    }

    public function test_a_record_is_raised_against_a_waybill(): void
    {
        Livewire::actingAs($this->admin());
        $payment = $this->raise(750000);

        $this->assertSame(750000.0, (float) $payment->amount);
        $this->assertSame(0.0, (float) $payment->amount_paid);
        $this->assertSame(750000.0, (float) $payment->balance);
        $this->assertSame(BplWaybillPayment::OUTSTANDING, $payment->status);

        // Customer and truck are copied off the delivery.
        $delivery = DB::connection('bpl')->table('bpl_delivery_details as d')
            ->leftJoin('bpl_delivery as f', 'f.id', '=', 'd.bpl_delivery_id')
            ->where('d.delivery_barcode', $payment->barcode)->first(['f.customer_name', 'f.vechile_number']);
        $this->assertSame($delivery->customer_name, $payment->customer_name);
    }

    public function test_a_first_payment_can_be_recorded_with_the_record(): void
    {
        Livewire::actingAs($this->admin());
        $payment = $this->raise(400000, ['amount' => 150000, 'note' => 'ZZ-001']);

        $this->assertSame(150000.0, (float) $payment->amount_paid);
        $this->assertSame(250000.0, (float) $payment->balance);
        $this->assertSame(BplWaybillPayment::PARTIAL, $payment->status);
        $this->assertSame(1, BplWaybillPaymentHistory::where('waybill_payment_id', $payment->id)->count());
    }

    public function test_a_waybill_takes_only_one_record_and_must_exist(): void
    {
        Livewire::actingAs($this->admin());
        $payment = $this->raise();

        Livewire::test(WaybillPayments::class)->call('create')
            ->set('barcode', $payment->barcode)
            ->set('transporter_id', $this->transporter()->id)
            ->set('amount', '100000')
            ->call('save')->assertHasErrors('barcode');

        Livewire::test(WaybillPayments::class)->call('create')
            ->set('barcode', 'ZZ-NO-SUCH-WAYBILL')
            ->set('transporter_id', $this->transporter()->id)
            ->set('amount', '100000')
            ->call('save')->assertHasErrors('barcode');

        $this->assertSame(0, BplWaybillPayment::where('barcode', 'ZZ-NO-SUCH-WAYBILL')->count());
    }

    /** Paying moves the record's own figures, and finishing it marks it Paid. */
    public function test_payments_move_the_balance_and_close_the_record(): void
    {
        Livewire::actingAs($this->admin());
        $payment = $this->raise(300000);

        $c = Livewire::test(WaybillPayments::class)->call('openPayments', $payment->id)
            ->set('historyAmount', '100000')->set('historyNote', 'ZZ-A')
            ->call('savePayment');

        $payment->refresh();
        $this->assertSame(100000.0, (float) $payment->amount_paid);
        $this->assertSame(200000.0, (float) $payment->balance);
        $this->assertSame(BplWaybillPayment::PARTIAL, $payment->status);

        $c->set('historyAmount', '200000')->set('historyNote', 'ZZ-B')->call('savePayment')->assertHasNoErrors();

        $payment->refresh();
        $this->assertSame(300000.0, (float) $payment->amount_paid);
        $this->assertSame(0.0, (float) $payment->balance);
        $this->assertSame(BplWaybillPayment::PAID, $payment->status);
    }

    /** The guard the legacy meant to have: it compared an undefined variable. */
    public function test_paying_more_than_is_outstanding_is_refused(): void
    {
        Livewire::actingAs($this->admin());
        $payment = $this->raise(200000);

        $c = Livewire::test(WaybillPayments::class)->call('openPayments', $payment->id)
            ->set('historyAmount', '250000')->call('savePayment')->assertHasErrors('historyAmount');
        $this->assertStringContainsString('still outstanding', $c->errors()->first('historyAmount'));

        $this->assertSame(0.0, (float) $payment->fresh()->amount_paid);
        $this->assertSame(0, BplWaybillPaymentHistory::where('waybill_payment_id', $payment->id)->count());
    }

    /** Correcting and removing a payment both re-derive the record. */
    public function test_a_payment_can_be_corrected_or_removed(): void
    {
        Livewire::actingAs($this->admin());
        $payment = $this->raise(500000, ['amount' => 200000, 'note' => 'ZZ-X']);
        $historyId = (int) BplWaybillPaymentHistory::where('waybill_payment_id', $payment->id)->value('id');

        $c = Livewire::test(WaybillPayments::class)->call('openPayments', $payment->id)
            ->call('editPayment', $historyId)
            ->assertSet('historyAmount', '200000')
            ->set('historyAmount', '350000')
            ->call('savePayment')->assertHasNoErrors();

        $this->assertSame(350000.0, (float) $payment->fresh()->amount_paid);

        $c->call('deletePayment', $historyId);

        $payment->refresh();
        $this->assertSame(0.0, (float) $payment->amount_paid);
        $this->assertSame(500000.0, (float) $payment->balance);
        $this->assertSame(BplWaybillPayment::OUTSTANDING, $payment->status);
    }

    public function test_the_agreed_amount_cannot_fall_below_what_was_paid(): void
    {
        Livewire::actingAs($this->admin());
        $payment = $this->raise(500000, ['amount' => 300000]);

        Livewire::test(WaybillPayments::class)->call('edit', $payment->id)
            ->set('amount', '250000')->call('save')->assertHasErrors('amount');

        $this->assertSame(500000.0, (float) $payment->fresh()->amount);

        // Raising it is fine, and the balance follows.
        Livewire::test(WaybillPayments::class)->call('edit', $payment->id)
            ->set('amount', '600000')->call('save')->assertHasNoErrors();
        $this->assertSame(300000.0, (float) $payment->fresh()->balance);
    }

    /** A record with money against it is not deleted; an empty one is. */
    public function test_deleting_is_guarded_by_what_has_been_paid(): void
    {
        Livewire::actingAs($this->admin());
        $paid = $this->raise(100000, ['amount' => 50000]);

        $c = Livewire::test(WaybillPayments::class);
        $this->assertStringStartsWith('Already paid', $c->instance()->deleteGuard($paid->fresh()));

        $c->set('confirmingDelete', $paid->id)->call('deleteConfirmed');
        $this->assertNotNull(BplWaybillPayment::find($paid->id), 'a paid record was deleted');

        $empty = $this->raise(100000);
        Livewire::test(WaybillPayments::class)->set('confirmingDelete', $empty->id)->call('deleteConfirmed');
        $this->assertNull(BplWaybillPayment::find($empty->id));
    }

    /** The status views split the records the way the amounts say. */
    public function test_the_views_and_totals_follow_the_amounts(): void
    {
        Livewire::actingAs($this->admin());
        $this->raise(100000);                              // outstanding
        $this->raise(100000, ['amount' => 40000]);         // part paid

        foreach ([
            'outstanding' => BplWaybillPayment::OUTSTANDING,
            'partial' => BplWaybillPayment::PARTIAL,
            'paid' => BplWaybillPayment::PAID,
        ] as $view => $status) {
            $rows = Livewire::test(WaybillPayments::class)->call('switchView', $view)->viewData('rows');
            $this->assertSame(BplWaybillPayment::where('status', $status)->count(), $rows->total());

            foreach ($rows->collect() as $row) {
                $this->assertSame($status, $row->status);
            }
        }

        // The footer totals what the view holds (formatted, so the commas go).
        $totals = Livewire::test(WaybillPayments::class)->call('switchView', 'outstanding')->viewData('totals');
        $this->assertEqualsWithDelta(
            (float) BplWaybillPayment::where('status', BplWaybillPayment::OUTSTANDING)->sum('balance'),
            (float) str_replace(',', '', (string) $totals['balance']),
            0.01
        );
    }

    /** Only waybills without a record are offered, so one cannot be raised twice. */
    public function test_the_waybill_picker_offers_only_free_waybills(): void
    {
        Livewire::actingAs($this->admin());
        $payment = $this->raise();

        $offered = Livewire::test(WaybillPayments::class)->instance()->openWaybills
            ->pluck('delivery_barcode')->all();

        $this->assertNotContains($payment->barcode, $offered);
    }
}
