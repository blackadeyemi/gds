<?php

namespace Tests\Feature;

use Livewire\Livewire;
use Modules\Bpl\Livewire\JumboRolls\Production\Hardroll;
use Modules\Bpl\Livewire\JumboRolls\WeightAllowances;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Bpl\Models\BplProduction;
use Modules\Bpl\Models\BplWeightAllowance;
use Modules\Bpl\Models\BplWeightAllowanceHistory;
use Modules\Bpl\Support\WeightAllowance;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Jumbo Rolls → Weight Allowances.
 *
 * Runs against the LIVE bpl database, so every row written here is tracked by
 * id and hard-deleted in tearDown. The seeded rules are never touched: each
 * test writes its own rule on a grade type no product uses.
 */
class BplWeightAllowancesPageTest extends TestCase
{
    /** A grade type that exists in no product, so a rule on it changes nothing. */
    private const TEST_GRADE = 'ZZTEST';

    private array $ruleIds = [];
    private array $reelIds = [];
    /** Grade types this test wrote rules on, so the history can be cleaned too. */
    private array $ruleGrades = [];

    protected function tearDown(): void
    {
        if ($this->ruleIds) {
            BplWeightAllowance::withTrashed()->whereIn('id', $this->ruleIds)->forceDelete();
        }
        if ($this->reelIds) {
            BplProduction::withTrashed()->whereIn('id', $this->reelIds)->forceDelete();
        }
        if ($this->ruleGrades) {
            BplWeightAllowanceHistory::whereIn('gradetype', array_unique($this->ruleGrades))->delete();
        }
        WeightAllowance::flush();

        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u, 'no admin user in core.user');

