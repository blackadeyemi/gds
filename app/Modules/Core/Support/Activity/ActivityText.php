<?php

namespace Modules\Core\Support\Activity;

use Illuminate\Support\Str;

/** How the activity log names things to a person. */
class ActivityText
{
    /** "bil.rawmaterials_warehouse_exit" → "raw material exit" (config) or a headline of the table. */
    public static function subject(string $type): string
    {
        $named = config('activity.subjects')[$type] ?? null;
        if ($named) {
            return $named;
        }

        $table = str_contains($type, '.') ? substr($type, strpos($type, '.') + 1) : $type;

        return Str::lower(Str::headline(Str::singular($table)));
    }

    public static function subjectPlural(string $type, int $n): string
    {
        $one = self::subject($type);

        return $n === 1 ? $one : Str::plural($one);
    }

    public static function field(string $column): string
    {
        return Str::headline($column);
    }

    public static function value(mixed $v): string
    {
        if ($v === null) {
            return '—';
        }
        if (is_bool($v)) {
            return $v ? 'Yes' : 'No';
        }
        if (is_array($v)) {
            return Str::limit(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 300);
        }

        $s = (string) $v;

        return $s === '' ? '(blank)' : Str::limit($s, 300);
    }

    /**
     * The feed's verb for a component method, falling back to what its
     * changes did.
     *
     * @param  array<string,int>  $ops  op => count
     */
    public static function verb(?string $method, array $ops): string
    {
        if ($method && ($v = config('activity.verbs')[$method] ?? null)) {
            return $v;
        }

        $kinds = array_keys(array_filter($ops));
        if (count($kinds) === 1) {
            return ucfirst($kinds[0]);
        }

        if ($method && ! str_starts_with($method, '$') && ! str_starts_with($method, '__')) {
            return Str::limit(Str::headline($method), 40, '');
        }

        return 'Changed';
    }

    /**
     * "Created 12 raw material exits, updated 12 raw materials in store".
     *
     * @param  array<string,array<string,int>>  $counts  op => [subject_type => n]
     */
    public static function summary(array $counts): string
    {
        $parts = [];
        foreach (['created', 'updated', 'deleted'] as $op) {
            $bits = [];
            foreach ($counts[$op] ?? [] as $type => $n) {
                $bits[] = number_format($n) . ' ' . self::subjectPlural($type, $n);
            }
            if ($bits) {
                $parts[] = $op . ' ' . implode(', ', $bits);
            }
        }

        return Str::limit(ucfirst(implode('; ', $parts)) ?: 'No changes', 490);
    }
}
