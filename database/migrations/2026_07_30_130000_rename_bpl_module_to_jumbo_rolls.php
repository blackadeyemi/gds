<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the "BPL" application module to "Jumbo Rolls" so permission grouping is
 * area-based like BIL's "Raw Materials" (BPL is the company; Jumbo Rolls is its
 * functional area). Permission names (edit-bpl, …) are unchanged. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('core')->table('application_modules')
            ->where('slug', 'bpl')
            ->update(['name' => 'Jumbo Rolls', 'slug' => 'jumbo-rolls']);
    }

    public function down(): void
    {
        DB::connection('core')->table('application_modules')
            ->where('slug', 'jumbo-rolls')
            ->update(['name' => 'BPL', 'slug' => 'bpl']);
    }
};
