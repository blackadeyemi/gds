<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-fill the columns gds computes that a production dump does not carry.
 *
 * A refresh (gds:refresh-legacy, or a hand restore) TRUNCATEs a legacy table and
 * reloads it from production. Production has never run our migrations, so a
 * column WE added and backfilled comes back empty — while `core.migrations`
 * still records the migration as run, so it never fires again. The column is
 * there; the values are gone; nothing complains.
 *
 * It is not theoretical. After the 2026-09-04 refresh:
 *
 *   factory_exit.exit_location_id     1,209,667 of 1,209,667 rows NULL
 *                                     — the Factory column on the Factory Exit
 *                                       report was blank for every row
 *   factory_event.date                792 of 792 blank
 *   bpl_factoryexit.received_at       0 of 132,134 stamped
 *
 * and four feature tests failed on the missing data rather than on any code.
 *
 * ⚠️ DO NOT `migrate:rollback` to fix this. Every one of these migrations drops
 * its column in down(), so a rollback destroys the schema as well as the data.
 * This command replays the backfill ONLY.
 *
 * Every repair is idempotent and touches only rows that are missing the value,
 * so running it when nothing is broken costs one COUNT per repair and changes
 * nothing. Run it after every refresh.
 *
 * A generated column (factory_usage_reel.reel_barcode, factory_event.reel_barcode)
 * is deliberately absent: MySQL recomputes those on insert, so a reload heals
 * them by itself.
 *
 * One repair ('fg-receipts') replays missing ROWS rather than a column, because
 * the failure is the same one: a refresh brings in legacy receipts that gds's
 * own receipt table never hears about. See its note below.
 */
class ReplayBackfills extends Command
{
    protected $signature = 'gds:replay-backfills
        {--apply : Actually write. Without it this is a dry run}
        {--only= : Comma-separated repair names to run}';

    protected $description = 'Re-fill gds-computed columns that a production dump refresh leaves empty';

