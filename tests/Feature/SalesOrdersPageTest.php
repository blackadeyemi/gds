<?php

namespace Tests\Feature;

use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * Read-only HTTP checks for BIL → Sales → Orders: the route, the `page:`
 * middleware, and that the sidebar renders with the new Sales group. Nothing is
 * written, so these run against the real databases safely.
 */
class SalesOrdersPageTest extends TestCase
{
    protected function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u, 'no admin user in core.user');

        return $u;
    }

    /** A real user who has NOT been granted the (brand new) page. */
    protected function outsider(): User
    {
        $u = User::whereDoesntHave('roles', fn ($q) => $q->where('legacy_level', 1))
            ->get()
            ->first(fn (User $u) => ! $u->canAccessPage('bil.sales.orders'));
        $this->assertNotNull($u, 'no non-admin user without the page');

        return $u;
    }

    public function test_admin_can_open_the_page(): void
    {
        $res = $this->actingAs($this->admin())->get('/bil/sales/orders');

        $res->assertOk();
        $res->assertSee('Sales Orders');
        $res->assertSee('Order location');
        $res->assertSee('Rows to add');
        // The order-location picker is fed from the core warehouses, so the
        // depot names must be what appears — not the legacy sales_warehouse row.
        $res->assertSee('Abuja Depot');
        // Kano is registered inactive and must not be offered for a new order.
        $res->assertDontSee('Kano Depot');
    }

    public function test_the_sales_group_is_in_the_sidebar(): void
    {
        $res = $this->actingAs($this->admin())->get('/bil/sales/orders');

        $res->assertOk();
        $res->assertSee('bil/sales/orders', false);
        $res->assertSee('>Sales<', false);
    }

    public function test_a_user_without_the_page_is_refused(): void
    {
        $this->actingAs($this->outsider())->get('/bil/sales/orders')->assertForbidden();
    }

    public function test_the_nav_link_is_hidden_from_a_user_without_the_page(): void
    {
        $res = $this->actingAs($this->outsider())->get('/');

        $res->assertOk();
        $res->assertDontSee('bil/sales/orders', false);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/bil/sales/orders')->assertRedirect();
    }
}
