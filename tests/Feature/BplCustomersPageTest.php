<?php

namespace Tests\Feature;

use Livewire\Livewire;
use Modules\Bpl\Livewire\Sales\Customers;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Sales → Customers.
 *
 * Read-only: nothing here writes a customer. The point of the file is the
 * address autocomplete's loading contract, which is easy to break and fails
 * silently when it is — see test_the_address_autocomplete_is_registered.
 */
class BplCustomersPageTest extends TestCase
{
    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u, 'no admin user in core.user');

        return $u;
    }

    public function test_the_page_opens(): void
    {
        $this->actingAs($this->admin())
            ->get('/bpl/sales/customers')
            ->assertOk()
            ->assertSee('Customers');
    }

    /**
     * The Alpine component behind the Address field must be registered by the
     * PAGE, not by the form.
     *
     * `@push` only reaches the layout's script stack on a full page render, and
     * the form is built only while its modal is open. A push from inside the
     * form would therefore land in a stack that had already been output: the
     * page would look fine, and every address field would throw "osmAddress is
     * not defined" the moment somebody opened the modal. Nothing else would
     * fail, which is why it is worth a test.
     */
    public function test_the_address_autocomplete_is_registered_by_the_page(): void
    {
        $html = $this->actingAs($this->admin())->get('/bpl/sales/customers')->getContent();

        $this->assertStringContainsString("Alpine.data('osmAddress'", $html, 'the OSM component is not on the page');
        // …and the form itself is not, so its option lists cost nothing here.
        $this->assertStringNotContainsString('Full customer name', $html, 'the form rendered while shut');
    }

    /** Opening the modal brings the form and its pickers with it. */
    public function test_opening_the_modal_builds_the_form(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(Customers::class);
        $this->assertStringNotContainsString('Full customer name', $c->html());

        $c->call('create');
        $html = $c->html();

        $this->assertStringContainsString('Full customer name', $html);
        $this->assertStringContainsString('osmAddress()', $html, 'the address field lost its component binding');
        $this->assertStringContainsString('Select country', $html);
    }
}
