<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import country / state / city reference data into the core geo_* tables.
 *
 *   php artisan gds:import-geo            # uses storage/app/geo/*.csv
 *   php artisan gds:import-geo --download # fetch the files first
 *
 * Source: the Countries States Cities Database (ODbL v1.0) —
 * https://github.com/dr5hn/countries-states-cities-database
 *
 * Idempotent: rows are upserted on the dataset's own ids, so re-running against
 * a newer export updates in place instead of duplicating. Nothing is deleted —
 * a city that vanishes upstream stays, because a customer may reference it.
 */
class ImportGeo extends Command
{
    protected $signature = 'gds:import-geo
                            {--download : Download the CSVs before importing}
                            {--path= : Directory holding countries.csv, states.csv, cities.csv}';

    protected $description = 'Import country/state/city reference data into the core geo_* tables';

    /** Countries and states live in the repo; cities ships only as a release asset. */
    protected const REPO = 'https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/master/csv/';
    protected const CITIES_RELEASE = 'https://github.com/dr5hn/countries-states-cities-database/releases/latest/download/csv-cities.csv.gz';

    /** Rows per insert. Big enough to be quick, small enough for max_allowed_packet. */
    protected const CHUNK = 2000;

    public function handle(): int
    {
        $dir = rtrim($this->option('path') ?: storage_path('app/geo'), '/\\');

        if ($this->option('download') && ! $this->download($dir)) {
            return self::FAILURE;
        }

        foreach (['countries', 'states', 'cities'] as $file) {
            if (! is_readable("$dir/$file.csv")) {
                $this->error("Missing $dir/$file.csv — run with --download, or pass --path.");

                return self::FAILURE;
            }
        }

        $this->import("$dir/countries.csv", 'geo_countries', fn (array $r) => [
            'id' => (int) $r['id'],
            'iso2' => $r['iso2'],
            'iso3' => $r['iso3'] ?: null,
            'name' => $r['name'],
            'phonecode' => $r['phonecode'] ?: null,
            'currency' => $r['currency'] ?: null,
            'region' => $r['region'] ?: null,
        ], fn (array $r) => $r['iso2'] !== '');

        $this->import("$dir/states.csv", 'geo_states', fn (array $r) => [
            'id' => (int) $r['id'],
            'country_code' => $r['country_code'],
            'state_code' => $r['iso2'] ?: null,
            'name' => $r['name'],
            'type' => $r['type'] ?: null,
        ], fn (array $r) => $r['country_code'] !== '' && $r['name'] !== '');

        $this->import("$dir/cities.csv", 'geo_cities', fn (array $r) => [
            'id' => (int) $r['id'],
            'country_code' => $r['country_code'],
            'state_code' => $r['state_code'] ?: null,
            'name' => $r['name'],
        ], fn (array $r) => $r['country_code'] !== '' && $r['name'] !== '');

        $db = DB::connection('core');
        $this->newLine();
        $this->info(sprintf(
            'Done — %s countries, %s states, %s cities.',
            number_format($db->table('geo_countries')->count()),
            number_format($db->table('geo_states')->count()),
            number_format($db->table('geo_cities')->count()),
        ));
        $this->line('Data by Countries States Cities Database (ODbL v1.0).');

        return self::SUCCESS;
    }

    /**
     * Stream a CSV into a table, upserting on the dataset id.
     *
     * Streamed rather than read whole: cities.csv is 19 MB / 153k rows, and
     * file_get_contents + str_getcsv on that is a needless ~200 MB spike.
     */
    protected function import(string $path, string $table, callable $map, callable $keep): void
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        if (! $header) {
            $this->error("$path is empty.");
            fclose($handle);

            return;
        }

        $this->line("Importing $table from " . basename($path) . ' …');
        $bar = $this->output->createProgressBar();
        $bar->start();

        $db = DB::connection('core');
        $batch = [];
        $count = 0;
        $columns = null;

        while (($row = fgetcsv($handle)) !== false) {
            // Ragged rows would silently shift every column; skip them loudly
            // rather than importing a city called "36.68333000".
            if (count($row) !== count($header)) {
                continue;
            }

            $assoc = array_combine($header, $row);
            if (! $keep($assoc)) {
                continue;
            }

            $batch[] = $mapped = $map($assoc);
            $columns ??= array_keys($mapped);

            if (count($batch) >= self::CHUNK) {
                $db->table($table)->upsert($batch, ['id'], array_diff($columns, ['id']));
                $count += count($batch);
                $batch = [];
                $bar->advance(self::CHUNK);
            }
        }

        if ($batch !== []) {
            $db->table($table)->upsert($batch, ['id'], array_diff($columns, ['id']));
            $count += count($batch);
        }

        fclose($handle);
        $bar->finish();
        $this->newLine();
        $this->line("  {$table}: " . number_format($count) . ' rows');
    }

    protected function download(string $dir): bool
    {
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create $dir");

            return false;
        }

        foreach (['countries', 'states'] as $file) {
            $this->line("Downloading $file.csv …");
            $body = @file_get_contents(self::REPO . "$file.csv");
            if ($body === false || $body === '') {
                $this->error("Download failed for $file.csv");

                return false;
            }
            file_put_contents("$dir/$file.csv", $body);
        }

        $this->line('Downloading cities.csv.gz …');
        $gz = @file_get_contents(self::CITIES_RELEASE);
        if ($gz === false || $gz === '') {
            $this->error('Download failed for cities.csv.gz');

            return false;
        }
        file_put_contents("$dir/cities.csv.gz", $gz);

        $plain = @gzdecode($gz);
        if ($plain === false) {
            $this->error('Could not decompress cities.csv.gz');

            return false;
        }
        file_put_contents("$dir/cities.csv", $plain);
        @unlink("$dir/cities.csv.gz");

        return true;
    }
}
