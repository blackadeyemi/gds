<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Finance\Accounts;
use Modules\Bpl\Livewire\Finance\Banks;
use Modules\Bpl\Models\BplAccount;
use Modules\Bpl\Models\BplBank;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Finance → Banks and Accounts.
 *
 * LIVE bpl database: every bank and account this writes is tracked by id and
 * hard-deleted in tearDown. The real ones are only read.
 */
class BplFinancePagesTest extends TestCase
{
    private array $bankIds = [];
    private array $accountIds = [];

    protected function tearDown(): void
    {
        $bpl = DB::connection('bpl');
        if ($this->accountIds) {
            $bpl->table('bpl_accounts')->whereIn('id', $this->accountIds)->delete();
        }
        if ($this->bankIds) {
            $bpl->table('bpl_banks')->whereIn('id', $this->bankIds)->delete();
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    private function bank(string $name, ?string $number): BplBank
    {
        $b = BplBank::create(['name' => $name, 'number' => $number]);
        $this->bankIds[] = $b->id;

        return $b;
    }

    public function test_both_pages_open(): void
    {
        $this->actingAs($this->admin());

        $this->get('/bpl/finance/banks')->assertOk()->assertSee('BPL Banks')->assertSee('Swift Code');
        $this->get('/bpl/finance/accounts')->assertOk()->assertSee('BPL Accounts')->assertSee('Beneficiary Bank');

        // The nav group sits under BPL.
        $this->get('/bpl/finance/banks')->assertSee('Finance')->assertSee(route('bpl.finance.accounts'), false);
    }

    public function test_a_bank_is_added_with_its_codes_kept_as_text(): void
    {
        Livewire::actingAs($this->admin());

        Livewire::test(Banks::class)->call('create')
            ->set('name', 'ZZ Test Bank')->set('number', 'ZZ-0001')
            ->set('sortcode', '044150084')->set('swiftcode', ' zztsng la ')
            ->call('save')->assertHasNoErrors();

        $b = BplBank::where('number', 'ZZ-0001')->firstOrFail();
        $this->bankIds[] = $b->id;
        $this->assertSame('044150084', $b->sortcode, 'the leading zero was lost');
        $this->assertSame('ZZTSNGLA', $b->swiftcode);
        $this->assertNull($b->address, 'an empty address should be stored as NULL');
    }

    public function test_a_duplicate_account_number_is_refused_in_words(): void
    {
        Livewire::actingAs($this->admin());
        $existing = BplBank::whereNotNull('number')->where('number', '<>', '')->firstOrFail();

        Livewire::test(Banks::class)->call('create')
            ->set('name', 'ZZ Another')->set('number', $existing->number)
            ->call('save')->assertHasErrors('number');
    }

    /** Two number-less banks do not collide on the index; a repeat of the same name is caught by hand. */
    public function test_number_less_banks_store_null_and_repeat_names_are_refused(): void
    {
        Livewire::actingAs($this->admin());

        Livewire::test(Banks::class)->call('create')->set('name', 'ZZ Routing A')->call('save')->assertHasNoErrors();
        Livewire::test(Banks::class)->call('create')->set('name', 'ZZ Routing B')->call('save')->assertHasNoErrors();
        $this->bankIds = array_merge($this->bankIds, BplBank::where('name', 'like', 'ZZ Routing%')->pluck('id')->all());

        $this->assertSame(2, BplBank::where('name', 'like', 'ZZ Routing%')->whereNull('number')->count());

        Livewire::test(Banks::class)->call('create')->set('name', 'ZZ Routing A')->call('save')->assertHasErrors('name');
    }

    /** A bank an account names — even a deleted account — cannot be deleted. */
    public function test_a_bank_on_an_account_cannot_be_deleted(): void
    {
        Livewire::actingAs($this->admin());
        $b = $this->bank('ZZ Guarded Bank', 'ZZ-GUARD');
        $a = BplAccount::create(['account' => 'ZZ Acc', 'beneficiary' => $b->id]);
        $this->accountIds[] = $a->id;
        $a->delete();   // soft — still names the bank

        $c = Livewire::test(Banks::class);
        $row = $c->instance()->deleteGuard((object) ['accounts_count' => BplBank::accountsNaming($b->id)]);
        $this->assertStringStartsWith('Named on 1 account', $row);

        $c->set('confirmingDelete', $b->id)->call('deleteConfirmed');
        $this->assertNotNull(BplBank::find($b->id), 'a bank an account names was deleted');

        // Once nothing names it, it goes.
        DB::connection('bpl')->table('bpl_accounts')->where('id', $a->id)->delete();
        Livewire::test(Banks::class)->set('confirmingDelete', $b->id)->call('deleteConfirmed');
        $this->assertNull(BplBank::find($b->id));
    }

    public function test_an_account_is_added_and_listed_with_its_banks(): void
    {
        Livewire::actingAs($this->admin());
        $ben = $this->bank('ZZ Beneficiary', 'ZZ-BEN');
        $int = $this->bank('ZZ Intermediary', null);
        $usd = (int) DB::connection('bpl')->table('currencies')->where('code', 'USD')->value('id');

        Livewire::test(Accounts::class)->call('create')
            ->set('account', 'ZZ Test Account')->set('beneficiary', $ben->id)->set('intermediary', $int->id)
            ->set('further_acc', '0200392673')->set('currency_id', $usd)
            ->call('save')->assertHasNoErrors();

        $a = BplAccount::where('account', 'ZZ Test Account')->firstOrFail();
        $this->accountIds[] = $a->id;
        $this->assertNull($a->correspondent);

        $rows = Livewire::test(Accounts::class)->set('search', 'ZZ Test Account')->viewData('rows');
        $this->assertSame(1, $rows->total());
        $row = $rows->collect()->first();
        $this->assertSame('ZZ Beneficiary', $row->bank);
        $this->assertSame('ZZ Intermediary', $row->intermediary_name);
        $this->assertSame('USD', $row->currency);
    }

    public function test_the_intermediary_cannot_be_the_beneficiary(): void
    {
        Livewire::actingAs($this->admin());
        $ben = $this->bank('ZZ Self', 'ZZ-SELF');

        Livewire::test(Accounts::class)->call('create')
            ->set('account', 'ZZ Loop')->set('beneficiary', $ben->id)->set('intermediary', $ben->id)
            ->call('save')->assertHasErrors('intermediary');
    }

    /** UNIQUE(beneficiary, currency) spans deleted rows: say so and offer the restore. */
    public function test_bank_and_currency_clash_including_a_deleted_account(): void
    {
        Livewire::actingAs($this->admin());
        $ben = $this->bank('ZZ Clash Bank', 'ZZ-CLASH');
        $eur = (int) DB::connection('bpl')->table('currencies')->where('code', 'EUR')->value('id');

        $a = BplAccount::create(['account' => 'ZZ First', 'beneficiary' => $ben->id, 'currency_id' => $eur]);
        $this->accountIds[] = $a->id;

        $new = fn () => Livewire::test(Accounts::class)->call('create')
            ->set('account', 'ZZ Second')->set('beneficiary', $ben->id)->set('currency_id', $eur)->call('save');

        $new()->assertHasErrors('currency_id');

        $a->delete();
        $c = $new()->assertHasErrors('currency_id');
        $this->assertStringContainsString('restore it', $c->errors()->first('currency_id'));

        // …and restoring works from the Deleted view.
        Livewire::test(Accounts::class)->call('switchView', 'deleted')->call('restore', $a->id);
        $this->assertFalse($a->fresh()->trashed());
    }

    public function test_deleting_an_account_is_soft(): void
    {
        Livewire::actingAs($this->admin());
        $ben = $this->bank('ZZ Soft Bank', 'ZZ-SOFT');
        $a = BplAccount::create(['account' => 'ZZ Soft', 'beneficiary' => $ben->id]);
        $this->accountIds[] = $a->id;

        Livewire::test(Accounts::class)->set('confirmingDelete', $a->id)->call('deleteConfirmed');

        $this->assertNull(BplAccount::find($a->id));
        $this->assertNotNull(BplAccount::withTrashed()->find($a->id));

        $rows = Livewire::test(Accounts::class)->call('switchView', 'deleted')->set('search', 'ZZ Soft')->viewData('rows');
        $this->assertSame(1, $rows->total());
    }

    /** An account naming a bank that was deleted from under it opens with the bank cleared and a note. */
    public function test_a_missing_bank_is_cleared_on_edit_with_a_note(): void
    {
        Livewire::actingAs($this->admin());
        $ben = $this->bank('ZZ Orphan Ben', 'ZZ-ORPH');
        $gone = (int) BplBank::max('id') + 1000;
        $id = DB::connection('bpl')->table('bpl_accounts')->insertGetId([
            'account' => 'ZZ Orphaned', 'beneficiary' => $ben->id, 'intermediary' => $gone,
        ]);
        $this->accountIds[] = $id;

        $html = Livewire::test(Accounts::class)->set('search', 'ZZ Orphaned')->html();
        $this->assertStringContainsString('missing bank #' . $gone, $html);

        Livewire::test(Accounts::class)->call('edit', $id)
            ->assertSet('intermediary', null)
            ->assertSet('beneficiary', $ben->id)
            ->assertSee('no longer exists')
            ->call('save')->assertHasNoErrors();

        $this->assertNull(BplAccount::find($id)->intermediary);
    }
}
