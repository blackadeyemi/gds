<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Move to per-page abilities: each page stores the abilities it supports, and
 * access is granted as "{key}:{ability}" permissions assigned directly to roles
 * (gds:sync-pages materializes them). The old access-only bundle pivot
 * (permission_page) is no longer used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('pages', function (Blueprint $table) {
            $table->json('abilities')->nullable()->after('module');
        });

        Schema::connection('core')->dropIfExists('permission_page');
    }

    public function down(): void
    {
        Schema::connection('core')->table('pages', function (Blueprint $table) {
            $table->dropColumn('abilities');
        });

        Schema::connection('core')->create('permission_page', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->primary(['permission_id', 'page_id']);
        });
    }
};