        return $u;
    }

    private function customer(): int
    {
        return (int) config('bil.jumbo_roll_customer_id');
    }

    /** Add a rule through the screen and remember it for cleanup. */
    private function addRule(string $gradetype, int $ply, string $allowance, string $note = ''): BplWeightAllowance
    {
        Livewire::test(WeightAllowances::class)
            ->call('create')
            ->set('customer_id', $this->customer())
            ->set('gradetype', $gradetype)
            ->set('ply', (string) $ply)
            ->set('allowance', $allowance)
            ->set('note', $note)
            ->call('save')
            ->assertHasNoErrors();

        $row = BplWeightAllowance::orderByDesc('id')->first();
        $this->ruleIds[] = $row->id;
        $this->ruleGrades[] = $row->gradetype;

        return $row;
    }

    public function test_the_page_opens_and_shows_the_seeded_rules(): void
    {
        $this->actingAs($this->admin())
            ->get('/bpl/jumbo-rolls/weight-allowances')
            ->assertOk()
            ->assertSee('Hardroll Weight Allowances')
            ->assertSee('Allowance (kg)');
    }

    public function test_every_view_renders(): void
    {
        Livewire::actingAs($this->admin());

        $this->assertSame(
            ['default', 'recorded', 'history'],
            array_keys((new WeightAllowances)->views())
        );

        foreach (['default', 'recorded', 'history'] as $key) {
            Livewire::test(WeightAllowances::class)->call('switchView', $key)->assertOk();
        }
    }

    /** "Recorded in production" has to agree with the reels themselves. */
    public function test_the_recorded_view_matches_the_production_rows(): void
    {
        Livewire::actingAs($this->admin());

        $expected = BplProduction::query()
            ->join('bpl_products_hardroll as p', 'bpl_production.product_id', '=', 'p.id')
            ->whereNotNull('bpl_production.net_weight')
            ->where('bpl_production.net_weight', '>', 0)
            ->distinct()
            ->count(BplProduction::getConnectionResolver()->connection('bpl')->raw(
                'CONCAT(`bpl_production`.`customer_id`, "|", `p`.`gradetype`, "|", `p`.`ply`, "|", `bpl_production`.`net_weight`)'
            ));

        $rows = Livewire::test(WeightAllowances::class)->call('switchView', 'recorded')->viewData('rows');

        $this->assertSame($expected, $rows->total());
    }

    /** The read-out views offer no edit or delete: they are not rules. */
    public function test_the_read_out_views_are_not_editable(): void
    {
        Livewire::actingAs($this->admin());

        foreach (['recorded', 'history'] as $view) {
            $c = Livewire::test(WeightAllowances::class)->call('switchView', $view);
            foreach (['create', 'edit', 'delete'] as $ability) {
                $this->assertFalse($c->instance()->mayDo($ability), "{$view} offered {$ability}");
            }
        }

        $c = Livewire::test(WeightAllowances::class)->call('switchView', 'default');
        $this->assertTrue($c->instance()->mayDo('edit'));
    }

    /**
     * The grade type is stored and matched in upper case. The legacy rule used
     * PHP `==`, so a product spelled `PBTs` silently got no allowance at all.
     */
    public function test_the_grade_type_is_case_insensitive(): void
    {
        Livewire::actingAs($this->admin());

        $rule = $this->addRule(strtolower(self::TEST_GRADE), 1, '42.5');
        $this->assertSame(self::TEST_GRADE, $rule->gradetype);

        WeightAllowance::flush();
        $this->assertSame(42.5, WeightAllowance::for($this->customer(), self::TEST_GRADE, 1));
        $this->assertSame(42.5, WeightAllowance::for($this->customer(), strtolower(self::TEST_GRADE), 1));

        // Scoped to the customer and the ply, not just the grade.
        $this->assertSame(0.0, WeightAllowance::for($this->customer(), self::TEST_GRADE, 2));
        $this->assertSame(0.0, WeightAllowance::for($this->customer() + 1000, self::TEST_GRADE, 1));
    }

    /** One rule per customer + grade + ply, refused in words rather than by the index. */
    public function test_a_duplicate_rule_is_refused_cleanly(): void
    {
        Livewire::actingAs($this->admin());

        $this->addRule(self::TEST_GRADE, 1, '10');

        Livewire::test(WeightAllowances::class)
            ->call('create')
            ->set('customer_id', $this->customer())
            ->set('gradetype', self::TEST_GRADE)
            ->set('ply', '1')
            ->set('allowance', '99')
            ->call('save')
            ->assertHasErrors('gradetype');
    }

    /** Add, change and remove each leave a line in the history. */
    public function test_every_change_is_logged_and_a_no_op_is_not(): void
    {
        Livewire::actingAs($this->admin());

        $rule = $this->addRule(self::TEST_GRADE, 1, '70', 'initial');

        $added = BplWeightAllowanceHistory::where('gradetype', self::TEST_GRADE)->orderByDesc('id')->first();
        $this->assertSame(BplWeightAllowanceHistory::ADDED, $added->action);
        $this->assertNull($added->previous_allowance);
        $this->assertSame(70.0, $added->allowance);

        Livewire::test(WeightAllowances::class)->call('edit', $rule->id)
            ->set('allowance', '65')->call('save')->assertHasNoErrors();

        $changed = BplWeightAllowanceHistory::where('gradetype', self::TEST_GRADE)->orderByDesc('id')->first();
        $this->assertSame(BplWeightAllowanceHistory::CHANGED, $changed->action);
        $this->assertSame(70.0, $changed->previous_allowance);
        $this->assertSame(65.0, $changed->allowance);

        // Saving without changing anything must not fake a change — the same
        // contract as Conversion Setup's changeover log.
        $before = BplWeightAllowanceHistory::where('gradetype', self::TEST_GRADE)->count();
        Livewire::test(WeightAllowances::class)->call('edit', $rule->id)->call('save')->assertHasNoErrors();
        $this->assertSame($before, BplWeightAllowanceHistory::where('gradetype', self::TEST_GRADE)->count());

        Livewire::test(WeightAllowances::class)->set('confirmingDelete', $rule->id)->call('deleteConfirmed');

        $removed = BplWeightAllowanceHistory::where('gradetype', self::TEST_GRADE)->orderByDesc('id')->first();
        $this->assertSame(BplWeightAllowanceHistory::REMOVED, $removed->action);
        $this->assertSame(65.0, $removed->previous_allowance);
        $this->assertNull($removed->allowance);

        WeightAllowance::flush();
        $this->assertSame(0.0, WeightAllowance::for($this->customer(), self::TEST_GRADE, 1));
    }

    /**
     * The point of the screen: a rule added here changes what the next reel is
     * deducted, with no deploy.
     */
    public function test_a_new_rule_changes_the_next_reel(): void
    {
        Livewire::actingAs($this->admin());

        // A product whose grade/ply no seeded rule covers, so the effect is
        // unambiguously this test's.
        $product = BplProductHardroll::query()
            ->whereNotNull('gradetype')->where('gradetype', '<>', '')
            ->get(['id', 'productname', 'gradetype', 'ply'])
            ->first(fn ($p) => ! WeightAllowance::has($this->customer(), $p->gradetype, (int) $p->ply));
        $this->assertNotNull($product, 'every product is already covered by a rule');

        $make = function (string $weight) use ($product) {
            Livewire::test(Hardroll::class)->call('create')
                ->set('dateofmanufacture', now()->format('Y-m-d'))
                ->set('papermachine', 'PM3')
                ->set('customer_id', $this->customer())
                ->set('form_gradetype', $product->gradetype)
                ->set('product_id', $product->id)
                ->set('corediameter', '76')->set('joints', '0')
                ->set('weight', $weight)->set('cart', 'A')
                ->call('save')->assertHasNoErrors();

            $row = BplProduction::orderByDesc('id')->first();
            $this->reelIds[] = $row->id;

            return $row;
        };

        $before = $make('1000');
        $this->assertEqualsWithDelta(1000, (float) $before->weight, 0.01);
        $this->assertEqualsWithDelta(0, (float) $before->net_weight, 0.01);

        $this->addRule($product->gradetype, (int) $product->ply, '25');
        WeightAllowance::flush();

        $after = $make('1000');
        $this->assertEqualsWithDelta(975, (float) $after->weight, 0.01);
        $this->assertEqualsWithDelta(25, (float) $after->net_weight, 0.01);

        // And the reel recorded before the rule keeps the weight it was given.
        $before->refresh();
        $this->assertEqualsWithDelta(1000, (float) $before->weight, 0.01);
    }
}
