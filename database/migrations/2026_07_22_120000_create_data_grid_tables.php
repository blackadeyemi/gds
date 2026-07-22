<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backing tables for the DataGrid framework. `data_pages` + `data_views`
 * are synced from each grid's code-declared views (gds:sync-data-views) and
 * are what the admin "Data Views" settings screen checklists: which views a
 * page exposes, the default view, and the default page size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_pages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();      // e.g. admin.departments
            $table->string('label');
            $table->unsignedSmallInteger('per_page')->default(10);
            $table->timestamps();
        });

        Schema::create('data_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_page_id')->constrained('data_pages')->cascadeOnDelete();
            $table->string('key');                 // e.g. default, by_company
            $table->string('label');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['data_page_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_views');
        Schema::dropIfExists('data_pages');
    }
};
