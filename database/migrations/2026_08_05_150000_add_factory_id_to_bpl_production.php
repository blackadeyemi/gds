<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Point BPL production at a real factory instead of the free-text papermachine.
 *
 * bpl_production.papermachine is a varchar holding 'PM2' (58,627 rows) / 'PM3'
 * (217,304) / blank (186); bpl_softroll_production.papermachine is an int, and
 * every one of its 1,650 rows is 3 — i.e. PM3. Both now also carry factory_id,
 * pointing at the PM2 / PM3 factories created under Belpapyrus.
 *
 * Purely additive: no gds read path touches papermachine today, and the legacy
 * app keeps reading and writing the papermachine columns exactly as before. The
 * triggers keep factory_id in step with legacy writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bpl = DB::connection('bpl');
        $factories = DB::connection('core')->table('factories')
            ->whereIn('code', ['PM2', 'PM3'])->pluck('id', 'code');

        foreach (['bpl_production', 'bpl_softroll_production'] as $table) {
            $bpl->statement("ALTER TABLE `{$table}` ADD COLUMN `factory_id` INT NULL");
            $bpl->statement("ALTER TABLE `{$table}` ADD INDEX `{$table}_factory_id_idx` (`factory_id`)");
        }

        // Hardroll: papermachine is the code itself. Blanks stay NULL.
        foreach ($factories as $code => $id) {
            $bpl->table('bpl_production')->where('papermachine', $code)->update(['factory_id' => $id]);
        }

        // Softroll: papermachine is an int machine number, so 3 => PM3.
        $bpl->table('bpl_softroll_production')->where('papermachine', 3)
            ->update(['factory_id' => $factories['PM3']]);

        $bpl->unprepared("DROP TRIGGER IF EXISTS `bpl_production_ins_factory`");
        $bpl->unprepared("
            CREATE TRIGGER `bpl_production_ins_factory` BEFORE INSERT ON `bpl_production`
            FOR EACH ROW
            BEGIN
                SET NEW.`factory_id` = (
                    SELECT f.id FROM core.factories f
                    WHERE f.code = NEW.`papermachine` AND f.deleted_at IS NULL LIMIT 1
                );
            END
        ");
        $bpl->unprepared("DROP TRIGGER IF EXISTS `bpl_production_upd_factory`");
        $bpl->unprepared("
            CREATE TRIGGER `bpl_production_upd_factory` BEFORE UPDATE ON `bpl_production`
            FOR EACH ROW
            BEGIN
                SET NEW.`factory_id` = (
                    SELECT f.id FROM core.factories f
                    WHERE f.code = NEW.`papermachine` AND f.deleted_at IS NULL LIMIT 1
                );
            END
        ");

        // Softroll's papermachine is numeric, so the code is 'PM' + the number.
        foreach (['ins' => 'INSERT', 'upd' => 'UPDATE'] as $suffix => $event) {
            $bpl->unprepared("DROP TRIGGER IF EXISTS `bpl_softroll_production_{$suffix}_factory`");
            $bpl->unprepared("
                CREATE TRIGGER `bpl_softroll_production_{$suffix}_factory` BEFORE {$event} ON `bpl_softroll_production`
                FOR EACH ROW
                BEGIN
                    SET NEW.`factory_id` = (
                        SELECT f.id FROM core.factories f
                        WHERE f.code = CONCAT('PM', NEW.`papermachine`) AND f.deleted_at IS NULL LIMIT 1
                    );
                END
            ");
        }
    }

    public function down(): void
    {
        $bpl = DB::connection('bpl');

        foreach (['bpl_production_ins_factory', 'bpl_production_upd_factory',
                  'bpl_softroll_production_ins_factory', 'bpl_softroll_production_upd_factory'] as $trigger) {
            $bpl->unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");
        }

        foreach (['bpl_production', 'bpl_softroll_production'] as $table) {
            $bpl->statement("ALTER TABLE `{$table}` DROP COLUMN `factory_id`");
        }
    }
};
