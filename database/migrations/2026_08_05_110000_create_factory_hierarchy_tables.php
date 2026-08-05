<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Company -> Factory -> Line (-> sub-line) -> Project (-> sub-project) spine.
 *
 * Replaces five overlapping, name-joined copies of the same tree in the legacy
 * bil schema (factory_lines, factory_sublines, factory_projects,
 * factory_subprojects, factory_details) plus the free-text papermachine on the
 * bpl production tables. Those become compatibility views over these tables.
 *
 * Sub-lines and sub-projects are the SAME table as their parents, via a
 * self-referencing parent_id. Two reasons: the columns are identical, and the
 * legacy consumer columns are ambiguous by nature — factory_usage_rawmaterials
 * .linename holds a line name in 80,870 rows and a sub-line name in 19,537.
 * One table means both resolve to one node id with a single lookup.
 *
 * `name` is globally UNIQUE on both node tables and that is load-bearing, not
 * cosmetic: the compatibility views project these names back out to the legacy
 * app, and every consumer table still joins on them. The index is what stops a
 * future rename from quietly making two rows indistinguishable to legacy code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('factories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 16)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
        });

        Schema::connection('core')->create('machine_lines', function (Blueprint $table) {
            $table->id();
            // Nullable: COMPRESSOR, ELECTRICAL LIFTER and MANUAL LIFTER are
            // site-wide equipment that has never been filed under a factory.
            $table->foreignId('factory_id')->nullable()->constrained('factories')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('machine_lines')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name')->unique();
            $table->string('code', 16)->nullable();

            // --- legacy factory_details carriers -------------------------------
            // factory_details was a 4th copy of this tree that spelled three
            // machines differently from factory_lines (FACIAL vs FACIAL TISSUE,
            // Handkerchief vs HANKERCHIEF, Aluminium Foil vs ALUMINIUM FOIL) and
            // carried a bespoke code per machine (e.g. NPK-OM1-OM001).
            //
            // We canonicalise on the factory_lines spelling, but the legacy app is
            // still live and still GROUP BYs those name strings, so the rebuilt
            // factory_details view has to emit the old spelling verbatim or a
            // report would split "FACIAL" and "FACIAL TISSUE" into two rows. These
            // three columns exist only to reproduce that view exactly; drop all
            // three once Consumption and the legacy reports read line_id instead.
            $table->unsignedInteger('detail_id')->nullable();
            $table->string('detail_code')->nullable();
            $table->string('legacy_alias')->nullable();
            // -------------------------------------------------------------------

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id', 'machine_lines_parent_idx');
        });

        Schema::connection('core')->create('machine_projects', function (Blueprint $table) {
            $table->id();
            // Points at whichever line node owns it — usually a sub-line, but
            // two Gambini projects hang off the REW 11 line directly.
            $table->foreignId('line_id')->constrained('machine_lines')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('machine_projects')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name')->unique();
            $table->string('code', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id', 'machine_projects_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('machine_projects');
        Schema::connection('core')->dropIfExists('machine_lines');
        Schema::connection('core')->dropIfExists('factories');
    }
};
