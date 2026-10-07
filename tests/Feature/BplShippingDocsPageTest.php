<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Finance\InvoicePayments;
use Modules\Bpl\Livewire\Logistics\BillsOfLading;
use Modules\Bpl\Models\BplBillOfLading;
use Modules\Bpl\Models\BplInvoicePayment;
use Modules\Bpl\Models\BplPackingList;
use Modules\Bpl\Support\BillOfLadingDocs;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Logistics → Bills of Lading, and BPL → Finance → Invoice Payments.
 *
 * LIVE bpl database: both tests work against a packing list they create
 * themselves, and tearDown removes the packing list, its bill of lading, its
 * payment and any uploaded document. The 279 real bills and 332 real payments
 * are only read.
 */
class BplShippingDocsPageTest extends TestCase
{
    private array $packingIds = [];
    private array $documents = [];

    protected function tearDown(): void
    {
        $bpl = DB::connection('bpl');

        if ($this->packingIds) {
            $bpl->table('bpl_bill_of_lading')->whereIn('packing_id', $this->packingIds)->delete();
            $bpl->table('bpl_invoice_payments')->whereIn('packing_id', $this->packingIds)->delete();
            $bpl->table('bpl_packing_list')->whereIn('id', $this->packingIds)->delete();
        }

        foreach ($this->documents as $name) {
            BillOfLadingDocs::delete($name);
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    /** A packing list of our own, copied from a real one so it has containers. */
    private function shipment(): BplPackingList
    {
        $source = DB::connection('bpl')->table('bpl_packing_list')->orderByDesc('id')->first();
        $this->assertNotNull($source);

        $packing = BplPackingList::create([
            'order_id' => $source->order_id,
            'number' => 'ZZ/TEST/' . uniqid(),
            'containers' => json_decode($source->containers, true),
            'date' => now()->format('Y/m/d'),
            'split' => '0',
        ]);

        $this->packingIds[] = $packing->id;

        return $packing;
    }

    private function pdf(string $name = 'bl.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nZZ test bill of lading\n%%EOF");
    }

    /* ---------------- Bills of lading ---------------- */

    public function test_the_bill_of_lading_page_opens_under_logistics(): void
    {
        $this->actingAs($this->admin())->get('/bpl/logistics/bills-of-lading')
            ->assertOk()
            ->assertSee('BPL Bills of Lading')
            ->assertSee('In transit');
    }

    public function test_a_bill_of_lading_is_raised_with_its_scan(): void
    {
        Livewire::actingAs($this->admin());
        $packing = $this->shipment();

        Livewire::test(BillsOfLading::class)->call('create')
            ->set('packing_id', $packing->id)
            ->set('number', 'ZZBL0001')
            ->set('shippedIso', now()->subDays(5)->format('Y-m-d'))
            ->set('issuedIso', now()->subDays(3)->format('Y-m-d'))
            ->set('document', $this->pdf())
            ->call('save')->assertHasNoErrors();

        $bl = BplBillOfLading::where('packing_id', $packing->id)->firstOrFail();
        $this->documents[] = $bl->doc;

        $this->assertSame('ZZBL0001', $bl->number);
        $this->assertSame(now()->subDays(3)->format('Y/m/d'), $bl->issued_date);
        $this->assertTrue($bl->inTransit(), 'no arrival date yet');
        $this->assertTrue(BillOfLadingDocs::exists($bl->doc), 'the scan was not stored');

        // Named after the packing list, as the legacy named it.
        $this->assertStringStartsWith('ZZ-TEST-', $bl->doc);

        // …and it streams back through the gated route.
        $this->actingAs($this->admin())
            ->get('/bpl/logistics/bills-of-lading/' . $bl->id . '/document')
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename="' . $bl->doc . '"');
    }

    /** The packing list is frozen by its bill of lading — the rule both apps share. */
    public function test_a_shipment_takes_one_bill_of_lading(): void
    {
        Livewire::actingAs($this->admin());
        $packing = $this->shipment();

        Livewire::test(BillsOfLading::class)->call('create')
            ->set('packing_id', $packing->id)->set('number', 'ZZBL0002')
            ->set('shippedIso', now()->subDay()->format('Y-m-d'))->set('issuedIso', now()->format('Y-m-d'))
            ->set('document', $this->pdf())->call('save')->assertHasNoErrors();
        $this->documents[] = BplBillOfLading::where('packing_id', $packing->id)->value('doc');

        // A second one against the same shipment is refused…
        Livewire::test(BillsOfLading::class)->call('create')
            ->set('packing_id', $packing->id)->set('number', 'ZZBL0003')
            ->set('shippedIso', now()->subDay()->format('Y-m-d'))->set('issuedIso', now()->format('Y-m-d'))
            ->set('document', $this->pdf())->call('save')->assertHasErrors('packing_id');

        // …and so is a repeated B/L number.
        $other = $this->shipment();
        Livewire::test(BillsOfLading::class)->call('create')
            ->set('packing_id', $other->id)->set('number', 'ZZBL0002')
            ->set('shippedIso', now()->subDay()->format('Y-m-d'))->set('issuedIso', now()->format('Y-m-d'))
            ->set('document', $this->pdf())->call('save')->assertHasErrors('number');

        $this->assertSame(1, BplBillOfLading::where('number', 'ZZBL0002')->count());
    }

    public function test_the_dates_have_to_make_sense(): void
    {
        Livewire::actingAs($this->admin());
        $packing = $this->shipment();

        $base = fn () => Livewire::test(BillsOfLading::class)->call('create')
            ->set('packing_id', $packing->id)->set('number', 'ZZBL0004')
            ->set('document', $this->pdf());

        // Issued before it shipped.
        $base()->set('shippedIso', now()->format('Y-m-d'))
            ->set('issuedIso', now()->subDays(2)->format('Y-m-d'))
            ->call('save')->assertHasErrors('shippedIso');

        // Arrived before it shipped.
        $base()->set('shippedIso', now()->subDay()->format('Y-m-d'))
            ->set('issuedIso', now()->format('Y-m-d'))
            ->set('arrivalIso', now()->subDays(5)->format('Y-m-d'))
            ->call('save')->assertHasErrors('arrivalIso');

        $this->assertNull(BplBillOfLading::where('packing_id', $packing->id)->first());
    }

    /** Correcting a date keeps the scan; a replacement only lands once it is saved. */
    public function test_an_edit_keeps_the_existing_scan_unless_a_new_one_is_given(): void
    {
        Livewire::actingAs($this->admin());
        $packing = $this->shipment();

        Livewire::test(BillsOfLading::class)->call('create')
            ->set('packing_id', $packing->id)->set('number', 'ZZBL0005')
            ->set('shippedIso', now()->subDays(4)->format('Y-m-d'))->set('issuedIso', now()->subDays(2)->format('Y-m-d'))
            ->set('document', $this->pdf())->call('save')->assertHasNoErrors();

        $bl = BplBillOfLading::where('packing_id', $packing->id)->firstOrFail();
        $original = $bl->doc;
        $this->documents[] = $original;

        // No file on the form: the record keeps the one it has.
        Livewire::test(BillsOfLading::class)->call('edit', $bl->id)
            ->set('arrivalIso', now()->format('Y-m-d'))
            ->call('save')->assertHasNoErrors();

        $bl->refresh();
        $this->assertSame($original, $bl->doc);
        $this->assertTrue(BillOfLadingDocs::exists($original));
        $this->assertFalse($bl->inTransit());

        // A replacement is written, and only then is the old one removed.
        Livewire::test(BillsOfLading::class)->call('edit', $bl->id)
            ->set('document', $this->pdf('replacement.pdf'))
            ->call('save')->assertHasNoErrors();

        $bl->refresh();
        $this->documents[] = $bl->doc;
        $this->assertNotSame($original, $bl->doc);
        $this->assertTrue(BillOfLadingDocs::exists($bl->doc));
        $this->assertFalse(BillOfLadingDocs::exists($original), 'the replaced scan should be gone');
    }

    /* ---------------- Invoice payments ---------------- */

    public function test_the_invoice_payments_page_opens_under_finance(): void
    {
        $this->actingAs($this->admin())->get('/bpl/finance/invoice-payments')
            ->assertOk()
            ->assertSee('BPL Invoice Payments')
            ->assertSee('Nothing paid');
    }

    /** Every shipment is listed, with what it is worth and what has been paid. */
    public function test_a_shipment_shows_its_invoice_and_its_payment(): void
    {
        Livewire::actingAs($this->admin());
        $packing = $this->shipment();

        $c = Livewire::test(InvoicePayments::class)->set('search', $packing->number);
        $row = $c->viewData('rows')->collect()->first();
        $this->assertNotNull($row, 'the shipment should be listed even with nothing paid');
        $this->assertNull($row->payment_id);

        $invoice = $c->instance()->invoiceValue($row);
        $this->assertGreaterThan(0, $invoice, 'the shipment should carry a final invoice value');

        // Pay half of it.
        $half = round($invoice / 2, 2);
        Livewire::test(InvoicePayments::class)->call('edit', $packing->id)
            ->set('amount', (string) $half)
            ->call('save')->assertHasNoErrors();

        $payment = BplInvoicePayment::where('packing_id', $packing->id)->firstOrFail();
        $this->assertEqualsWithDelta($half, (float) $payment->amount, 0.01);
        $this->assertSame(now()->format('Y/m/d'), $payment->date);

        // Paying again REPLACES the figure — one row per shipment, as legacy had it.
        Livewire::test(InvoicePayments::class)->call('edit', $packing->id)
            ->set('amount', (string) $invoice)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(1, BplInvoicePayment::where('packing_id', $packing->id)->count());
        $this->assertEqualsWithDelta($invoice, (float) $payment->fresh()->amount, 0.01);
    }

    public function test_a_payment_cannot_exceed_the_invoice(): void
    {
        Livewire::actingAs($this->admin());
        $packing = $this->shipment();

        $c = Livewire::test(InvoicePayments::class)->set('search', $packing->number);
        $invoice = $c->instance()->invoiceValue($c->viewData('rows')->collect()->first());

        $over = Livewire::test(InvoicePayments::class)->call('edit', $packing->id)
            ->set('amount', (string) round($invoice * 2, 2))
            ->call('save')->assertHasErrors('amount');
        $this->assertStringContainsString('more than the invoice', $over->errors()->first('amount'));

        $this->assertNull(BplInvoicePayment::where('packing_id', $packing->id)->first());
    }

    /** Only a shipment with a payment can have one removed. */
    public function test_a_payment_can_be_removed_and_an_empty_one_cannot(): void
    {
        Livewire::actingAs($this->admin());
        $packing = $this->shipment();

        $c = Livewire::test(InvoicePayments::class);
        $unpaid = $c->set('search', $packing->number)->viewData('rows')->collect()->first();
        $this->assertSame('Nothing has been paid against this shipment.', $c->instance()->deleteGuard($unpaid));

        Livewire::test(InvoicePayments::class)->call('edit', $packing->id)->set('amount', '100')->call('save');
        $this->assertNotNull(BplInvoicePayment::where('packing_id', $packing->id)->first());

        Livewire::test(InvoicePayments::class)->set('confirmingDelete', $packing->id)->call('deleteConfirmed');
        $this->assertNull(BplInvoicePayment::where('packing_id', $packing->id)->first());
    }
}
