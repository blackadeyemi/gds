<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pages are the unit of access control. Seeded from config/pages.php by
 * gds:sync-pages. A permission grants access to a set of pages via the
 * permission_page pivot; a page is reachable if any of the user's permissions
 * include it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('module')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('pages');
    }
};
