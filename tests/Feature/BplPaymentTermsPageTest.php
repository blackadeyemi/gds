<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Finance\PaymentTerms;
use Modules\Bpl\Models\BplPaymentTerm;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Finance → Payment Terms.
 *
 * LIVE bpl database: every term this writes is tracked by id and hard-deleted
 * in tearDown. The 14 real ones are only read.
 */
class BplPaymentTermsPageTest extends TestCase
{
    private array $ids = [];

    protected function tearDown(): void
    {
        if ($this->ids) {
            DB::connection('bpl')->table('bpl_payment_terms')->whereIn('id', $this->ids)->delete();
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    public function test_the_page_opens_under_finance(): void
    {
        $this->actingAs($this->admin())->get('/bpl/finance/payment-terms')
            ->assertOk()
            ->assertSee('BPL Payment Terms')
            ->assertSee('Payment Term')
            ->assertSee('Days');
    }

    /** Active terms are listed with how many proformas use each. */
    public function test_the_list_counts_proformas_and_hides_deleted_terms(): void
    {
        Livewire::actingAs($this->admin());

        $rows = Livewire::test(PaymentTerms::class)->viewData('rows');
        $this->assertSame(BplPaymentTerm::count(), $rows->total());

        $row = $rows->collect()->firstWhere('payment_terms', '30 Days After BL');
        $this->assertNotNull($row);
        $expected = DB::connection('bpl')->table('bpl_proforma')
            ->where('payment_term_id', $row->id)->whereNull('deleted_at')->count();
        $this->assertSame($expected, (int) $row->proformas_count);

        // Term 8 was deleted in 2021 and a proforma still carries it.
        $deleted = Livewire::test(PaymentTerms::class)->call('switchView', 'deleted')->viewData('rows');
        $this->assertSame(BplPaymentTerm::onlyTrashed()->count(), $deleted->total());
        $this->assertNotContains('1 Month After BL', $rows->collect()->pluck('payment_terms')->all());
    }

    public function test_a_term_is_added_with_its_days(): void
    {
        Livewire::actingAs($this->admin());

        Livewire::test(PaymentTerms::class)->call('create')
            ->set('payment_terms', '  ZZ Test   Term  ')->set('days', 45)
            ->call('save')->assertHasNoErrors();

        $t = BplPaymentTerm::where('payment_terms', 'ZZ Test Term')->firstOrFail();
        $this->ids[] = $t->id;
        $this->assertSame(45, $t->days, 'the days were not stored');
    }

    public function test_days_must_be_at_least_one(): void
    {
        Livewire::actingAs($this->admin());

        Livewire::test(PaymentTerms::class)->call('create')
            ->set('payment_terms', 'ZZ Zero Days')->set('days', 0)
            ->call('save')->assertHasErrors('days');

        Livewire::test(PaymentTerms::class)->call('create')
            ->set('payment_terms', 'ZZ No Days')
            ->call('save')->assertHasErrors('days');

        $this->assertSame(0, BplPaymentTerm::withTrashed()->where('payment_terms', 'like', 'ZZ%')->count());
    }

    /** UNIQUE(payment_terms) spans deleted rows: say so, and point at the restore. */
    public function test_a_repeated_term_is_refused_and_a_deleted_twin_is_restorable(): void
    {
        Livewire::actingAs($this->admin());

        $existing = BplPaymentTerm::firstOrFail()->payment_terms;
        Livewire::test(PaymentTerms::class)->call('create')
            ->set('payment_terms', $existing)->set('days', 30)
            ->call('save')->assertHasErrors('payment_terms');

        // A deleted twin blocks it too — the message says to restore instead.
        $t = BplPaymentTerm::create(['payment_terms' => 'ZZ Retired Term', 'days' => 60]);
        $this->ids[] = $t->id;
        $t->delete();

        $c = Livewire::test(PaymentTerms::class)->call('create')
            ->set('payment_terms', 'ZZ Retired Term')->set('days', 60)
            ->call('save')->assertHasErrors('payment_terms');
        $this->assertStringContainsString('restore', $c->errors()->first('payment_terms'));

        Livewire::test(PaymentTerms::class)->call('switchView', 'deleted')->call('restore', $t->id);
        $this->assertFalse($t->fresh()->trashed());
    }

    /** Editing keeps the term's own name available to itself. */
    public function test_a_term_can_be_edited_without_clashing_with_itself(): void
    {
        Livewire::actingAs($this->admin());
        $t = BplPaymentTerm::create(['payment_terms' => 'ZZ Editable', 'days' => 30]);
        $this->ids[] = $t->id;

        Livewire::test(PaymentTerms::class)->call('edit', $t->id)
            ->assertSet('payment_terms', 'ZZ Editable')
            ->assertSet('days', 30)
            ->set('days', 90)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(90, $t->fresh()->days);
    }

    public function test_deleting_a_term_is_soft(): void
    {
        Livewire::actingAs($this->admin());
        $t = BplPaymentTerm::create(['payment_terms' => 'ZZ Soft Term', 'days' => 30]);
        $this->ids[] = $t->id;

        Livewire::test(PaymentTerms::class)->set('confirmingDelete', $t->id)->call('deleteConfirmed');

        $this->assertNull(BplPaymentTerm::find($t->id));
        $this->assertNotNull(BplPaymentTerm::withTrashed()->find($t->id)->deleted_at);

        // …and a deleted term cannot be deleted again.
        $c = Livewire::test(PaymentTerms::class);
        $this->assertSame('Already deleted.', $c->instance()->deleteGuard(BplPaymentTerm::withTrashed()->find($t->id)));
    }
}
