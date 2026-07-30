<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which pages each permission grants access to. Access-only (no per-page
 * actions): a permission simply "opens" the pages linked here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('permission_page', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->primary(['permission_id', 'page_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('permission_page');
    }
};
