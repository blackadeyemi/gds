<?php

namespace Tests\Feature;

use Livewire\Livewire;
use Modules\Bpl\Livewire\JumboRolls\Production\Hardroll;
use Modules\Bpl\Livewire\JumboRolls\Production\Softroll;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Bpl\Models\BplProductSoftroll;
use Modules\Bpl\Models\BplProduction;
use Modules\Bpl\Models\BplSoftrollProduction;
use Modules\Bpl\Support\RollBarcode;
use Modules\Bpl\Support\WeightAllowance;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Jumbo Rolls → Production, both streams.
 *
 * These run against the LIVE bpl database, so every row this test writes is
 * tracked by id and hard-deleted in tearDown — never by date, and never by a
 * "today's rows" sweep, which would take real production with it.
 */
class BplProductionPageTest extends TestCase
{
    /** @var array<int> */
    private array $hardIds = [];

    /** @var array<int> */
    private array $softIds = [];

    protected function tearDown(): void
    {
        if ($this->hardIds) {
            BplProduction::withTrashed()->whereIn('id', $this->hardIds)->forceDelete();
        }
        if ($this->softIds) {
            BplSoftrollProduction::withTrashed()->whereIn('id', $this->softIds)->forceDelete();
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u, 'no admin user in core.user');

        return $u;
    }

    private function newHardroll(BplProductHardroll $product, int $customerId, string $weight): BplProduction
    {
        Livewire::test(Hardroll::class)
            ->call('create')
            ->set('dateofmanufacture', now()->format('Y-m-d'))
            ->set('papermachine', 'PM3')
            ->set('customer_id', $customerId)
            // The product picker cascades off the grade, so the grade has to be
            // chosen first — same as on screen.
            ->set('form_gradetype', $product->gradetype)
            ->set('product_id', $product->id)
            ->set('corediameter', '76')
            ->set('joints', '0')
            ->set('weight', $weight)
            ->set('cart', 'A')
            ->call('save')
            ->assertHasNoErrors();

        $row = BplProduction::orderByDesc('id')->first();
        $this->hardIds[] = $row->id;

        return $row;
    }

    private function newSoftroll(BplProductSoftroll $product, string $weight): BplSoftrollProduction
    {
        Livewire::test(Softroll::class)
            ->call('create')
            ->set('dateofmanufacture', now()->format('Y-m-d'))
            ->set('papermachine', '3')
            ->set('product_id', $product->id)
            ->set('brightness', '78')
            ->set('weight', $weight)
            ->call('save')
            ->assertHasNoErrors();

        $row = BplSoftrollProduction::orderByDesc('id')->first();
        $this->softIds[] = $row->id;

        return $row;
    }

    public function test_both_pages_open(): void
    {
        $this->actingAs($this->admin())
            ->get('/bpl/jumbo-rolls/production/hardroll')
            ->assertOk()
            ->assertSee('BPL Hardroll Production');

        $this->actingAs($this->admin())
            ->get('/bpl/jumbo-rolls/production/softroll')
            ->assertOk()
            ->assertSee('BPL Softroll Production');
    }

    /**
     * Each view's row count has to agree with its own source table.
     *
     * `default` is windowed to the last 12 months — it is the day-to-day
     * listing. `on_floor` is NOT: it is a stock position, and reels standing
     * longer than the window (the oldest since 2022/02/15) are the most
     * interesting rows on the page, not the ones to hide.
     */
    public function test_every_view_renders_and_agrees_with_its_table(): void
    {
        Livewire::actingAs($this->admin());

        $expected = [
            Hardroll::class => [
                'default' => BplProduction::where('dateofmanufacture', '>=', Hardroll::listedFrom())->count(),
                'on_floor' => BplProduction::whereNull('status')->count(),
            ],
            Softroll::class => [
                'default' => BplSoftrollProduction::where('dateofmanufacture', '>=', Softroll::listedFrom())->count(),
                'on_floor' => BplSoftrollProduction::whereNull('status')->count(),
            ],
        ];

        foreach ($expected as $class => $views) {
            $this->assertSame(array_keys($views), array_keys((new $class)->views()));

            foreach ($views as $key => $count) {
                $t = Livewire::test($class)->call('switchView', $key);
                $this->assertSame($count, $t->viewData('rows')->total(), "{$class} / {$key}");
            }
        }
    }

