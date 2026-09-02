<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The date a factory event happened, as opposed to the moment it was typed.
 *
 * `factory_event` only ever carried a unix `timestamp`, so a remainder logged
 * at 01:00 for the shift that ended at midnight, or a return keyed in the
 * morning after the truck left, was dated wrong and could not be corrected.
 * Every other movement table in this module carries its own date for exactly
 * that reason — `dateofentrance`, `dateofuse`, `dateofexit`.
 *
 * `date` rather than `dateofreturn`: the table holds both 'remain' and 'return'
 * events, and both want it.
 *
 * Legacy 'Y/m/d' text to match the columns beside it, so the legacy screens and
 * reports compare it the way they compare the others. Backfilled from the
 * timestamp already stored, so the column is complete from the day it lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bil = DB::connection('bil');

        if (Schema::connection('bil')->hasColumn('factory_event', 'date')) {
            return;
        }

        $bil->statement('ALTER TABLE `factory_event` ADD COLUMN `date` VARCHAR(20) NULL AFTER `reason`');
        $bil->statement('ALTER TABLE `factory_event` ADD INDEX `factory_event_date_idx` (`date`)');

        // What the row already knew, in the shape the rest of the module uses.
        $bil->statement(
            "UPDATE `factory_event` SET `date` = FROM_UNIXTIME(`timestamp`, '%Y/%m/%d')"
            . ' WHERE `date` IS NULL AND `timestamp` > 0'
        );
    }

    public function down(): void
    {
        if (Schema::connection('bil')->hasColumn('factory_event', 'date')) {
            DB::connection('bil')->statement('ALTER TABLE `factory_event` DROP INDEX `factory_event_date_idx`');
            DB::connection('bil')->statement('ALTER TABLE `factory_event` DROP COLUMN `date`');
        }
    }
};
