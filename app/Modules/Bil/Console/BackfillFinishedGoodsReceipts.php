<?php

namespace Modules\Bil\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import the legacy `store_entrance` receipts so the Warehouse Entrance report
 * shows the full history, not just what gds has taken in.
 *
 * A COMMAND, NOT A MIGRATION, on purpose: every legacy gate must be attached to
 * a warehouse first, and only a person can decide which. A migration would
 * either fail on a half-configured system or silently guess.
 *
 * Imported rows are flagged `is_historic`, so they appear in reports and are
 * excluded from stock — nine years of arrivals are not goods on the floor. See
 * FinishedGoodsStock.
 *
 * Idempotent: rows carry the `store_entrance.id` they came from, so re-running
 * imports only what is missing. Safe to stop and resume.
 */
class BackfillFinishedGoodsReceipts extends Command
{
    protected $signature = 'bil:backfill-fg-receipts
                            {--apply : Write the rows (without this it only reports)}
                            {--chunk=2000 : Rows per insert}';

    protected $description = 'Import legacy store_entrance rows as historic finished-goods receipts';

    public function handle(): int
    {
        $core = DB::connection('core');
        $bil = DB::connection('bil');

        // Legacy gate name -> [gate id, warehouse id]. Only gates attached to a
        // warehouse can take receipts; the rest are reported and skipped.
        $gates = $core->table('warehouse_gates')
            ->whereNotNull('legacy_name')->whereNull('deleted_at')
            ->get(['id', 'name', 'legacy_name', 'warehouse_id'])
            ->keyBy('legacy_name');

        $locations = $bil->table('store_entrance')
            ->groupBy('entrancelocation')
            // NB: don't alias this `rows` — ROWS is reserved in MySQL 8.
            ->selectRaw('entrancelocation, COUNT(*) as total')
            ->pluck('total', 'entrancelocation');

        $this->line('Legacy entrance locations:');
        $blocked = 0;
        $rows = [];
        foreach ($locations as $name => $count) {
            $gate = $gates[$name] ?? null;
            $state = match (true) {
                ! $gate => '<fg=red>no matching gate</>',
                ! $gate->warehouse_id => '<fg=red>gate has no warehouse</>',
                default => '<fg=green>-> ' . $gate->name . '</>',
            };
            $this->line(sprintf('  %-28s %8s  %s', $name, number_format($count), $state));

            if (! $gate || ! $gate->warehouse_id) {
                $blocked += $count;
            } else {
                $rows[$name] = $gate;
            }
        }

        if ($blocked > 0) {
            $this->newLine();
            $this->warn(number_format($blocked) . ' row(s) cannot be imported yet.');
            $this->line('Attach every entrance to a warehouse first: Admin -> Warehouse Gates.');
        }

        if ($rows === []) {
            $this->error('Nothing to import.');

            return self::FAILURE;
        }

        $already = (int) $core->table('finished_goods_warehouse_receipts')->whereNotNull('legacy_id')->count();
        $importable = $bil->table('store_entrance')->whereIn('entrancelocation', array_keys($rows))->count();

        $this->newLine();
        $this->line('Importable: ' . number_format($importable) . '   already imported: ' . number_format($already));

        if (! $this->option('apply')) {
            $this->warn('Dry run — re-run with --apply to write the rows.');

            return self::SUCCESS;
        }

        // Products the finished-goods master still knows about. A receipt whose
        // product has since been deleted is still imported — the report should
        // show what happened, and the product name simply renders as a dash.
        $chunk = max(200, (int) $this->option('chunk'));
        $imported = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar($importable);
        $bar->start();

        $bil->table('store_entrance')
            ->whereIn('entrancelocation', array_keys($rows))
            ->orderBy('id')
            ->chunkById($chunk, function ($batch) use ($core, $rows, &$imported, &$skipped, $bar) {
                $existing = $core->table('finished_goods_warehouse_receipts')
                    ->whereIn('legacy_id', $batch->pluck('id'))
                    ->pluck('legacy_id')->flip();

                // A barcode already present as a LIVE receipt must not be
                // duplicated — `barcode` is unique, and the live row is the
                // authoritative one.
                $liveBarcodes = $core->table('finished_goods_warehouse_receipts')
                    ->whereIn('barcode', $batch->pluck('barcode'))
                    ->pluck('barcode')->flip();

                $insert = [];
                foreach ($batch as $row) {
                    if (isset($existing[$row->id]) || isset($liveBarcodes[$row->barcode])) {
                        $skipped++;
                        continue;
                    }

                    $gate = $rows[$row->entrancelocation];
                    $insert[] = [
                        'barcode' => $row->barcode,
                        'entrance_id' => $gate->id,
                        'warehouse_id' => $gate->warehouse_id,
                        'productid' => (int) $row->productid,
                        'bundles' => (int) $row->bundles,
                        // Legacy `Y/m/d` varchar -> a real date.
                        'date_of_entrance' => str_replace('/', '-', $row->dateofentrance),
                        'user_id' => null,
                        'username' => $row->username,
                        'is_historic' => true,
                        'legacy_id' => $row->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($insert !== []) {
                    $core->table('finished_goods_warehouse_receipts')->insert($insert);
                    $imported += count($insert);
                }

                $bar->advance($batch->count());
            });

        $bar->finish();
        $this->newLine(2);
        $this->info('Imported ' . number_format($imported) . ' historic receipt(s); skipped '
            . number_format($skipped) . ' already present.');
        $this->line('These are flagged historic and do NOT count toward stock.');

        return self::SUCCESS;
    }
}
