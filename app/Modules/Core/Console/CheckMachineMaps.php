<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preflight for a data refresh: does every legacy NAME in the BIL tables still
 * resolve to an id in the machines hierarchy?
 *
 * The legacy app writes names — `linename`, `factory`, `project`, `staff` — and
 * BEFORE INSERT/UPDATE triggers resolve them to core ids through the
 * `machine_map_*` tables. When production data is loaded over `bil` while the
 * hierarchy is carried across from elsewhere, any name the hierarchy has never
 * seen resolves to NULL. Silently.
 *
 * That is usually cosmetic. For CONVERSION WASTE it is not: a run is keyed on
 * `factory_conversion.line_id`, and rows with a null line form no run at all —
 * they never reach the waste queue, never need confirming and never block. The
 * control fails OPEN, which is the failure that does not announce itself. This
 * command is how it announces itself.
 *
 * The checks are DERIVED FROM THE TRIGGERS, not hardcoded: the trigger bodies
 * are parsed at runtime, so this cannot drift from the schema and picks up any
 * mapping added later. (Hardcoding the list missed `staff_id` on the first
 * attempt — it matches on two columns, not one.)
 *
 * Resolution is tested IN SQL, with the same LEFT JOIN the trigger would do, so
 * MySQL's own collation rules apply. Checking in PHP is stricter than the
 * database and reports names as missing that in fact resolve — "Aluminium Foil"
 * matches "ALUMINIUM FOIL" in a case-insensitive collation.
 *
 * Exit code is non-zero when anything is unresolved, so a deploy script can
 * stop on it.
 */
class CheckMachineMaps extends Command
{
    protected $signature = 'gds:check-machine-maps
                            {--limit=10 : Unresolved values to list per mapping}
                            {--all : List every unresolved value}
                            {--strict : Count legacy placeholders as gaps too}';

    protected $description = 'Check that every legacy name in BIL resolves to a machines-hierarchy id';

    /**
     * Values the legacy app writes where it has nothing real to say. They will
     * never resolve and they are not hierarchy gaps — `factory_usage_rawmaterials`
     * carries the literal 'machine' in its project column on 120,971 of its
     * 121,043 rows, and `subproject` carries the string 'null'.
     *
     * They are REPORTED, separately and by count, rather than hidden: a
     * placeholder on nearly every row usually means the column is not being used
     * for what its name suggests, which is worth knowing. They just do not fail
     * the check, because no amount of editing the hierarchy would fix them.
     * `--strict` counts them anyway.
     */
    private const PLACEHOLDERS = [
        'machine', 'null', 'none', 'n/a', 'na', 'nil', '-', '--', '0', 'undefined',
    ];

    /**
     * Mappings whose failure is more than cosmetic, and why. Printed alongside
     * the gap so the consequence is on screen rather than in a runbook.
     */
    private const CRITICAL = [
        'factory_conversion.line_id' =>
            'Conversion Waste keys a run on this. Pallets with a null line form NO run — '
            . 'they never reach the waste queue and never block the next run on that line.',
        'conversion_setup.line_id' =>
            'Conversion Output only offers lines that resolve here, so an unmapped line '
            . 'cannot be booked against at all.',
    ];

    public function handle(): int
    {
        $mappings = $this->mappings();

        if ($mappings === []) {
            $this->error('No name->id triggers found on the `bil` connection. Has the split migration run?');

            return self::FAILURE;
        }

        $this->line(count($mappings) . ' mapping(s) derived from the BEFORE INSERT triggers.');
        $this->newLine();

        $gaps = 0;
        $rowsAffected = 0;

        foreach ($mappings as $m) {
            [$ok, $rows] = $this->check($m);
            if (! $ok) {
                $gaps++;
                $rowsAffected += $rows;
            }
        }

        $this->newLine();

        if ($gaps === 0) {
            $this->info('All legacy names resolve. The hierarchy covers every name in the data.');

            return self::SUCCESS;
        }

        $this->error($gaps . ' mapping(s) with unresolved names, affecting '
            . number_format($rowsAffected) . ' row(s).');
        $this->newLine();
        $this->line('Fix by adding the missing line / project / staff to the machines hierarchy');
        $this->line('(BIL > Machines), then re-running the map build. Do NOT edit the legacy');
        $this->line('rows — the trigger resolves them on the next write.');

        return self::FAILURE;
    }

    /* ---------------- Deriving the checks from the triggers ---------------- */

    /**
     * Every name->id resolution the triggers perform, as
     * ['table','id_column','map','pairs' => [map_column => source_column]].
     *
     * Handles both shapes in use: a single `m.nm = NEW.col`, and the composite
     * `m.division_nm = NEW.division AND m.staff_nm = NEW.staff`.
     */
    private function mappings(): array
    {
        $out = [];

        foreach (DB::connection('bil')->select('SHOW TRIGGERS') as $t) {
            // Insert and update triggers are identical; read one of each pair.
            if (! str_contains(strtoupper($t->Event), 'INSERT')) {
                continue;
            }

            $sql = (string) $t->Statement;

            preg_match_all(
                '/SET\s+NEW\.`(\w+)`\s*=\s*\(\s*SELECT\s+m\.target_id\s+FROM\s+`(\w+)`\s+m\s+WHERE\s+(.+?)\s+LIMIT\s+1\s*\)/is',
                $sql,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                [, $idColumn, $map, $conditions] = $match;

                preg_match_all('/m\.(\w+)\s*=\s*NEW\.`(\w+)`/i', $conditions, $pairMatches, PREG_SET_ORDER);

                $pairs = [];
                foreach ($pairMatches as $p) {
                    $pairs[$p[1]] = $p[2];
                }

                if ($pairs === []) {
                    continue;
                }

                $out[] = [
                    'table' => $t->Table,
                    'id_column' => $idColumn,
                    'map' => $map,
                    'pairs' => $pairs,
                ];
            }
        }

        return $out;
    }

