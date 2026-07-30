<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named time window within a context (Day, Night, Afternoon, …). A window
 * may wrap midnight (start 19:00, end 07:00). A context is "open now" when the
 * current time falls inside any enabled window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('shift_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_context_id')->constrained('shift_contexts')->cascadeOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('shift_windows');
    }
};
