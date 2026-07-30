<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qualify functional-area modules by company so the same area name can exist
 * under different companies (e.g. BPL / Jumbo Rolls today, BIL / Jumbo Rolls
 * later). Adds a `company` column, backfills it, and makes the slugs
 * company-qualified. Cross-cutting modules (Admin, Reports) stay company-less.
 */
return new class extends Migration
{
    /** current slug => [company, new slug] */
    private array $map = [
        'factory' => ['BIL', 'bil-factory'],
        'raw-materials' => ['BIL', 'bil-raw-materials'],
        'store' => ['BIL', 'bil-store'],
        'sales' => ['BIL', 'bil-sales'],
        'quality' => ['BIL', 'bil-quality'],
        'jumbo-rolls' => ['BPL', 'bpl-jumbo-rolls'],
    ];

    public function up(): void
    {
        Schema::connection('core')->table('application_modules', function (Blueprint $table) {
            $table->string('company')->nullable()->after('name');
        });

        foreach ($this->map as $oldSlug => [$company, $newSlug]) {
            DB::connection('core')->table('application_modules')
                ->where('slug', $oldSlug)
                ->update(['company' => $company, 'slug' => $newSlug]);
        }
    }

    public function down(): void
    {
        foreach ($this->map as $oldSlug => [, $newSlug]) {
            DB::connection('core')->table('application_modules')
                ->where('slug', $newSlug)
                ->update(['slug' => $oldSlug]);
        }

        Schema::connection('core')->table('application_modules', function (Blueprint $table) {
            $table->dropColumn('company');
        });
    }
};
