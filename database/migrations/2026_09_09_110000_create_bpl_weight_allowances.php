<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the hardroll weight allowance editable instead of hard-coded.
 *
 * A reel made for Belimpex has a fixed core/wrapper allowance deducted from its
 * scale weight. The rule lived in PHP — five `new stdClass()` rows inside
 * Bil\Bpl\production::merge() — so changing it meant a deploy, and the live data
 * shows it HAS been changed: since the feature started on 2026/05/14, reels have
 * been saved with `PBT+`:1 => 70 (a grade the checked-in table doesn't mention),
 * `PBTS`:1 => 14 on three reels that otherwise get 70, `SBT`:2 => 14, and
 * `PBTS`:2 => 70 once. Nobody can say which of those was intended, because
 * nothing recorded who changed what.
 *
 * So: a table for the current rules, an append-only log of every change, and a
 * screen over both — the same shape as BIL's Conversion Setup and its history.
 *
 * `customer_id` is a column rather than a constant. The legacy code carried an
 * `$affectedCustomers = ["17"]` array it never grew, but the rule genuinely is
 * per-customer, and a second customer should not need a deploy either.
 *
 * Seeded with the five rows from the legacy `merge()`, all for customer 17.
 * That is deliberately the CHECKED-IN rule, not a guess reverse-engineered from
 * the production data — the screen's "Recorded in production" view puts the two
 * side by side so somebody who knows the process can reconcile them.
 *
 * These are gds-only tables. The flat PHP app keeps its hard-coded copy until it
 * is retired, so the two can drift; the screen is the place that says what the
 * rule is meant to be.
 */
return new class extends Migration
{
    /** Bil\Bpl\production::merge(), verbatim. */
    private const LEGACY_RULES = [
        ['EBT', 1, 70],
        ['PBTS', 1, 70],
        ['SBT', 1, 70],
        ['PBTS', 2, 14],
        ['PBTB', 2, 14],
    ];

    public function up(): void
    {
        $bpl = Schema::connection('bpl');

        if (! $bpl->hasTable('bpl_weight_allowances')) {
            $bpl->create('bpl_weight_allowances', function ($t) {
                $t->increments('id');
                $t->integer('customer_id');
                $t->string('gradetype', 50);
                $t->unsignedTinyInteger('ply');
                // Kilograms off the scale weight. Decimal, not int: the legacy
                // values are whole numbers but nothing says the next one is.
                $t->decimal('allowance', 8, 2);
                $t->string('note', 255)->nullable();
                $t->string('username', 50)->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->softDeletes();

                // One rule per customer + grade + ply. The column collation is
                // case-insensitive, which matches how the lookup compares —
                // see Modules\Bpl\Support\WeightAllowance.
                $t->unique(['customer_id', 'gradetype', 'ply'], 'bpl_weight_allowance_rule_idx');
            });
        }

        if (! $bpl->hasTable('bpl_weight_allowance_history')) {
            $bpl->create('bpl_weight_allowance_history', function ($t) {
                $t->increments('id');
                $t->integer('customer_id');
                $t->string('gradetype', 50);
                $t->unsignedTinyInteger('ply');
                // Null on a removal: there is no allowance any more.
                $t->decimal('allowance', 8, 2)->nullable();
                $t->decimal('previous_allowance', 8, 2)->nullable();
                $t->string('action', 20);
                $t->string('note', 255)->nullable();
                $t->string('username', 50)->nullable();
                $t->timestamp('date_modified')->nullable();

                $t->index(['customer_id', 'gradetype', 'ply'], 'bpl_weight_allowance_hist_idx');
                $t->index('date_modified', 'bpl_weight_allowance_hist_when_idx');
            });
        }

        // The reconciliation view groups the reels that actually carry an
        // allowance — 784 of 279,282 — and without this it is a full scan.
        if (! $this->hasIndex('bpl_production', 'bpl_production_net_weight_idx')) {
            DB::connection('bpl')->statement(
                'ALTER TABLE `bpl_production` ADD INDEX `bpl_production_net_weight_idx` (`net_weight`)'
            );
        }

        $customer = (int) config('bil.jumbo_roll_customer_id', 17);
        $now = now();

        foreach (self::LEGACY_RULES as [$gradetype, $ply, $allowance]) {
            DB::connection('bpl')->table('bpl_weight_allowances')->updateOrInsert(
                ['customer_id' => $customer, 'gradetype' => $gradetype, 'ply' => $ply],
                [
                    'allowance' => $allowance,
                    'note' => 'Seeded from the legacy hard-coded rule.',
                    'username' => 'gds',
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::connection('bpl')
            ->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]) !== [];
    }

    public function down(): void
    {
        if ($this->hasIndex('bpl_production', 'bpl_production_net_weight_idx')) {
            DB::connection('bpl')->statement('ALTER TABLE `bpl_production` DROP INDEX `bpl_production_net_weight_idx`');
        }

        Schema::connection('bpl')->dropIfExists('bpl_weight_allowance_history');
        Schema::connection('bpl')->dropIfExists('bpl_weight_allowances');
    }
};
