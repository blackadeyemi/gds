<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Monthly legacy-data refresh. Loads the per-table `bilfg` production dump into
 * a staging schema, then TRUNCATE + INSERT (common columns) each allowlisted
 * OPERATIONAL table into its gds target — drift-proof, trigger-driven hierarchy
 * re-derivation, archive-first. Config: config/legacy_refresh.php.
 *
 * Always rehearse with --dry-run first; the real run needs --force.
 */
class RefreshLegacy extends Command
{
    protected $signature = 'gds:refresh-legacy
        {--profile=legacy_refresh : Config profile to drive the run (e.g. legacy_refresh_bhn)}
        {--path= : Dump directory (per_file) or dump file (single_dump); overrides config}
        {--only= : Comma-separated subset of legacy table names}
        {--dry-run : Show the plan + column mapping; change nothing}
        {--no-archive : Skip the pre-truncate mysqldump of each target}
        {--keep-staging : Keep the staging DB after the run}
        {--force : Required to actually modify data}';

    protected $description = 'Refresh operational data tables from a legacy dump (bilfg per-file, or a single-dump profile like BHN)';

    public function handle(): int
    {
        $profile = (string) ($this->option('profile') ?: 'legacy_refresh');
        $cfg = config($profile);
        if (! $cfg) {
            $this->error("Unknown refresh profile: {$profile}");

            return self::FAILURE;
        }
        $single = ($cfg['source'] ?? 'per_file') === 'single_dump';
        $stageConn = $cfg['staging_conn'] ?? 'bil';
        $stage = $cfg['staging_db'];
        $dry = (bool) $this->option('dry-run');
        $archive = ! $this->option('no-archive');

        // Source: a dump directory (per-file) or a single monolithic dump file.
        $dumpFile = null;
        if ($single) {
            $dumpFile = (string) ($this->option('path') ?: ($cfg['dump_file'] ?? ''));
            $path = $dumpFile;
            if (! is_file($dumpFile)) {
                $this->error("Dump file not found: {$dumpFile}");

                return self::FAILURE;
            }
        } else {
            $path = rtrim((string) ($this->option('path') ?: $cfg['dump_path']), "/\\");
            if (! is_dir($path)) {
                $this->error("Dump directory not found: {$path}");

                return self::FAILURE;
            }
        }
        if (! $dry && ! $this->option('force')) {
            $this->error('Refusing to modify data without --force. Rehearse with --dry-run first.');

            return self::FAILURE;
        }

        $map = $cfg['map'];
        if ($only = $this->option('only')) {
            $wanted = array_map('trim', explode(',', $only));
            $map = array_intersect_key($map, array_flip($wanted));
            foreach (array_diff($wanted, array_keys($cfg['map'])) as $u) {
                $this->warn("Unknown table ignored: {$u}");
            }
        }

        $this->info(($dry ? 'DRY RUN — ' : '').'Legacy refresh from '.$path);
        $this->line('Tables: '.count($map).'  |  staging: '.$stage.'  |  archive: '.($archive ? 'yes' : 'no'));
        $this->newLine();

        $backupDir = null;
        if (! $dry) {
            DB::connection($stageConn)->statement("CREATE DATABASE IF NOT EXISTS `{$stage}`");
            // single_dump: load the whole dump into staging once, up front.
            if ($single) {
                $this->info("Loading dump into staging `{$stage}` …");
                $this->loadWholeDump($cfg, $stage, $dumpFile);
                // Optional preprocessing against staging (e.g. prune to one
                // company) before the column-intersection refresh reads it.
                if ($sql = ($cfg['post_stage_sql'] ?? null)) {
                    $file = base_path($sql);
                    if (! is_file($file)) {
                        $this->error("post_stage_sql not found: {$file}");

                        return self::FAILURE;
                    }
                    $this->info('Preprocessing staging: '.$sql);
                    $this->runSqlFile($cfg, $stage, $file);
                }
            }
            if ($archive) {
                $backupDir = base_path('database/backups/refresh_'.date('Ymd_His'));
                @mkdir($backupDir, 0777, true);
            }
        }

        $summary = [];
        $problems = [];

        foreach ($map as $legacy => $target) {
            [$conn, $tbl] = explode('.', $target, 2);
            $file = $single ? null : "{$path}/table-{$legacy}.sql";
            $row = ['legacy' => $legacy, 'target' => $target, 'rows' => '—', 'nulled' => '', 'status' => ''];

            if ($single) {
                if (! $this->stageHasTable($stageConn, $stage, $legacy)) {
                    $row['status'] = 'NOT IN DUMP';
                    $problems[] = "{$legacy}: not present in staging {$stage}";
                    $summary[] = $row;

                    continue;
                }
                $dumpCols = $this->stageColumns($stageConn, $stage, $legacy);
            } else {
                if (! is_file($file)) {
                    $row['status'] = 'MISSING FILE';
                    $problems[] = "{$legacy}: dump file not found";
                    $summary[] = $row;

                    continue;
                }
                $dumpCols = $this->dumpColumns($file);
            }
            $targetCols = $this->targetColumns($conn, $tbl);
            if (! $targetCols) {
                $row['status'] = 'TARGET MISSING';
                $problems[] = "{$target}: not a base table in gds";
                $summary[] = $row;

                continue;
            }

            $common = array_values(array_intersect($dumpCols, $targetCols));
            $nulled = array_values(array_diff($targetCols, $dumpCols)); // gds-added, get default/NULL/trigger
            $row['nulled'] = count($nulled) ? implode(',', $nulled) : '—';

            if (! $common) {
                $row['status'] = 'NO COMMON COLS';
                $problems[] = "{$legacy}: no shared columns with {$target}";
                $summary[] = $row;

                continue;
            }

            if ($dry) {
                $cur = (int) DB::connection($conn)->table($tbl)->count();
                $row['rows'] = "have {$cur}";
                $hasRows = $single
                    ? (int) DB::connection($stageConn)->table("{$stage}.{$legacy}")->count() > 0
                    : $this->dumpHasRows($file);
                $row['status'] = $hasRows ? 'ready ('.count($common).' cols)' : 'DUMP EMPTY';
                $summary[] = $row;

                continue;
            }

            try {
                if (! $single) {
                    $this->loadStage($cfg, $stage, $legacy, $file);
                }
                if ($archive) {
                    $this->archive($cfg, $conn, $tbl, $backupDir);
                }
                $this->refresh($conn, $tbl, $stage, $legacy, $common, $cfg['defaults'][$target] ?? [], $cfg['transforms'][$target] ?? []);

                $count = (int) DB::connection($conn)->table($tbl)->count();
                $row['rows'] = (string) $count;
                $row['status'] = 'OK';
                if ($count === 0 && ! in_array($target, $cfg['allow_empty'], true)) {
                    $row['status'] = 'OK (0 rows!)';
                    $problems[] = "{$target}: refreshed to 0 rows (dump had no data)";
                }
            } catch (\Throwable $e) {
                $row['status'] = 'ERROR';
                $problems[] = "{$target}: ".$e->getMessage();
            }
            $summary[] = $row;
            $this->line(sprintf('  %-34s %-32s %s', $legacy, '-> '.$target, $row['status']));
        }

        if (! $dry && ! $this->option('keep-staging')) {
            DB::connection($stageConn)->statement("DROP DATABASE IF EXISTS `{$stage}`");
        }

        $this->newLine();
        $this->table(['Legacy table', 'gds target', 'Rows', 'Left NULL (gds-added)', 'Status'],
            array_map(fn ($r) => [$r['legacy'], $r['target'], $r['rows'], $r['nulled'], $r['status']], $summary));

        if ($dry) {
            $this->newLine();
            $this->comment('Columns listed under "Left NULL" are gds-added. Hierarchy ids self-heal via');
            $this->comment('BEFORE INSERT triggers; the rest stay NULL unless configured in "defaults".');
            $this->comment('Review candidates (config nullable_review):');
            foreach (($cfg['nullable_review'] ?? []) as $t => $cols) {
                $this->line("   {$t}: ".implode(', ', $cols));
            }
        }

        if ($problems) {
            $this->newLine();
            $this->warn('Attention ('.count($problems).'):');
            foreach ($problems as $p) {
                $this->line('   - '.$p);
            }
        }

        if (! $dry) {
            $this->newLine();
            $this->comment('Downstream derived tables are now stale — re-derive as needed:');
            // First, because the rest read what it fills in: a refresh leaves
            // gds-derived columns empty AND the imported receipts behind.
            $this->line('   php artisan gds:replay-backfills --apply    (derived columns + new receipts)');
            $this->line('   php artisan bil:reconcile-warehouse-stock   (rebuild rawmaterials_stock)');
            $this->line('   php artisan bil:reconcile-rm-stock          (verify raw-materials stock)');
            $this->line('   php artisan bil:reconcile-fg-stock          (verify finished-goods stock)');
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }

    /** Column names from a dump file's CREATE TABLE block (reads only the head). */
    private function dumpColumns(string $file): array
    {
        $cols = [];
        $fh = fopen($file, 'r');
        $inCreate = false;
        while (($line = fgets($fh)) !== false) {
            if (! $inCreate) {
                if (stripos($line, 'CREATE TABLE') !== false) {
                    $inCreate = true;
                }

                continue;
            }
            $t = ltrim($line);
            if ($t !== '' && $t[0] === ')') {
                break; // end of column defs (") ENGINE=...")
            }
            if (preg_match('/^`([A-Za-z0-9_]+)`\s/', $t, $m)
                && ! preg_match('/^(PRIMARY|UNIQUE|KEY|CONSTRAINT|FULLTEXT|INDEX)\b/i', $t)) {
                $cols[] = $m[1];
            }
        }
        fclose($fh);

        return $cols;
    }

    /** True if the dump file contains at least one data row. */
    private function dumpHasRows(string $file): bool
    {
        $fh = fopen($file, 'r');
        $has = false;
        while (($line = fgets($fh)) !== false) {
            if (stripos($line, 'REPLACE INTO') !== false || stripos($line, 'INSERT INTO') !== false) {
                $has = true;
                break;
            }
        }
        fclose($fh);

        return $has;
    }

    /** Columns of the gds target base table (empty array if it doesn't exist). */
    private function targetColumns(string $conn, string $table): array
    {
        $schema = DB::connection($conn)->getDatabaseName();
        $rows = DB::connection($conn)->select(
            "SELECT c.COLUMN_NAME FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t
               ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = ? AND c.TABLE_NAME = ? AND t.TABLE_TYPE = 'BASE TABLE'",
            [$schema, $table]
        );

        return array_map(fn ($r) => $r->COLUMN_NAME, $rows);
    }

    /** single_dump: load the whole monolithic dump into the staging schema once. */
    private function loadWholeDump(array $cfg, string $stage, string $file): void
    {
        $p = new Process([$cfg['mysql_bin'], '-h', '127.0.0.1', '-u', 'root', $stage]);
        $p->setTimeout(null);
        $p->setInput(fopen($file, 'r'));
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('dump load failed: '.trim($p->getErrorOutput()));
        }
    }

