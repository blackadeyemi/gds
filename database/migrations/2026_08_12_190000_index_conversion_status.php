<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index `factory_conversion.status`.
 *
 * Factory Floor Stock is defined by `status IS NULL` — 1,278 pallets out of
 * 1.2M rows — and without an index every view, sort and filter on that report
 * is a full scan. The report was unusable before this.
 *
 * The column is heavily skewed (1.2M 'yes' against ~1,300 NULL), which is
 * exactly the shape an index serves well: the selective side is the one being
 * queried.
 */
return new class extends Migration
{
    private const NAME = 'fc_status_idx';

    public function up(): void
    {
        if (! $this->hasIndex()) {
            DB::connection('bil')->statement(
                'ALTER TABLE `factory_conversion` ADD INDEX `' . self::NAME . '` (`status`)'
            );
        }
    }

    public function down(): void
    {
        if ($this->hasIndex()) {
            DB::connection('bil')->statement('ALTER TABLE `factory_conversion` DROP INDEX `' . self::NAME . '`');
        }
    }

    private function hasIndex(): bool
    {
        return collect(DB::connection('bil')->select('SHOW INDEX FROM `factory_conversion`'))
            ->contains(fn ($i) => $i->Key_name === self::NAME);
    }
};
