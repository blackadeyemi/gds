<?php

namespace Tests\Feature;

use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * Read-only HTTP checks for BIL → Sales → Customers: the route, the `page:`
 * middleware, the nav entry and the export guard. Nothing is written.
 */
class SalesCustomersPageTest extends TestCase
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
            ->first(fn (User $u) => ! $u->canAccessPage('bil.sales.customers'));
        $this->assertNotNull($u, 'no non-admin user without the page');

        return $u;
    }

    public function test_admin_can_open_the_page(): void
    {
        $res = $this->actingAs($this->admin())->get('/bil/sales/customers');

        $res->assertOk();
        $res->assertSee('Sales Customers');
        $res->assertSee('Designation');
        $res->assertSee('Channel');
    }

    public function test_every_declared_view_renders(): void
    {
        $grid = new \Modules\Bil\Livewire\Sales\Customers();
        $this->assertSame(
            ['default', 'contact', 'unclassified', 'by_region', 'by_channel'],
            array_keys($grid->views())
        );

        \Livewire\Livewire::actingAs($this->admin());
        foreach (array_keys($grid->views()) as $key) {
            \Livewire\Livewire::test(\Modules\Bil\Livewire\Sales\Customers::class)
                ->call('switchView', $key)
                ->assertOk();
        }
    }

    /** Country → state → city, and the dial code that follows the country. */
    public function test_the_location_pickers_cascade(): void
    {
        \Livewire\Livewire::actingAs($this->admin());
        $c = \Livewire\Livewire::test(\Modules\Bil\Livewire\Sales\Customers::class)->call('create');

        $this->assertSame('Nigeria', $c->get('customercountry'), 'Nigeria is the default');
        $this->assertCount(250, $c->instance()->countryOptions);
        $this->assertSame('+234', $c->instance()->dialCode);
        $this->assertGreaterThanOrEqual(37, count($c->instance()->stateOptions));

        $c->set('customerstate', 'LAGOS');
        $this->assertNotEmpty($c->instance()->cityOptions);

        $c->set('customercity', 'IKEJA')->set('customercountry', 'Ghana');
        $this->assertSame('', $c->get('customerstate'), 'a new country drops the old state');
        $this->assertSame('', $c->get('customercity'));
        $this->assertSame('+233', $c->instance()->dialCode);
        // Every country gets subdivisions now, and the right noun for them.
        $this->assertNotEmpty($c->instance()->stateOptions);
        $this->assertSame('Region', $c->instance()->stateNoun);
    }

    /**
     * Sales territories divide Nigeria; a customer anywhere else has none.
     * Channel is not a territory and applies everywhere.
     */
    public function test_territory_applies_to_nigeria_only(): void
    {
        \Livewire\Livewire::actingAs($this->admin());
        $c = \Livewire\Livewire::test(\Modules\Bil\Livewire\Sales\Customers::class)->call('create');

        $this->assertTrue($c->instance()->territoryApplies);
        $c->set('customerregion', 'NORTH')->set('customerdesignation', 'NORTH 2');

        $c->set('customercountry', 'Ghana');
        $this->assertFalse($c->instance()->territoryApplies);
        $this->assertNull($c->get('customerregion'));
        $this->assertNull($c->get('customerdesignation'));
        $this->assertSame([], $c->instance()->regionOptions);
        $this->assertCount(4, $c->instance()->channelOptions, 'channel is not a territory');
    }

    public function test_the_customers_link_is_in_the_sales_nav(): void
    {
        $res = $this->actingAs($this->admin())->get('/bil/sales/customers');

        $res->assertOk();
        $res->assertSee('bil/sales/customers', false);
        $res->assertSee('>Sales<', false);
    }

    public function test_a_user_without_the_page_is_refused(): void
    {
        $this->actingAs($this->outsider())->get('/bil/sales/customers')->assertForbidden();
    }

    public function test_the_nav_link_is_hidden_from_a_user_without_the_page(): void
    {
        $res = $this->actingAs($this->outsider())->get('/');

        $res->assertOk();
        $res->assertDontSee('bil/sales/customers', false);
    }

    /** The grid registry drives the generic print route; a missing entry 404s. */
    public function test_the_print_route_resolves_the_grid(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/grid/bil.sales.customers/print')
            ->assertOk();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/bil/sales/customers')->assertRedirect();
    }
}
