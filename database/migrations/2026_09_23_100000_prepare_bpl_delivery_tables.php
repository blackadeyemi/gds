<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make the three BPL delivery tables safe to write from one screen.
 *
 * A delivery writes three tables at once — the form (`bpl_delivery`), one row
 * per roll (`bpl_delivery_details`) and the waybill (`bpl_delivery_barcode`) —
 * and stamps every roll's warehouse exit. All three were **MyISAM**, which has
 * no transactions, so the legacy screen's half-written deliveries (a form with
 * no waybill, rolls stamped for a delivery that never got a number) could not
 * be rolled back. InnoDB, so gds can write them as one unit.
 *
 * Indexes: `bpl_delivery_details` had none beyond its primary key, and every
 * lookup goes through `delivery_barcode` or `barcode` — the waybill printout
 * and "has this roll already gone out?" both scanned 23,860 rows.
 *
 * `delivery_barcode` is NOT made unique on `bpl_delivery_barcode`: the legacy
 * numbering (see Support\DeliveryNumber) could repeat, and the existing rows
 * have to stay as they are. gds checks for a free number instead.
 *
 * The `bil` copies are SELECT * views, so nothing there has to change.
 */
return new class extends Migration
{
    private const TABLES = ['bpl_delivery', 'bpl_delivery_details', 'bpl_delivery_barcode'];

    private const INDEXES = [
        'bpl_delivery_details' => [
            'bdd_waybill_idx' => '(`delivery_barcode`)',
            'bdd_barcode_idx' => '(`barcode`)',
            'bdd_delivery_idx' => '(`bpl_delivery_id`)',
        ],
        'bpl_delivery_barcode' => [
            'bdb_waybill_idx' => '(`delivery_barcode`)',
            'bdb_date_idx' => '(`date`)',
        ],
        'bpl_delivery' => [
            'bd_date_idx' => '(`date`)',
        ],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if ($this->engine($table) !== 'InnoDB') {
                DB::connection('bpl')->statement("ALTER TABLE `{$table}` ENGINE = InnoDB");
            }
        }

        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! $this->hasIndex($table, $name)) {
                    DB::connection('bpl')->statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if ($this->hasIndex($table, $name)) {
                    DB::connection('bpl')->statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
            }
        }

        // The engine is deliberately NOT put back: MyISAM would only lose the
        // transaction the delivery screen depends on.
    }

    private function engine(string $table): ?string
    {
        return DB::connection('bpl')->table('information_schema.tables')
            ->where('table_schema', DB::connection('bpl')->getDatabaseName())
            ->where('table_name', $table)
            ->value('ENGINE');
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::connection('bpl')->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]) !== [];
    }
};
