<?php

namespace Modules\Bil\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Close old loadings that were never confirmed delivered.
 *
 * `sales_loading.status` holds the DELIVERY DATE and is NULL while the load is
 * still out. Loads that were never confirmed sit in the Delivery queue and in
 * the in-transit figure for ever — and some of them left the warehouse in 2018.
 * They are not deliveries anyone is still chasing; they are paperwork that was
 * never closed. This stamps them shut so the queue and the in-transit figure
 * mean "actually outstanding" again.
 *
 * WHAT IT WRITES: `status` = the row's own `dateofloading`, which is exactly
 * what SalesDeliveries::confirm() writes when a delivery is confirmed properly
 * (it stamps the loading date, not the day the button was pressed). So a closed
 * row is indistinguishable from a normally-confirmed one, and the delivered
 * figures land in the month the goods actually went out rather than piling into
 * whichever month this was run.
 *
 * WHAT IT DOES NOT WRITE: a `sales_delivery` row. confirm() creates one, with a
 * number and a barcode, because a real delivery produces a note that someone
 * signs. These loads produced no such note, and inventing thirty of them would
 * be fabricating documents. They close with no delivery barcode, and the
 * Delivery report shows a dash for it — which is the truth.
 *
 * ⚠️ It rewrites live rows. It is a DRY RUN unless you pass --apply, it writes
 * an undo file naming every id it touched before it touches them, and --reopen
 * puts them back. Take a dump first anyway.
 *
 * Stock is not affected: FinishedGoodsStock derives dispatch from
 * `dateofloading` since the cut-over, and everything this closes is older than
 * that. The command refuses to run over the cut-over for that reason.
 */
class CloseStaleLoadings extends Command
{
    protected $signature = 'bil:close-stale-loadings
        {--before= : Close loadings before this date (Y-m-d). Default: 1 January of the current year}
        {--apply : Actually write. Without it this is a dry run}
        {--reopen= : Path to an undo file — puts those rows back to unconfirmed}';

    protected $description = 'Mark never-confirmed loadings older than a date as delivered on their loading date';

    public function handle(): int
    {
        if ($file = $this->option('reopen')) {
            return $this->reopen((string) $file);
        }

        $before = $this->before();
        $cutover = str_replace('-', '/', \Modules\Bil\Support\FinishedGoodsStock::cutover());

        // Past the cut-over a loading is part of the derived stock figure, and
        // closing it would move bundles the reconciler then has to explain.
        if ($before > $cutover) {
            $this->error("--before must be on or before the finished-goods cut-over ({$cutover}).");
            $this->line('Later loadings feed the derived stock figure; close those on the Delivery screen.');

            return self::FAILURE;
        }

        $rows = DB::connection('bil')->table('sales_loading')
            ->whereNull('status')->where('dateofloading', '<', $before)
            ->orderBy('dateofloading')->orderBy('id')
            ->get(['id', 'barcode', 'dateofloading', 'loadnumber', 'quantityloaded']);

        if ($rows->isEmpty()) {
            $this->info('Nothing to close before ' . $before . '.');

            return self::SUCCESS;
        }

        $this->report($rows, $before);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Dry run — nothing written. Re-run with --apply to close them.');

            return self::SUCCESS;
        }

        $undo = $this->writeUndoFile($rows, $before);
        $this->line('Undo file: ' . $undo);

        $closed = DB::connection('bil')->transaction(function () use ($rows) {
            $done = 0;
            // Grouped by date so each UPDATE stamps one value, and chunked so a
            // large run never builds an unbounded IN list.
            foreach ($rows->groupBy('dateofloading') as $date => $group) {
                foreach ($group->pluck('id')->chunk(500) as $ids) {
                    $done += DB::connection('bil')->table('sales_loading')
                        ->whereIn('id', $ids->all())
                        ->whereNull('status')   // never re-stamp one closed meanwhile
                        ->update(['status' => $date]);
                }
            }

            return $done;
        });

        $this->newLine();
        $this->info('Closed ' . number_format($closed) . ' loading row(s).');
        $this->line('To put them back: php artisan bil:close-stale-loadings --reopen=' . $undo);

        return self::SUCCESS;
    }

    /** Default: 1 January of the current year — "older than this year". */
    private function before(): string
    {
        $opt = (string) ($this->option('before') ?: now()->startOfYear()->format('Y-m-d'));

        return str_replace('-', '/', $opt);
    }

    private function report($rows, string $before): void
    {
        $withNote = DB::connection('bil')->table('sales_loading as l')
            ->whereNull('l.status')->where('l.dateofloading', '<', $before)
            ->whereExists(fn ($q) => $q->from('sales_delivery as d')
                ->whereColumn('d.loadnumber', 'l.loadnumber')
                ->whereColumn('d.dateofdelivery', 'l.dateofloading'))
            ->count();

        $this->info('Closing loadings before ' . $before . ', stamped with their own loading date.');
        $this->table(['', ''], [
            ['Rows', number_format($rows->count())],
            ['Loads', number_format($rows->pluck('barcode')->unique()->count())],
            ['Bundles', number_format((int) $rows->sum('quantityloaded'))],
            ['Oldest', $rows->first()->dateofloading],
            ['Newest', $rows->last()->dateofloading],
            // These were delivered — the note exists, the loading row just
            // never got stamped. For them this is a repair, not a write-off.
            ['Already have a delivery note', number_format($withNote)],
            ['Nothing loaded on them', number_format($rows->where('quantityloaded', 0)->count())],
        ]);

        $this->line('By year:');
        foreach ($rows->groupBy(fn ($r) => substr($r->dateofloading, 0, 4)) as $year => $group) {
            $this->line(sprintf('  %-6s %4d row(s)  %3d load(s)  %9s bundles',
                $year, $group->count(), $group->pluck('barcode')->unique()->count(),
                number_format((int) $group->sum('quantityloaded'))));
        }
    }

    /** Every id and the value it had, written before anything is changed. */
    private function writeUndoFile($rows, string $before): string
    {
        $path = storage_path('app/close-stale-loadings-'
            . now()->format('Ymd-His') . '.json');

        file_put_contents($path, json_encode([
            'closed_at' => now()->toDateTimeString(),
            'before' => $before,
            'note' => 'status was NULL on every id below; --reopen sets them back to NULL',
            'ids' => $rows->pluck('id')->all(),
        ], JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * Put a previous run back.
     *
     * Only rows this command actually stamped, and only where `status` still
     * holds the loading date it wrote — a row someone has since delivered
     * properly is left alone rather than being torn open again.
     */
    private function reopen(string $file): int
    {
        if (! is_file($file)) {
            $this->error('No such undo file: ' . $file);

            return self::FAILURE;
        }

        $ids = json_decode((string) file_get_contents($file), true)['ids'] ?? [];

        if ($ids === []) {
            $this->error('That undo file names no rows.');

            return self::FAILURE;
        }

        $reopened = DB::connection('bil')->transaction(function () use ($ids) {
            $done = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $done += DB::connection('bil')->table('sales_loading')
                    ->whereIn('id', $chunk)
                    ->whereColumn('status', 'dateofloading')
                    ->update(['status' => null]);
            }

            return $done;
        });

        $this->info('Reopened ' . number_format($reopened) . ' of ' . count($ids) . ' loading row(s).');

        if ($reopened < count($ids)) {
            $this->line('The rest have been delivered since, and were left as they are.');
        }

        return self::SUCCESS;
    }
}
