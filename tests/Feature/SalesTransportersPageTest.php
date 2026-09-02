<?php

namespace Tests\Feature;

use Modules\Bil\Livewire\Sales\Transporters;
use Modules\Bil\Models\SalesTransporter;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * Read-only HTTP checks for BIL → Sales → Transporters, plus the code contract.
 * Nothing is written.
 */
class SalesTransportersPageTest extends TestCase
{
    protected function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u, 'no admin user in core.user');

        return $u;
    }

    protected function outsider(): User
    {
        $u = User::whereDoesntHave('roles', fn ($q) => $q->where('legacy_level', 1))
            ->get()
            ->first(fn (User $u) => ! $u->canAccessPage('bil.sales.transporters'));
        $this->assertNotNull($u, 'no non-admin user without the page');

        return $u;
    }

    public function test_admin_can_open_the_page(): void
    {
        $res = $this->actingAs($this->admin())->get('/bil/sales/transporters');

        $res->assertOk();
        $res->assertSee('Sales Transporters');
        $res->assertSee('Transporter Code');
    }

    public function test_every_declared_view_renders(): void
    {
        \Livewire\Livewire::actingAs($this->admin());

        foreach (array_keys((new Transporters())->views()) as $key) {
            \Livewire\Livewire::test(Transporters::class)->call('switchView', $key)->assertOk();
        }
    }

    /** Every transporter carries a unique 8-digit code, and the DB enforces it. */
    public function test_the_transporter_code_contract_holds(): void
    {
        $codes = SalesTransporter::pluck('transportercode');

        $this->assertNotEmpty($codes);
        $this->assertCount($codes->count(), $codes->unique(), 'codes must be unique');

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[1-9][0-9]{7}$/', (string) $code);
        }

        $this->assertNotEmpty(
            \Illuminate\Support\Facades\DB::connection('bil')
                ->select("SHOW INDEX FROM sales_transporters WHERE Key_name = 'st_code_unq'"),
            'the UNIQUE index is the real guarantee, not the generator'
        );
    }

    public function test_generated_codes_are_free_and_not_sequential(): void
    {
        $a = SalesTransporter::generateCode();
        $b = SalesTransporter::generateCode();

        $this->assertMatchesRegularExpression('/^[1-9][0-9]{7}$/', $a);
        $this->assertNotSame($a, $b);
        $this->assertFalse(SalesTransporter::where('transportercode', $a)->exists());
    }

    /** 141 of 143 have carried something; deleting one would orphan loadings. */
    public function test_a_transporter_with_loadings_cannot_be_deleted(): void
    {
        $used = SalesTransporter::withCount('loadings')->having('loadings_count', '>', 0)->first();
        $this->assertNotNull($used);

        $this->assertStringContainsString(
            'cannot delete',
            (string) (new Transporters())->deleteGuard($used)
        );
    }

    public function test_the_link_is_in_the_sales_nav(): void
    {
        $res = $this->actingAs($this->admin())->get('/bil/sales/transporters');

        $res->assertOk();
        $res->assertSee('bil/sales/transporters', false);
        $res->assertSee('>Sales<', false);
    }

    public function test_a_user_without_the_page_is_refused(): void
    {
        $this->actingAs($this->outsider())->get('/bil/sales/transporters')->assertForbidden();
    }

    public function test_the_print_route_resolves_the_grid(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/grid/bil.sales.transporters/print')
            ->assertOk();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/bil/sales/transporters')->assertRedirect();
    }
}
