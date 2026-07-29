<?php

namespace Modules\Bil\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild the `rawmaterials_stock` aggregate from the barcode truth.
 *
 * The per-product/location aggregate had drifted far from reality — years of
 * legacy processes that incremented it but didn't always decrement (it reported
 * ~8.5x the stock the in-store barcodes actually account for). The barcodes in
 * `rawmaterials_warehouse_entry` with `status IS NULL` (still in store) are the
 * source of truth, so this recomputes each stock line's quantity (= count of
 * in-store barcodes) and weight (= their summed weight):
 *
 *   - product/location that has in-store barcodes  → quantity + weight set to the rollup
 *   - stock line whose product no longer has any    → zeroed (kept, not deleted, so
 *     the modification audit + the legacy row survive)
 *
 * Each touched line gets a "Reconciled from barcodes" entry appended to its
 * `modification` JSON audit. Idempotent, and transactional (the table is
 * InnoDB). `rawmaterials_stock` is read by several still-live legacy pages, so
 * back it up first (mysqldump) and use --dry-run to preview.
 */
class ReconcileWarehouseStock extends Command
{
    protected $signature = 'bil:reconcile-warehouse-stock {--dry-run : Show the plan without writing anything}';

    protected $description = 'Rebuild rawmaterials_stock (quantity + weight) from in-store barcodes';

    public function handle(): int
    {
        $bil = DB::connection('bil');
        $dry = (bool) $this->option('dry-run');

        $locNames = $bil->table('rawmaterial_store_location')->pluck('location', 'id')->all();

        // In-store units per (location_id, product) — the barcode truth.
        $rollup = $bil->table('rawmaterials_warehouse_entry')
            ->whereNull('status')
            ->selectRaw('location_id, productid, COUNT(*) AS qty, SUM(weight) AS wt')
            ->groupBy('location_id', 'productid')
            ->get();

        $target = [];   // "location|productid" => [qty, wt]
        $skipped = 0;
        foreach ($rollup as $r) {
            $loc = $locNames[$r->location_id] ?? null;
            if ($loc === null) {
                $skipped += (int) $r->qty;   // barcode at a location with no name row
                continue;
            }
            $target[$loc . '|' . $r->productid] = [(int) $r->qty, (float) $r->wt];
        }

        $existing = $bil->table('rawmaterials_stock')
            ->get(['id', 'location', 'productid', 'quantity'])
            ->keyBy(fn ($s) => $s->location . '|' . $s->productid);

        // Build the plan: upsert every rollup, zero every stale line.
        $toUpsert = [];   // [id|null, location, productid, qty, wt]
        foreach ($target as $key => [$qty, $wt]) {
            [$loc, $pid] = explode('|', $key, 2);
            $toUpsert[] = [$existing->get($key)->id ?? null, $loc, (int) $pid, $qty, $wt];
        }
        $toZero = [];
        foreach ($existing as $key => $row) {
            if (! isset($target[$key]) && (int) $row->quantity !== 0) {
                $toZero[] = (int) $row->id;
            }
        }

        $insert = count(array_filter($toUpsert, fn ($u) => $u[0] === null));
        $update = count($toUpsert) - $insert;
        $totalQty = array_sum(array_map(fn ($u) => $u[3], $toUpsert));
        $totalKg = round(array_sum(array_map(fn ($u) => $u[4], $toUpsert)));

        $this->info(sprintf(
            'Plan: %d update, %d insert, %d zero.%s',
            $update, $insert, count($toZero),
            $skipped ? "  ($skipped barcodes skipped — unknown location)" : ''
        ));
        $this->line(sprintf(
            'Reconciled stock: %s units / %s kg across %d lines.',
            number_format($totalQty), number_format($totalKg), count($toUpsert)
        ));

        if ($dry) {
            $this->warn('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $stamp = json_encode([
            'description' => 'Reconciled from barcodes',
            'user' => 'system',
            'timestamp' => now()->timestamp,
        ]);

        $bil->transaction(function () use ($bil, $toUpsert, $toZero, $stamp) {
            foreach ($toUpsert as [$id, $loc, $pid, $qty, $wt]) {
                if ($id === null) {
                    $bil->table('rawmaterials_stock')->insert([
                        'location' => $loc,
                        'productid' => $pid,
                        'quantity' => $qty,
                        'weight' => $wt,
                        'modification' => '[' . $stamp . ']',
                    ]);
                } else {
                    $this->setLine($bil, $id, $qty, $wt, $stamp);
                }
            }
            foreach ($toZero as $id) {
                $this->setLine($bil, $id, 0, 0.0, $stamp);
            }
        });

        $this->info('Done — rawmaterials_stock reconciled from in-store barcodes.');

        return self::SUCCESS;
    }

    /** Set a line's quantity/weight and append the audit entry (JSON_VALID-guarded). */
    private function setLine($bil, int $id, int $qty, float $wt, string $entry): void
    {
        $bil->statement(
            'UPDATE `rawmaterials_stock` SET `quantity` = ?, `weight` = ?, '
            . "`modification` = JSON_ARRAY_APPEND(IF(JSON_VALID(`modification`), `modification`, JSON_ARRAY()), '$', CAST(? AS JSON)) "
            . 'WHERE `id` = ?',
            [$qty, $wt, $entry, $id]
        );
    }
}
