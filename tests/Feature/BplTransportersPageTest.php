<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Sales\Transporters;
use Modules\Bpl\Models\BplTransporter;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Sales → Transporters.
 *
 * LIVE bpl database: every transporter this writes is tracked by id and
 * hard-deleted in tearDown. The three real ones are only read.
 */
class BplTransportersPageTest extends TestCase
{
    private array $ids = [];

    protected function tearDown(): void
    {
        if ($this->ids) {
            BplTransporter::whereIn('id', $this->ids)->delete();
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    public function test_the_page_opens(): void
    {
        $this->actingAs($this->admin())->get('/bpl/sales/transporters')
            ->assertOk()
            ->assertSee('BPL Transporters')
            ->assertSee('Transporter Code');
    }

    /** Every existing transporter got a code, and they are all distinct. */
    public function test_every_transporter_has_a_unique_eight_digit_code(): void
    {
        $codes = BplTransporter::pluck('transportercode');

        $this->assertNotContains(null, $codes->all(), 'a transporter was left without a code');
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[1-9]\d{7}$/', $code);
        }
        $this->assertSame($codes->count(), $codes->unique()->count());
    }

    /** A new transporter gets a code it did not type; an edit cannot change it. */
    public function test_the_code_is_system_assigned_and_fixed(): void
    {
        Livewire::actingAs($this->admin());

        Livewire::test(Transporters::class)->call('create')
            ->set('transportername', 'ZZ Test Haulage')
            ->call('save')->assertHasNoErrors();

        $t = BplTransporter::where('transportername', 'ZZ Test Haulage')->firstOrFail();
        $this->ids[] = $t->id;
        $this->assertMatchesRegularExpression('/^[1-9]\d{7}$/', $t->transportercode);

        $original = $t->transportercode;

        // Even a tampered property cannot reach the column: it is not in $data.
        Livewire::test(Transporters::class)->call('edit', $t->id)
            ->set('transportername', 'ZZ Test Haulage Renamed')
            ->set('transportercode', '99999999')
            ->call('save')->assertHasNoErrors();

        $t->refresh();
        $this->assertSame('ZZ Test Haulage Renamed', $t->transportername);
        $this->assertSame($original, $t->transportercode);
    }

    /** A row the legacy screen created (no code) gets one the first time gds saves it. */
    public function test_a_legacy_row_is_given_a_code_on_save(): void
    {
        Livewire::actingAs($this->admin());

        // What the legacy INSERT does: name only.
        $id = DB::connection('bpl')->table('bpl_transporters')->insertGetId(['transportername' => 'ZZ Legacy Haulier']);
        $this->ids[] = $id;
        $this->assertNull(BplTransporter::find($id)->transportercode);

        Livewire::test(Transporters::class)->call('edit', $id)->call('save')->assertHasNoErrors();

        $this->assertMatchesRegularExpression('/^[1-9]\d{7}$/', (string) BplTransporter::find($id)->transportercode);
    }

    public function test_a_duplicate_name_is_refused_in_words(): void
    {
        Livewire::actingAs($this->admin());

        $existing = BplTransporter::firstOrFail()->transportername;

        Livewire::test(Transporters::class)->call('create')
            ->set('transportername', $existing)
            ->call('save')
            ->assertHasErrors('transportername');
    }

    /** A transporter named on a waybill payment cannot be deleted. */
    public function test_a_paid_transporter_cannot_be_deleted(): void
    {
        Livewire::actingAs($this->admin());

        $paidId = (int) DB::connection('bpl')->table('bpl_waybill_payment')->value('transporter_id');
        $this->assertGreaterThan(0, $paidId, 'no waybill payments to test against');

        $c = Livewire::test(Transporters::class);
        $row = BplTransporter::withCount('waybillPayments')->find($paidId);
        $this->assertStringStartsWith('Named on', $c->instance()->deleteGuard($row));

        $c->set('confirmingDelete', $paidId)->call('deleteConfirmed');
        $this->assertNotNull(BplTransporter::find($paidId), 'a paid transporter was deleted');
    }

    /** "Never used" lists exactly the transporters with no payments — the deletable ones. */
    public function test_never_used_lists_only_unpaid_transporters(): void
    {
        Livewire::actingAs($this->admin());

        $expected = BplTransporter::doesntHave('waybillPayments')->count();
        $rows = Livewire::test(Transporters::class)->call('switchView', 'unused')->viewData('rows');

        $this->assertSame($expected, $rows->total());
        foreach ($rows->collect() as $r) {
            $this->assertSame(0, (int) $r->waybill_payments_count);
        }
    }
}