    /**
     * The product picker cascades off the grade, and the page does not carry
     * the form's markup while the modal is shut.
     *
     * Both are performance contracts, and both are the kind that rot silently:
     * the catalog holds 4,387 hardroll products, and the searchable-select
     * partial snapshots its whole option list as JSON. Rendering that into every
     * page load, sort and search put 405 KB on the wire for a hidden modal.
     */
    public function test_the_form_is_absent_until_opened_and_the_product_picker_cascades(): void
    {
        Livewire::actingAs($this->admin());

        $closed = Livewire::test(Hardroll::class);
        $this->assertStringNotContainsString('Core diameter (mm)', $closed->html(), 'the form rendered while shut');
        $this->assertLessThan(150 * 1024, strlen($closed->html()), 'the listing HTML has grown past 150KB');

        $opened = Livewire::test(Hardroll::class)->call('create');
        $this->assertStringContainsString('Core diameter (mm)', $opened->html());

        // No grade chosen yet, so no products are offered.
        $this->assertCount(0, $opened->instance()->products);

        $grade = BplProductHardroll::query()->whereNotNull('gradetype')->where('gradetype', '<>', '')->value('gradetype');
        $opened->set('form_gradetype', $grade);

        $offered = $opened->instance()->products;
        $this->assertGreaterThan(0, $offered->count());
        $this->assertSame(
            BplProductHardroll::where('gradetype', $grade)->count(),
            $offered->count(),
            'the picker did not narrow to the chosen grade'
        );

        // Switching grade drops a product picked under the old one.
        $opened->set('product_id', $offered->first()->id);
        $other = BplProductHardroll::query()->where('gradetype', '<>', $grade)
            ->whereNotNull('gradetype')->where('gradetype', '<>', '')->value('gradetype');
        $opened->set('form_gradetype', $other);
        $this->assertNull($opened->get('product_id'));
    }

    /** The window is on the listing only — aged stock stays visible. */
    public function test_the_listing_is_windowed_but_the_floor_view_is_not(): void
    {
        Livewire::actingAs($this->admin());

        $cutoff = Hardroll::listedFrom();
        $aged = BplProduction::whereNull('status')->where('dateofmanufacture', '<', $cutoff)->count();
        $this->assertGreaterThan(0, $aged, 'no reels older than the window to test with');

        $default = Livewire::test(Hardroll::class)->call('switchView', 'default')->viewData('rows')->total();
        $this->assertSame(
            BplProduction::where('dateofmanufacture', '>=', $cutoff)->count(),
            $default
        );
        $this->assertLessThan(BplProduction::count(), $default, 'the listing was not windowed');

        // The aged reels are still counted on the floor.
        $floor = Livewire::test(Hardroll::class)->call('switchView', 'on_floor')->viewData('rows')->total();
        $this->assertSame(BplProduction::whereNull('status')->count(), $floor);
        $this->assertGreaterThanOrEqual($aged, $floor);
    }

    /**
     * The reason this module exists. A softroll made on the same machine on the
     * same day as a hardroll used to be handed the SAME barcode, because both
     * generators emitted `M{machine}` from separate counters. It now prints
     * `S{machine}`, so the barcode alone says which stream a scan belongs to.
     */
    public function test_a_softroll_barcode_cannot_collide_with_a_hardroll_barcode(): void
    {
        Livewire::actingAs($this->admin());

        $hard = $this->newHardroll(BplProductHardroll::first(), 1, '1000');
        $soft = $this->newSoftroll(BplProductSoftroll::first(), '3400');

        $this->assertSame('hardroll', RollBarcode::stream($hard->barcode));
        $this->assertSame('softroll', RollBarcode::stream($soft->barcode));

        // Same date, same machine, same ordinal — and still different codes.
        $this->assertNotSame($hard->barcode, $soft->barcode);
        $this->assertFalse(BplProduction::where('barcode', $soft->barcode)->exists());
        $this->assertFalse(BplSoftrollProduction::where('barcode', $hard->barcode)->exists());

        // Every barcode printed before the cut-over reads as a hardroll, which
        // is what the historical rows are resolved as.
        $this->assertSame('hardroll', RollBarcode::stream('26-08-25-M3-008'));
        $this->assertNull(RollBarcode::stream('not-a-barcode'));
    }