    /* ---------------- The check ---------------- */

    /** @return array{0:bool,1:int} [everything resolved, rows affected] */
    private function check(array $m): array
    {
        $bil = DB::connection('bil');
        $label = $m['table'] . '.' . $m['id_column'];
        $sources = array_values($m['pairs']);

        // Rows whose source name is set but has no match in the map — joined
        // exactly as the trigger does, so collation behaves identically.
        $q = $bil->table($m['table'] . ' as t')
            ->leftJoin($m['map'] . ' as m', function ($join) use ($m) {
                foreach ($m['pairs'] as $mapColumn => $sourceColumn) {
                    $join->on('m.' . $mapColumn, '=', 't.' . $sourceColumn);
                }
            })
            ->whereNull('m.target_id');

        // A blank name legitimately resolves to NULL — only a name that was
        // actually supplied counts as unresolved.
        foreach ($sources as $col) {
            $q->whereNotNull('t.' . $col)->where('t.' . $col, '<>', '');
        }

        $select = implode(', ', array_map(fn ($c) => 't.' . $c, $sources));

        $unresolved = (clone $q)
            ->selectRaw($select . ', COUNT(*) as n')
            ->groupBy($sources)
            ->orderByDesc('n')
            ->get();

        $total = (int) $unresolved->sum('n');

        // How many rows are ALREADY carrying a null id — the damage already
        // done, as opposed to what the next write would do.
        $nulls = (int) $bil->table($m['table'])->whereNull($m['id_column'])->count();
        $totalRows = (int) $bil->table($m['table'])->count();

        // A name is a placeholder only when EVERY part of it is one, so a real
        // person in a division called "-" is still reported.
        $isPlaceholder = fn ($row) => collect($sources)->every(
            fn ($c) => in_array(strtolower(trim((string) $row->{$c})), self::PLACEHOLDERS, true)
        );

        $strict = (bool) $this->option('strict');
        $placeholders = $strict ? collect() : $unresolved->filter($isPlaceholder)->values();
        $real = $strict ? $unresolved : $unresolved->reject($isPlaceholder)->values();

        $realRows = (int) $real->sum('n');
        $placeholderRows = (int) $placeholders->sum('n');

        // Colour tags occupy no visual width but DO count toward sprintf's
        // padding, so the status is written outside the padded string.
        $status = $real->isEmpty() ? '<fg=green>ok </>' : '<fg=red>GAP</>';

        $note = match (true) {
            $real->isEmpty() && $placeholders->isEmpty() => 'all names resolve',
            $real->isEmpty() => 'all real names resolve',
            default => sprintf('%s unresolved name(s), %s row(s)',
                number_format($real->count()), number_format($realRows)),
        };

        $this->line('  ' . $status . '  ' . str_pad($label, 44) . $note);

        $limit = $this->option('all') ? PHP_INT_MAX : max(1, (int) $this->option('limit'));

        foreach ($real->take($limit) as $row) {
            $name = implode(' / ', array_map(fn ($c) => (string) $row->{$c}, $sources));
            // str_pad does not truncate, so a long name would run into the
            // count — clip it to the column width first.
            $this->line('         ' . str_pad(mb_strimwidth($name, 0, 46, '…'), 48)
                . number_format((int) $row->n) . ' row(s)');
        }

        if ($real->count() > $limit) {
            $this->line('         … and ' . ($real->count() - $limit) . ' more (--all to list)');
        }

        if ($real->isNotEmpty() && isset(self::CRITICAL[$label])) {
            $this->line('         <fg=yellow>' . self::CRITICAL[$label] . '</>');
        }

        // Shown whether or not there is a real gap — a placeholder on most of a
        // table says the column is not holding what its name suggests.
        foreach ($placeholders as $row) {
            $name = implode(' / ', array_map(fn ($c) => (string) $row->{$c}, $sources));
            $share = $totalRows > 0 ? round($placeholderRows / $totalRows * 100) : 0;
            $this->line('         <fg=gray>placeholder "' . $name . '" on '
                . number_format((int) $row->n) . ' row(s)'
                . ($share >= 50 ? ' — ' . $share . '% of the table' : '')
                . ' — not a hierarchy gap</>');
        }

        if ($nulls > 0) {
            $this->line('         <fg=gray>' . number_format($nulls) . ' row(s) currently hold a null '
                . $m['id_column'] . '</>');
        }

        return [$real->isEmpty(), $realRows];
    }
}
