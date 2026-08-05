<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classify what a machine service job actually was — Maintenance, Repair, and
 * whatever else gets added later.
 *
 * A lookup table rather than an enum column, matching how divisions/factories
 * are modelled here: the set will grow, and it should be editable without a
 * migration (Settings > Service Types).
 *
 * `service_type_id` is nullable and the 43,401 existing rows are left NULL. The
 * legacy screen never captured this, so every historic value would be a guess —
 * the notes make clear that some are repairs and some are routine servicing.
 * They report as "Unclassified" until someone chooses to categorise them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        DB::connection('core')->table('service_types')->insert([
            ['name' => 'Maintenance', 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Repair', 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::connection('bil')->statement(
            'ALTER TABLE `factory_machine_maintenance` ADD COLUMN `service_type_id` INT NULL'
        );
        DB::connection('bil')->statement(
            'ALTER TABLE `factory_machine_maintenance` ADD INDEX `fmm_service_type_id_idx` (`service_type_id`)'
        );
    }

    public function down(): void
    {
        DB::connection('bil')->statement(
            'ALTER TABLE `factory_machine_maintenance` DROP COLUMN `service_type_id`'
        );
        Schema::connection('core')->dropIfExists('service_types');
    }
};
