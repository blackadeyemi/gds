<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Activity\Retention;

/**
 * Drop activity older than the retention period. Items and notifications go
 * with their activity (ON DELETE CASCADE).
 *
 * The period is Settings → Activity Log (Admin only), falling back to
 * config('activity.retention_days'). See Support\Activity\Retention.
 *
 * The only thing that ever deletes from the log. Scheduled daily in
 * routes/console.php, so a shorter setting takes effect the next night;
 * deletes in id order, in chunks, so a first run over a large backlog does not
 * hold one long lock.
 */
class PruneActivity extends Command
{
    protected $signature = 'gds:prune-activity
        {--days= : Keep this many days instead of the configured retention}
        {--apply : Actually delete. Without it this is a dry run}';

    protected $description = 'Delete activity log entries past the retention period';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : Retention::days();
        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = Retention::cutoff($days);
        $core = DB::connection('core');
        $highest = $core->table('activities')->where('created_at', '<', $cutoff)->max('id');

        if (! $highest) {
            $this->info("Nothing older than {$cutoff->toDateString()} ({$days} days).");

            return self::SUCCESS;
        }

        $n = $core->table('activities')->where('id', '<=', $highest)->count();

        if (! $this->option('apply')) {
            $this->warn(number_format($n) . " activities before {$cutoff->toDateString()} ({$days} days) would be deleted. Re-run with --apply.");

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $batch = $core->table('activities')->where('id', '<=', $highest)->orderBy('id')->limit(2000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info(number_format($deleted) . " activities before {$cutoff->toDateString()} ({$days} days) deleted.");

        return self::SUCCESS;
    }
}
