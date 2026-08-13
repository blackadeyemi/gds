<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings an administrator may change at runtime, without a deploy.
 *
 * Deliberately small and deliberately audited. Most configuration belongs in
 * `config/*.php` where it is version-controlled and reviewed; this table is for
 * the handful of values an operations manager legitimately needs to move
 * between deploys — starting with the conversion-waste cut-over.
 *
 * The audit columns are not decoration. The first setting stored here decides
 * which production still owes waste, so moving it forward makes an unconfirmed
 * backlog disappear. That has to be attributable, and it has to be visible on
 * the page that does it.
 *
 * A row here OVERRIDES the matching config value; deleting the row falls back
 * to config (and therefore to .env). See Core\Support\Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('app_settings', function (Blueprint $table) {
            // The config key it overrides, e.g. 'waste.confirmation_start'.
            $table->string('key', 128)->primary();
            $table->text('value')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('updated_by_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('app_settings');
    }
};