    /** Run a .sql file against the staging schema (mysql client). */
    private function runSqlFile(array $cfg, string $stage, string $file): void
    {
        $p = new Process([$cfg['mysql_bin'], '-h', '127.0.0.1', '-u', 'root', $stage]);
        $p->setTimeout(null);
        $p->setInput(fopen($file, 'r'));
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('post_stage_sql failed: '.trim($p->getErrorOutput()));
        }
    }

    /** True if the staging schema holds this legacy table. */
    private function stageHasTable(string $conn, string $stage, string $legacy): bool
    {
        return (bool) DB::connection($conn)->selectOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
            [$stage, $legacy]
        );
    }

    /** Column names of a staging table (single_dump equivalent of dumpColumns). */
    private function stageColumns(string $conn, string $stage, string $legacy): array
    {
        $rows = DB::connection($conn)->select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$stage, $legacy]
        );

        return array_map(fn ($r) => $r->COLUMN_NAME, $rows);
    }

    private function loadStage(array $cfg, string $stage, string $legacy, string $file): void
    {
        DB::connection('bil')->statement("DROP TABLE IF EXISTS `{$stage}`.`{$legacy}`");
        $p = new Process([$cfg['mysql_bin'], '-h', '127.0.0.1', '-u', 'root', $stage]);
        $p->setTimeout(null);
        $p->setInput(fopen($file, 'r'));
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('stage load failed: '.trim($p->getErrorOutput()));
        }
    }

    private function archive(array $cfg, string $conn, string $tbl, string $dir): void
    {
        $schema = DB::connection($conn)->getDatabaseName();
        $fh = fopen("{$dir}/{$schema}.{$tbl}.sql", 'w');
        $p = new Process([$cfg['mysqldump_bin'], '-h', '127.0.0.1', '-u', 'root',
            '--skip-comments', '--skip-lock-tables', '--no-tablespaces', $schema, $tbl]);
        $p->setTimeout(null);
        $p->run(function ($type, $buf) use ($fh) {
            if ($type === Process::OUT) {
                fwrite($fh, $buf);
            }
        });
        fclose($fh);
    }

    private function refresh(string $conn, string $tbl, string $stage, string $legacy, array $common, array $defaults, array $transforms = []): void
    {
        $db = DB::connection($conn);
        $schema = $db->getDatabaseName();
        // SELECT list: a column is copied verbatim unless the profile gives it a
        // transform expression (e.g. legacy datetime -> modern unix int, or a
        // NOT-NULL coercion). Expressions reference the staging column by name.
        $selParts = array_map(
            fn ($c) => isset($transforms[$c]) ? "({$transforms[$c]}) AS `{$c}`" : "`{$c}`",
            $common
        );
        $insertCols = $common;
        // Default columns are written at INSERT time (as constants) rather than a
        // post-UPDATE, so NOT-NULL gds-added columns absent from the legacy dump
        // still get a value. Skip any that are already copied from the dump.
        foreach ($defaults as $col => $val) {
            if (in_array($col, $common, true)) {
                continue;
            }
            $insertCols[] = $col;
            $selParts[] = $db->getPdo()->quote((string) $val).' AS `'.$col.'`';
        }
        $cl = '`'.implode('`,`', $insertCols).'`';
        $sel = implode(',', $selParts);
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        $db->statement("TRUNCATE `{$schema}`.`{$tbl}`");
        $db->statement("INSERT INTO `{$schema}`.`{$tbl}` ({$cl}) SELECT {$sel} FROM `{$stage}`.`{$legacy}`");
    }
}
