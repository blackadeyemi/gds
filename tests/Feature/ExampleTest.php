<?php

namespace Tests\Feature;

use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * The front door.
 *
 * This began as the Laravel scaffold's `assertStatus(200)` on `/`, which has
 * never been true here: `/` IS the dashboard and carries `auth`, so a guest is
 * sent to the login page. The test failed from the day the route was written
 * and stayed red, which made it worse than useless — a permanently failing
 * check is one nobody reads, and it hid the real failures behind it.
 *
 * So it now asserts what the app actually promises: a guest is turned away to
 * login, and someone signed in gets the dashboard.
 */
class ExampleTest extends TestCase
{
    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_login_page_itself_is_public(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_a_signed_in_user_gets_the_dashboard(): void
    {
        $user = User::first();
        $this->assertNotNull($user, 'no user to sign in as');

        $this->actingAs($user)->get('/')->assertOk();
    }
}
