<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conversion waste — the rebuild of the legacy factory_production_waste.php.
 *
 * The legacy `factory_waste` table is EMPTY (0 rows), so nothing is migrated and
 * nothing has to stay string-compatible with it. gds owns these outright.
 *
 * WHAT CHANGES, AND WHY
 *
 * The legacy screen posted a flat list of up to 20 cause+weight pairs with ONE
 * origin radio for the whole form, against a free-typed date/line/product. There
 * was no notion of the entry being complete, so waste was recorded when someone
 * remembered to.
 *
 * Here waste hangs off a RUN — one line converting one product on one date in
 * one shift — and a run is either open or confirmed. That is the whole point of
 * the rebuild: `conversion_waste_runs` makes "has this shift's waste been
 * accounted for?" a question with an answer, which is what lets Conversion
 * Output refuse to start the next run until the previous one is closed.
 *
 * A run is keyed by PRODUCT as well as shift, because a line's product can
 * change mid-shift: that changeover ends a run and starts another, so the
 * outgoing product's waste has to be entered before the incoming one can be
 * booked. (line_id, production_date, shift, productid) is therefore unique.
 *
 * Origin moves from one radio per form to one per ENTRY row, since it now picks
 * which lookup the row is classified against — a jumbo-roll grade type or a raw
 * materials group. Every cause stays available under both.
 */
return new class extends Migration
{
    /** The legacy factory_waste_details vocabulary, carried over as-is. */
    private const CAUSES = [
        'Bad Cuts', 'Bad Perforation', 'Breaks', 'Core Failure',
        'Defected Jumboreel', 'Foklift Damage', 'Jumboreel End', 'Packing',
        'Setting of Machine', 'Stickies', 'Tail', 'Trims', 'Uncompleted Logs',
    ];

    /**
     * `source` names the lookup the entry's origin_ref is chosen from. It is a
     * fixed vocabulary in code (WasteOrigin::SOURCES) rather than free text —
     * an admin can rename, reorder or retire an origin, but inventing one whose
     * source nothing can resolve would just produce an empty dropdown.
     */
    private const ORIGINS = [
        ['key' => 'jumboreel', 'label' => 'Jumbo Roll', 'source' => 'grade_types', 'sort_order' => 10],
        ['key' => 'rawmaterials', 'label' => 'Raw Materials', 'source' => 'rm_groups', 'sort_order' => 20],
    ];

    public function up(): void
    {
        $core = Schema::connection('core');

        // ---- Settings vocabulary (Settings → Waste) ----

        $core->create('waste_causes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        $core->create('waste_origins', function (Blueprint $table) {
            $table->id();
            // Stable machine key. Entries reference the id, but reports and the
            // form's conditional lookup read this.
            $table->string('key', 32)->unique();
            $table->string('label');
            $table->string('source', 32);
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        // ---- The run: one line, one product, one shift, one day ----

        $core->create('conversion_waste_runs', function (Blueprint $table) {
            $table->id();
            // core.factories / core.machine_lines are gds-owned, so these are
            // real relationships — but left as plain indexed columns for
            // consistency with the rest of the schema, where most such columns
            // point across databases and cannot be constrained.
            $table->unsignedBigInteger('factory_id')->nullable()->index();
            $table->unsignedBigInteger('line_id')->index();
            // bil.products.productid — cross-database, so no FK.
            $table->unsignedInteger('productid')->index();
            // Denormalised for display and export: the product master is on
            // another connection and cannot be joined in one statement.
            $table->string('product_name')->nullable();
            $table->string('line_name')->nullable();
            // A real DATE. The legacy `Y/m/d` varchar has no reader here.
            $table->date('production_date');
            $table->string('shift', 16);

            // Open until someone says the waste for this run is complete.
            // Nullable rather than a status string: the timestamp IS the state,
            // and it cannot say "confirmed" without saying by whom and when.
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->unsignedBigInteger('confirmed_by')->nullable()->index();
            $table->string('confirmed_by_name')->nullable();
            // Set when a run is closed with no waste at all — a deliberate
            // "nothing to report" is a different fact from an empty run nobody
            // has looked at, and only one of them should satisfy the block.
            $table->boolean('is_nil')->default(false);
            $table->text('note')->nullable();

            $table->unsignedBigInteger('opened_by')->nullable();
            $table->timestamps();

            // The identity of a run. Also the lookup the Conversion Output block
            // makes on every save.
            $table->unique(['line_id', 'production_date', 'shift', 'productid'], 'cwr_run_unique');
            // "the previous run on this line", ordered.
            $table->index(['line_id', 'production_date', 'shift'], 'cwr_line_date_shift_idx');
            // The report's date range. Indexed from the start, and with `id`
            // after the date so the listing can be ordered ALONG the index —
            // the legacy tables had to learn that one the expensive way.
            $table->index(['production_date', 'id'], 'cwr_date_id_idx');
        });

        $core->create('conversion_waste_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('conversion_waste_runs')->cascadeOnDelete();
            $table->foreignId('cause_id')->constrained('waste_causes');
            $table->foreignId('origin_id')->constrained('waste_origins');

            // What the origin classified this against: a grade type code
            // (jumboreel_grades.gradetype) or a raw materials group name. Stored
            // by value as well as id — both lookups live on the bil connection,
            // so a join is impossible and the label has to travel with the row.
            $table->string('origin_ref')->nullable();
            $table->unsignedInteger('origin_ref_id')->nullable();

            // kg, to the gram. The legacy column was a float, which cannot hold
            // a decimal weight exactly and made totals disagree with themselves.
            $table->decimal('weight_kg', 12, 3);

            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('username')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'origin_id'], 'cwe_run_origin_idx');
            $table->index('cause_id', 'cwe_cause_idx');
        });

        // ---- Seed the vocabulary ----

        $now = now();

        DB::connection('core')->table('waste_causes')->insert(
            collect(self::CAUSES)->values()->map(fn ($name, $i) => [
                'name' => $name,
                // Spaced so an admin can slot a new cause between two without
                // renumbering the lot.
                'sort_order' => ($i + 1) * 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        DB::connection('core')->table('waste_origins')->insert(
            collect(self::ORIGINS)->map(fn ($o) => $o + [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        $core = Schema::connection('core');
        $core->dropIfExists('conversion_waste_entries');
        $core->dropIfExists('conversion_waste_runs');
        $core->dropIfExists('waste_origins');
        $core->dropIfExists('waste_causes');
    }
};
