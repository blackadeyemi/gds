<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jumbo Rolls → Factory Entrance: record WHICH gate a reel came through.
 *
 * `factory_entrance_reel` only ever stored `location` — the factory NAME the
 * legacy dropdown carried as its option value (Bil-1, Bil-2, Gambini, and the
 * one-off "Oregun Store"). That is still written, because the legacy jumbo
 * screens read it; `gate_id` is added alongside it exactly as it was for the
 * four raw-material movement tables, so the gds screen records the gate the
 * operator actually picked.
 *
 * Additive and nullable: the legacy inserts keep working untouched.
 *
 * Backfillable, unlike the warehouse movements — every historic `location` is
 * a factory whose inbound gate is unambiguous, so old rows resolve exactly
 * rather than being guessed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bil = DB::connection('bil');

        if (! Schema::connection('bil')->hasColumn('factory_entrance_reel', 'gate_id')) {
            $bil->statement('ALTER TABLE `factory_entrance_reel` ADD COLUMN `gate_id` INT NULL');
            $bil->statement('ALTER TABLE `factory_entrance_reel` ADD INDEX `factory_entrance_reel_gate_id_idx` (`gate_id`)');
        }

        // location (a factory code, or the "Oregun Store" pseudo-factory) -> inbound gate.
        $gates = DB::connection('core')->table('factory_gates as g')
            ->leftJoin('factories as f', 'f.id', '=', 'g.factory_id')
            ->whereIn('g.direction', ['in', 'both'])
            ->get(['g.id', 'g.name', 'f.code']);

        foreach ($gates as $gate) {
            $location = $gate->code ?? $gate->name;   // Oregun Store has no factory
            $bil->table('factory_entrance_reel')
                ->where('location', $location)->update(['gate_id' => $gate->id]);
        }
    }

    public function down(): void
    {
        if (Schema::connection('bil')->hasColumn('factory_entrance_reel', 'gate_id')) {
            DB::connection('bil')->statement('ALTER TABLE `factory_entrance_reel` DROP COLUMN `gate_id`');
        }
    }
};
