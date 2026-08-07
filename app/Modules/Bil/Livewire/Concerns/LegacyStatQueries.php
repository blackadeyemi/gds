<?php

namespace Modules\Bil\Livewire\Concerns;

use Carbon\Carbon;

/**
 * Query helpers for statistics pages built over the legacy `bil` tables.
 *
 * They exist because those tables don't store dates as dates: most columns are
 * varchars holding 'Y/m/d' or 'Y-m-d', which compare and sort correctly as
 * strings but can't be handed to MySQL's date functions. Everything here works
 * in that idiom — bucket by LEFT(col, 7|10), range by string BETWEEN — and
 * fills the gaps in PHP so a chart always shows a continuous timeline.
 *
 * Used by Raw Materials and Machines statistics; a page mixes it into
 * Modules\Core\Livewire\StatisticsPage, which supplies rangeStart()/rangeEnd().
 */
trait LegacyStatQueries
{
    /** Bucket by month for long ranges, by day for short ones. */
    protected function isMonthly(): bool
    {
        return in_array($this->range, ['12m', 'all'], true);
    }

    /** [from, to] as strings in the given separator, or [null, null] for all time. */
    protected function bounds(string $sep): array
    {
        $start = $this->rangeStart();

        if (! $start) {
            return [null, null];
        }

        $fmt = $sep === '/' ? 'Y/m/d' : 'Y-m-d';

        return [$start->format($fmt), $this->rangeEnd()->format($fmt)];
    }

    /**
     * A filled time series {labels, data} over a string-date column, bucketed by
     * day or month. $where receives the builder to add table-specific filters.
     */
    protected function series(string $table, string $dateCol, string $sep, string $agg = 'COUNT(*)', ?callable $where = null): array
    {
        $monthly = $this->isMonthly();
        $len = $monthly ? 7 : 10;

        $q = $this->db()->table($table)->selectRaw("LEFT(`{$dateCol}`, {$len}) as bucket, {$agg} as val");
        [$from, $to] = $this->bounds($sep);

        if ($from) {
            $q->whereBetween($dateCol, [$from, $to]);
        }

        if ($where) {
            $where($q);
        }

        $vals = $q->groupBy('bucket')->orderBy('bucket')->pluck('val', 'bucket');

        // Continuous buckets from range start (or earliest present, for all-time) to now.
        $start = $this->rangeStart();

        if (! $start) {
            // All-time: start at the earliest VALID bucket. Legacy date columns
            // hold dirty values (empty strings, dd/mm/yy rows, a stray far-future
            // date), so ignore anything that doesn't begin with a real year.
            $validKeys = $vals->keys()->filter(fn ($k) => preg_match('/^(19|20)\d{2}/', (string) $k));

            if ($validKeys->isEmpty()) {
                return ['labels' => [], 'data' => []];
            }

            $minKey = str_replace('/', '-', (string) $validKeys->min());
            $start = Carbon::parse($monthly ? $minKey . '-01' : $minKey);
        }

        $labels = [];
        $data = [];
        $fmt = $sep === '/' ? 'Y/m/d' : 'Y-m-d';
        $cursor = $monthly ? $start->copy()->startOfMonth() : $start->copy()->startOfDay();
        $end = $this->rangeEnd();
        $guard = 0;

        while ($cursor <= $end && $guard++ < 800) {
            $key = substr($cursor->format($fmt), 0, $len);
            $labels[] = $monthly ? $cursor->format('M Y') : $cursor->format('j M');
            $data[] = (float) ($vals[$key] ?? 0);
            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** Count rows in a table over the current range (date column + separator). */
    protected function countOver(string $table, string $dateCol, string $sep, ?callable $where = null): int
    {
        $q = $this->db()->table($table);
        [$from, $to] = $this->bounds($sep);

        if ($from) {
            $q->whereBetween($dateCol, [$from, $to]);
        }

        if ($where) {
            $where($q);
        }

        return (int) $q->count();
    }

    protected function sumOver(string $table, string $dateCol, string $sep, string $col, ?callable $where = null): float
    {
        $q = $this->db()->table($table);
        [$from, $to] = $this->bounds($sep);

        if ($from) {
            $q->whereBetween($dateCol, [$from, $to]);
        }

        if ($where) {
            $where($q);
        }

        return (float) $q->sum($col);
    }

    /**
     * Top N groups of a table over the range, grouped by an INDEXED base column
     * (fast) — returns rows of {key, cnt}. Resolve key→name with nameMap().
     */
    protected function topBy(string $table, string $dateCol, string $sep, string $keyCol, int $limit = 10)
    {
        $q = $this->db()->table($table)->selectRaw("`{$keyCol}` as `key`, COUNT(*) as cnt");
        [$from, $to] = $this->bounds($sep);

        if ($from) {
            $q->whereBetween($dateCol, [$from, $to]);
        }

        return $q->groupBy($keyCol)->orderByDesc('cnt')->limit($limit)->get();
    }

    /** Map key-column values to a display name from a lookup table. */
    protected function nameMap(string $table, string $keyCol, string $nameCol, $keys): array
    {
        $keys = collect($keys)->filter(fn ($k) => $k !== null && $k !== '')->all();

        if (! $keys) {
            return [];
        }

        return $this->db()->table($table)->whereIn($keyCol, $keys)->pluck($nameCol, $keyCol)->all();
    }

    protected function kg(float $v): string
    {
        return $this->figure($v, 'kg');
    }

    protected function num(float $v): string
    {
        return $this->figure($v);
    }
}