    /** Two rolls in the same stream on one day take consecutive numbers. */
    public function test_the_sequence_advances_per_stream_per_day(): void
    {
        Livewire::actingAs($this->admin());

        $product = BplProductSoftroll::first();
        $first = $this->newSoftroll($product, '3400');
        $second = $this->newSoftroll($product, '3300');

        $ordinal = fn ($b) => (int) substr($b, -3);
        $this->assertSame($ordinal($first->barcode) + 1, $ordinal($second->barcode));
        $this->assertNotSame($first->softrollnumber, $second->softrollnumber);
    }

    /**
     * The core/wrapper allowance is per customer, and the rules are maintained
     * on BPL → Jumbo Rolls → Weight Allowances (see BplWeightAllowancesPageTest).
     */
    public function test_the_weight_allowance_applies_to_belimpex_only(): void
    {
        Livewire::actingAs($this->admin());

        $product = BplProductHardroll::where('gradetype', 'PBTB')->where('ply', 2)->first();
        $this->assertNotNull($product, 'no PBTB 2-ply product to test the allowance with');

        $belimpex = (int) config('bil.jumbo_roll_customer_id');
        $allowance = WeightAllowance::for($belimpex, 'PBTB', 2);
        $this->assertGreaterThan(0, $allowance, 'the PBTB 2-ply rule is missing');

        $theirs = $this->newHardroll($product, $belimpex, '1500');
        $this->assertEqualsWithDelta(1500 - $allowance, (float) $theirs->weight, 0.01);
        $this->assertEqualsWithDelta($allowance, (float) $theirs->net_weight, 0.01);

        $others = $this->newHardroll($product, 1, '1500');
        $this->assertEqualsWithDelta(1500, (float) $others->weight, 0.01);
        $this->assertEqualsWithDelta(0, (float) $others->net_weight, 0.01);
    }

    /**
     * Editing a reel without touching its weight must not deduct the allowance
     * a second time — the stored weight is already net of it.
     */
    public function test_editing_without_changing_the_weight_does_not_deduct_again(): void
    {
        Livewire::actingAs($this->admin());

        $product = BplProductHardroll::where('gradetype', 'PBTB')->where('ply', 2)->first();
        $roll = $this->newHardroll($product, (int) config('bil.jumbo_roll_customer_id'), '1500');
        $netAfterCreate = (float) $roll->weight;

        Livewire::test(Hardroll::class)->call('edit', $roll->id)->set('joints', '4')->call('save')->assertHasNoErrors();

        $roll->refresh();
        $this->assertEqualsWithDelta($netAfterCreate, (float) $roll->weight, 0.01);
        $this->assertSame(4, (int) $roll->joints);
    }

    /** A softroll takes its grade and dimensions from the product it is made to. */
    public function test_a_softroll_inherits_its_product(): void
    {
        Livewire::actingAs($this->admin());

        $product = BplProductSoftroll::first();
        $roll = $this->newSoftroll($product, '3400');

        $this->assertSame($product->id, $roll->product_id);
        $this->assertSame($product->grade_id, $roll->grade_id);
        $this->assertSame((string) $product->grammage, (string) $roll->grammage);
        $this->assertSame((string) $product->diameter, (string) $roll->diameter);
    }

    /** A reel Factory Exit has taken is no longer this screen's to change. */
    public function test_an_exited_reel_is_locked(): void
    {
        $exited = BplProduction::whereNotNull('status')->first();
        $this->assertNotNull($exited);

        $grid = new Hardroll();
        $this->assertNotNull($grid->deleteGuard($exited));
        $this->assertNull($grid->deleteGuard(BplProduction::whereNull('status')->first()));
    }

    /** Both labels render, carry the roll's barcode and name their stream. */
    public function test_the_labels_render(): void
    {
        Livewire::actingAs($this->admin());

        $hard = $this->newHardroll(BplProductHardroll::first(), 1, '1000');
        $soft = $this->newSoftroll(BplProductSoftroll::first(), '3400');

        $this->actingAs($this->admin())
            ->get(route('bpl.jumbo-rolls.production.hardroll.label', $hard->id))
            ->assertOk()
            ->assertSee($hard->barcode)
            ->assertSee('HARDROLL');

        $this->actingAs($this->admin())
            ->get(route('bpl.jumbo-rolls.production.softroll.label', $soft->id))
            ->assertOk()
            ->assertSee($soft->barcode)
            ->assertSee('SOFTROLL');
    }
}
