<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Department → Division → Staff, replacing the free-text `department` and
 * `division` columns on the legacy bil.factory_staff table.
 *
 * Department stays the top level (MAINTENANCE, CONVERSION) and lives in the
 * existing core.departments alongside the org units user accounts already use
 * (Admin, BILFactories, MARKETING …) — one vocabulary, so a user and a staff
 * member can share a department.
 *
 * Divisions are the second level with the redundant prefix stripped: the legacy
 * strings spell them "MAINTENANCE ELECTRICAL" / "PRODUCTION SUPERVISOR", which
 * repeat (or, for CONVERSION, contradict) the parent department. `legacy_name`
 * keeps the original string so the factory_staff compatibility view and the
 * name→id triggers on factory_machine_maintenance still resolve — the same
 * trick machine_lines.legacy_alias uses for factory_details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('divisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            // The full legacy string, e.g. "MAINTENANCE ELECTRICAL". Nullable:
            // divisions created from the UI have no legacy spelling.
            $table->string('legacy_name')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['department_id', 'name']);
        });

        Schema::connection('core')->create('staff', function (Blueprint $table) {
            $table->id();
            // The payroll number. The legacy column is called `staff_id`, which
            // reads like a foreign key next to department_id/division_id — it
            // isn't, so it is `staff_no` here and the view aliases it back.
            $table->unsignedInteger('staff_no')->nullable()->index();
            $table->string('name');
            $table->foreignId('department_id')->constrained('departments')->cascadeOnUpdate()->restrictOnDelete();
            // Optional: a staff member may sit in a department with no division.
            $table->foreignId('division_id')->nullable()->constrained('divisions')->cascadeOnUpdate()->restrictOnDelete();
            // Optional link to a login. None of the 70 legacy staff match a user
            // account today, so every migrated row starts unlinked.
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // NOT unique: "OTHERS" is a per-division placeholder and exists four
            // times. That is why maintenance history resolves staff on
            // (division, name) rather than name alone — a name-only join returns
            // 43,709 rows for a 43,401-row table because OTHERS fans out.
            // Uniqueness within a division is enforced in Admin > Staff, where a
            // NULL division can still be checked properly.
            $table->index(['division_id', 'name']);
        });

        Schema::connection('core')->table('user', function (Blueprint $table) {
            $table->unsignedBigInteger('division_id')->nullable()->after('department_id');
            $table->index('division_id');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('user', function (Blueprint $table) {
            $table->dropIndex(['division_id']);
            $table->dropColumn('division_id');
        });

        Schema::connection('core')->dropIfExists('staff');
        Schema::connection('core')->dropIfExists('divisions');
    }
};
