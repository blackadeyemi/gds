<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index `barcode` on the two loading tables.
 *
 * A barcode IS a load: every screen that opens one looks up its lines by this
 * column, and the returns rolled up against it likewise. Neither was indexed —
 * the legacy pages reached them through `sod_id` — so opening a single load
 * scanned 583k loading rows and 336k return rows.
 *
 * Measured on the Loading page: the returns rollup alone was 1,225ms and the
 * line lookup 549ms, out of a 3.5s render.
 *
 * Both are extremely selective. A barcode identifies about five lines; the
 * worst bucket is 65 rows of 583,096 (0.011%) and 51 of 336,372 (0.015%).
 */
return new class extends Migration
{
    private const INDEXES = [
        'sales_loading' => 'sl_barcode_idx',
        'sales_loading_return' => 'slr_barcode_idx',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $name) {
            if ($this->missing($table, $name)) {
                DB::connection('bil')->statement(
                    "ALTER TABLE `{$table}` ADD INDEX `{$name}` (`barcode`), ALGORITHM=INPLACE, LOCK=NONE"
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $name) {
            if (! $this->missing($table, $name)) {
                DB::connection('bil')->statement(
                    "ALTER TABLE `{$table}` DROP INDEX `{$name}`, ALGORITHM=INPLACE, LOCK=NONE"
                );
            }
        }
    }

    private function missing(string $table, string $name): bool
    {
        return ! collect(DB::connection('bil')->select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($i) => $i->Key_name === $name);
    }
};
