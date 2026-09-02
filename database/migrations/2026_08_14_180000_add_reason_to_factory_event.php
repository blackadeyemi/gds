<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why a jumbo roll went back to BPL.
 *
 * `factory_event` recorded that a reel was returned and how much of it, but
 * never why — so a run of returns could be counted and not explained, and the
 * question "what keeps coming back, and for what?" had no answer in the data.
 *
 * Optional by design: the operator at the gate may not know the reason, and a
 * required field there would only be filled with noise. Nullable and additive,
 * so the legacy screens — which name their columns on insert and never read
 * this one — are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('bil')->hasColumn('factory_event', 'reason')) {
            DB::connection('bil')->statement(
                'ALTER TABLE `factory_event` ADD COLUMN `reason` VARCHAR(255) NULL AFTER `event`'
            );
        }
    }

    public function down(): void
    {
        if (Schema::connection('bil')->hasColumn('factory_event', 'reason')) {
            DB::connection('bil')->statement('ALTER TABLE `factory_event` DROP COLUMN `reason`');
        }
    }
};
