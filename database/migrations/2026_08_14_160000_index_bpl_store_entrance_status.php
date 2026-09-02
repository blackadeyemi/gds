<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index the "still in a BPL store" lookup.
 *
 * `bpl_storeentrance` holds 131k rows and had only PRIMARY and a unique
 * barcode, so finding the ~1,000 reels still standing in a store
 * (`status IS NULL`) meant a full scan. That set is a live BIL stock position —
 * jumbo rolls BPL made for BIL and is holding in its own warehouses — so the
 * Jumbo Rolls Stock page reads it on every render.
 */
return new class extends Migration
{
    private const NAME = 'bpl_storeentrance_status_idx';

    public function up(): void
    {
        if ($this->missing()) {
            DB::connection('bpl')->statement(
                'ALTER TABLE `bpl_storeentrance` ADD INDEX `' . self::NAME . '` (`status`, `deleted_at`, `location_id`)'
            );
        }
    }

    public function down(): void
    {
        if (! $this->missing()) {
            DB::connection('bpl')->statement('ALTER TABLE `bpl_storeentrance` DROP INDEX `' . self::NAME . '`');
        }
    }

    private function missing(): bool
    {
        return DB::connection('bpl')
            ->select('SHOW INDEX FROM `bpl_storeentrance` WHERE Key_name = ?', [self::NAME]) === [];
    }
};