    /** Legacy spellings of a factory gate, from the 2026_08_12_100000 migration. */
    private const GATE_ALIASES = [
        'Bil-1 Elevator' => 'BIL1-Elevator',
        'BIL1 Elevator' => 'BIL1-Elevator',
        'Gambini Gate' => 'Gambini Gate 1',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $repairs = $this->repairs();

        if ($only = $this->option('only')) {
            $wanted = array_map('trim', explode(',', (string) $only));
            foreach (array_diff($wanted, array_keys($repairs)) as $unknown) {
                $this->warn("Unknown repair ignored: {$unknown}");
            }
            $repairs = array_intersect_key($repairs, array_flip($wanted));
        }

        $rows = [];
        $broken = 0;
        $fixed = 0;

        foreach ($repairs as $name => $repair) {
            if (! ($repair['available'])()) {
                $rows[] = [$name, $repair['column'], '—', 'column not present — skipped'];

                continue;
            }

            $missing = (int) ($repair['missing'])();

            if ($missing === 0) {
                $rows[] = [$name, $repair['column'], '0', 'already filled'];

                continue;
            }

            $broken++;

            if (! $apply) {
                $rows[] = [$name, $repair['column'], number_format($missing), 'WOULD REPAIR'];

                continue;
            }

            ($repair['repair'])();
            $left = (int) ($repair['missing'])();
            $fixed += $missing - $left;
            $rows[] = [$name, $repair['column'], number_format($missing),
                $left === 0 ? 'repaired' : 'repaired, ' . number_format($left) . ' still unresolved'];
        }

        $this->table(['Repair', 'Column', 'Rows missing', 'Status'], $rows);

        if ($broken === 0) {
            $this->info('Nothing to replay — every backfilled column is filled.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->warn($broken . ' backfill(s) need replaying. Re-run with --apply.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Replayed ' . $broken . ' backfill(s); ' . number_format($fixed) . ' row(s) filled.');
        $this->line('Derived stock and the reports read these columns — no further step is needed.');

        return self::SUCCESS;
    }

    /**
     * name => [column, available, missing, repair].
     *
     * Each `repair` fills only what is missing, so it can be run at any time.
     */
    private function repairs(): array
    {
        $bil = fn () => DB::connection('bil');

        return [
            /* The Factory column on the Factory Exit report resolves through
               this id; without it every row reads "-". */
            'factory-exit-gate' => [
                'column' => 'bil.factory_exit.exit_location_id',
                'available' => fn () => Schema::connection('bil')->hasColumn('factory_exit', 'exit_location_id'),
                'missing' => fn () => $bil()->table('factory_exit')->whereNull('exit_location_id')->count(),
                'repair' => function () use ($bil) {
                    [$case, $bindings] = $this->gateCase();

                    if ($case === null) {
                        return;
                    }

                    // One CASE pass, not an UPDATE per gate: `exitlocation` is
                    // an unindexed varchar over 1.2M rows, so each separate
                    // statement would be its own full scan.
                    $bil()->update(
                        "UPDATE `factory_exit` SET `exit_location_id` = {$case} WHERE `exit_location_id` IS NULL",
                        $bindings
                    );
                },
            ],

            /* Jumbo roll returns are listed and dated by this. */
            'factory-event-date' => [
                'column' => 'bil.factory_event.date',
                'available' => fn () => Schema::connection('bil')->hasColumn('factory_event', 'date'),
                'missing' => fn () => $bil()->table('factory_event')
                    ->where('timestamp', '>', 0)
                    ->where(fn ($q) => $q->whereNull('date')->orWhere('date', ''))->count(),
                'repair' => fn () => $bil()->statement(
                    "UPDATE `factory_event` SET `date` = FROM_UNIXTIME(`timestamp`, '%Y/%m/%d')"
                    . " WHERE (`date` IS NULL OR `date` = '') AND `timestamp` > 0"
                ),
            ],

            /* Without it, a reel BIL has already received still shows as an
               open exit on the BPL side. */
            'bpl-exit-received' => [
                'column' => 'bpl.bpl_factoryexit.received_at',
                'available' => fn () => Schema::connection('bpl')->hasColumn('bpl_factoryexit', 'received_at'),
                'missing' => fn () => (int) DB::connection('bpl')->selectOne(
                    'SELECT COUNT(*) n FROM `bpl`.`bpl_factoryexit` x
                     JOIN `bil`.`factory_entrance_reel` f
                       ON f.`barcode` = x.`barcode` AND f.`is_deleted` = 0
                     WHERE x.`received_at` IS NULL')->n,
                'repair' => fn () => DB::connection('bpl')->statement(
                    'UPDATE `bpl`.`bpl_factoryexit` x'
                    . ' JOIN `bil`.`factory_entrance_reel` f'
                    . '   ON f.`barcode` = x.`barcode` AND f.`is_deleted` = 0'
                    . ' SET x.`received_at` = COALESCE('
                    . "     STR_TO_DATE(f.`dateofentrance`, '%Y/%m/%d'),"
                    . "     STR_TO_DATE(x.`date`, '%Y/%m/%d'))"
                    . ' WHERE x.`received_at` IS NULL'
                ),
            ],

            /* The Machines dashboard and Services report total stop time off
               this cache of the duration JSON. */
            'maintenance-minutes' => [
                'column' => 'bil.factory_machine_maintenance.duration_minutes',
                'available' => fn () => Schema::connection('bil')
                    ->hasColumn('factory_machine_maintenance', 'duration_minutes'),
                'missing' => fn () => $bil()->table('factory_machine_maintenance')
                    ->whereNull('duration_minutes')->whereRaw('JSON_VALID(`duration`)')->count(),
                'repair' => fn () => $bil()->statement(
                    'UPDATE `factory_machine_maintenance` SET `duration_minutes` = '
                    . self::minutesSql('`duration`')
                    . ' WHERE `duration_minutes` IS NULL AND JSON_VALID(`duration`)'
                ),
            ],

            /* Which gate a reel came in at, for the Jumbo Rolls reports. */
            'reel-entrance-gate' => [
                'column' => 'bil.factory_entrance_reel.gate_id',
                'available' => fn () => Schema::connection('bil')
                    ->hasColumn('factory_entrance_reel', 'gate_id'),
                'missing' => fn () => $bil()->table('factory_entrance_reel')
                    ->whereNull('gate_id')->whereNotNull('location')->where('location', '<>', '')->count(),
                'repair' => function () use ($bil) {
                    // `location` holds the FACTORY's code (Bil-1, Gambini), not
                    // the gate's name, so the code has to come off `factories`
                    // — `factory_gates` has no `code` column of its own. Only
                    // inbound gates: a reel arrives through one.
                    $gates = DB::connection('core')->table('factory_gates as g')
                        ->leftJoin('factories as f', 'f.id', '=', 'g.factory_id')
                        ->whereIn('g.direction', ['in', 'both'])
                        ->get(['g.id', 'g.name', 'f.code']);

                    foreach ($gates as $gate) {
                        // Oregun Store is a pseudo-factory with no code.
                        $location = $gate->code ?? $gate->name;
                        $bil()->table('factory_entrance_reel')
                            ->where('location', $location)->whereNull('gate_id')
                            ->update(['gate_id' => $gate->id]);
                    }
                },
            ],

            /* ROWS, not a column -- the only one of its kind here, and it
               earns the exception. gds has its own finished-goods receipt
               table; the legacy `store_entrance` is what the production app
               still writes, and `bil:backfill-fg-receipts` copies the one into
               the other. A refresh reloads `store_entrance` with everything
               that happened since the last dump, and nothing re-ran the import
               -- so the gds table silently fell behind. On 2026-10-02 it held
               arrivals to 07 Aug while the legacy table ran to 30 Sep: 16,714
               receipts missing, and every arrival figure on the Finished Goods
               statistics, plus the tail of the Warehouse Entrance report, read
               as though nothing had been received for seven weeks.

               Counted by id rather than by an anti-join: the import walks
               `store_entrance` in id order, so anything above the highest id
               already imported is what a refresh brought in. One index range
               scan instead of an anti-join over 1.18M rows. */
            'fg-receipts' => [
                'column' => 'bil.finished_goods_warehouse_receipts (rows)',
                'available' => fn () => Schema::connection('bil')
                    ->hasTable('finished_goods_warehouse_receipts'),
                'missing' => function () use ($bil) {
                    $highest = (int) $bil()->table('finished_goods_warehouse_receipts')
                        ->max('legacy_id');

                    return $bil()->table('store_entrance')->where('id', '>', $highest)->count();
                },
                'repair' => fn () => $this->call('bil:backfill-fg-receipts', ['--apply' => true]),
            ],
        ];
    }

    /**
     * The CASE that maps every spelling of a gate in `factory_exit.exitlocation`
     * onto its id, including the four from March–April 2017 that
     * `factoryexit_details` never held.
     *
     * @return array{0: ?string, 1: array}
     */
    private function gateCase(): array
    {
        $byName = DB::connection('core')->table('factory_gates')->pluck('id', 'name');

        $map = [];
        foreach ($byName as $name => $id) {
            $map[$name] = $id;
        }
        foreach (self::GATE_ALIASES as $spelling => $canonical) {
            if (isset($byName[$canonical])) {
                $map[$spelling] = $byName[$canonical];
            }
        }

        if ($map === []) {
            return [null, []];
        }

        $case = 'CASE `exitlocation`';
        $bindings = [];
        foreach ($map as $name => $id) {
            $case .= ' WHEN ? THEN ?';
            $bindings[] = $name;
            $bindings[] = $id;
        }

        return [$case . ' ELSE NULL END', $bindings];
    }

    /** The duration JSON ({"d":_,"h":_,"m":_}) as a number of minutes. */
    private static function minutesSql(string $ref): string
    {
        return "COALESCE(JSON_EXTRACT($ref, '$.d'), 0) * 1440
              + COALESCE(JSON_EXTRACT($ref, '$.h'), 0) * 60
              + COALESCE(JSON_EXTRACT($ref, '$.m'), 0)";
    }
}
