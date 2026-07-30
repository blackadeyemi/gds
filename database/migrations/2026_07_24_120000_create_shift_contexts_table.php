<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shift context is a named area where Day/Night (or any named) shifts apply
 * — e.g. bpl.production, bpl.store_exit, bil.factory_entrance. Each is
 * independent, so two areas in the same module can keep different shift times.
 * `is_active` is the master gate: off means the area is ungated (open anytime).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('shift_contexts', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('module')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('shift_contexts');
    }
};
